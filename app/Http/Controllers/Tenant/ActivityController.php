<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ActivityAssignedNotification;
use App\Notifications\ActivityCommentedNotification;
use App\Notifications\ActivityFileUploadedNotification;
use App\Notifications\ActivityStatusChangedNotification;
use App\Support\ActivityPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    private const STATUSES = ['todo', 'in_progress', 'review', 'done'];

    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function index(Request $request, Tenant $tenant): Response
    {
        abort_unless(ActivityPermissions::canAny($request->user(), $tenant, ActivityPermissions::VIEW), 403);

        $contracts = $this->accessibleContracts($request, $tenant, ActivityPermissions::VIEW)
            ->with(['obra'])
            ->orderBy('code')
            ->get();

        $contractIds = $contracts->pluck('id');

        $activities = $tenant->activities()
            ->whereIn('contract_id', $contractIds)
            ->where(function (Builder $query): void {
                $query
                    ->where('status', '!=', 'done')
                    ->orWhereNull('completed_at')
                    ->orWhere('completed_at', '>', now()->subDays(5));
            })
            ->with([
                'contract:id,code,name,obra_id',
                'contract.obra:id,nome',
                'assignee:id,name,email,avatar_url',
                'assignees:id,name,email,avatar_url',
                'creator:id,name,email,avatar_url',
                'comments.user:id,name,email,avatar_url',
                'files.user:id,name,email,avatar_url',
            ])
            ->orderBy('position')
            ->latest()
            ->get();

        return Inertia::render('Tenant/Activities/Index', [
            'tenant' => $tenant,
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->obra?->nome ?? $contract->name,
                'status' => $contract->status,
            ])->values(),
            'activities' => $activities,
            'assigneesByContract' => $this->assignableUsersByContract($tenant, $contracts),
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
            'canCreateActivities' => $contracts->contains(fn (Contract $contract): bool => ActivityPermissions::can($request->user(), $tenant, ActivityPermissions::CREATE, $contract)),
            'canEditActivities' => ActivityPermissions::canAny($request->user(), $tenant, ActivityPermissions::EDIT),
            'canDeleteActivities' => ActivityPermissions::canAny($request->user(), $tenant, ActivityPermissions::DELETE),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless(ActivityPermissions::canAny($request->user(), $tenant, ActivityPermissions::CREATE), 403);

        $data = $request->validate([
            'contract_id' => [
                'required',
                Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'assigned_to_ids' => ['nullable', 'array'],
            'assigned_to_ids.*' => ['integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
        ]);

        $contract = $tenant->contracts()->findOrFail($data['contract_id']);

        abort_unless($this->canAccessContract($request->user(), $tenant, $contract), 403);
        abort_unless(ActivityPermissions::can($request->user(), $tenant, ActivityPermissions::CREATE, $contract), 403);

        $assignedUserIds = collect($data['assigned_to_ids'] ?? [])
            ->filter()
            ->map(fn ($userId): int => (int) $userId)
            ->unique()
            ->values();

        $invalidAssignee = $assignedUserIds->first(
            fn (int $userId): bool => ! $this->userCanReceiveActivity($userId, $tenant, $contract),
        );

        if ($invalidAssignee) {
            throw ValidationException::withMessages([
                'assigned_to_ids' => 'Selecione apenas usuários com acesso a este contrato.',
            ]);
        }

        $activity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'assigned_to_id' => $assignedUserIds->first(),
            'created_by_id' => $request->user()->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => 'todo',
            'priority' => $data['priority'],
            'due_date' => $data['due_date'] ?? null,
            'position' => $this->nextPosition($contract, 'todo'),
        ]);

        $activity->assignees()->sync($assignedUserIds);

        User::query()
            ->whereIn('id', $assignedUserIds)
            ->get()
            ->each(fn (User $user) => $user->notify(new ActivityAssignedNotification($activity, $request->user())));

        return back()->with('success', 'Atividade criada. Responsaveis notificados no sistema e por email.');
    }

    public function update(Request $request, Tenant $tenant, Activity $activity): RedirectResponse
    {
        abort_unless((int) $activity->tenant_id === (int) $tenant->id, 404);
        $contract = $activity->contract()->firstOrFail();

        abort_unless($this->canAccessContract($request->user(), $tenant, $contract), 403);
        abort_unless(ActivityPermissions::can($request->user(), $tenant, ActivityPermissions::EDIT, $contract), 403);

        if (! $request->has('title')) {
            return $this->updateStatus($request, $tenant, $contract, $activity);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
            'assigned_to_ids' => ['nullable', 'array'],
            'assigned_to_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $assignedUserIds = collect($data['assigned_to_ids'] ?? [])
            ->filter()
            ->map(fn ($userId): int => (int) $userId)
            ->unique()
            ->values();

        $invalidAssignee = $assignedUserIds->first(
            fn (int $userId): bool => ! $this->userCanReceiveActivity($userId, $tenant, $contract),
        );

        if ($invalidAssignee) {
            throw ValidationException::withMessages([
                'assigned_to_ids' => 'Selecione apenas usuarios com acesso a este contrato.',
            ]);
        }

        $activity->update([
            'assigned_to_id' => $assignedUserIds->first(),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'],
            'due_date' => $data['due_date'] ?? null,
        ]);

        $activity->assignees()->sync($assignedUserIds);

        return back()->with('success', 'Atividade atualizada.');
    }

    public function destroy(Request $request, Tenant $tenant, Activity $activity): RedirectResponse
    {
        abort_unless((int) $activity->tenant_id === (int) $tenant->id, 404);
        $contract = $activity->contract()->firstOrFail();

        abort_unless($this->canAccessContract($request->user(), $tenant, $contract), 403);
        abort_unless(ActivityPermissions::can($request->user(), $tenant, ActivityPermissions::DELETE, $contract), 403);

        $activity->delete();

        return back()->with('success', 'Atividade excluida. O registro foi mantido no historico.');
    }

    private function updateStatus(Request $request, Tenant $tenant, Contract $contract, Activity $activity): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);
        $oldStatus = $activity->status;
        $newStatus = $data['status'];

        $activity->update([
            'status' => $newStatus,
            'position' => $this->nextPosition($contract, $newStatus),
            'completed_at' => $this->completedAtForStatusChange($activity, $oldStatus, $newStatus),
        ]);

        if ($oldStatus !== $newStatus) {
            $this->notifyStatusChanged($activity->refresh(), $request->user(), $oldStatus, $newStatus);
        }

        return back()->with('success', 'Atividade atualizada.');
    }

    public function storeComment(Request $request, Tenant $tenant, Activity $activity): RedirectResponse
    {
        $this->authorizeActivityAccess($request->user(), $tenant, $activity);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $activity->comments()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $this->notifyActivityParticipants(
            $activity,
            new ActivityCommentedNotification($activity, $request->user()),
        );

        return back()->with('success', 'Comentário adicionado.');
    }

    public function storeFile(Request $request, Tenant $tenant, Activity $activity): RedirectResponse
    {
        $this->authorizeActivityAccess($request->user(), $tenant, $activity);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $data['file'];
        $path = $file->store("tenant-{$tenant->id}/activities/{$activity->id}", 'public');

        $activity->files()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $request->user()->id,
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        $this->notifyActivityParticipants(
            $activity,
            new ActivityFileUploadedNotification($activity, $request->user(), $file->getClientOriginalName()),
        );

        return back()->with('success', 'Arquivo anexado.');
    }

    private function accessibleContracts(Request $request, Tenant $tenant, ?string $permission = null)
    {
        $query = $tenant->contracts();
        $tenantRole = $request->user()->tenantRole($tenant);

        if (! in_array($tenantRole, ['tenant_owner', 'tenant_admin'], true)) {
            $query->whereHas('participants', function (Builder $query) use ($request): void {
                $query->where('user_id', $request->user()->id)->where('status', 'active');
            });
        }

        if ($permission) {
            $contractIds = ActivityPermissions::contractIdsFor($request->user(), $tenant, $permission);

            if ($contractIds !== null) {
                $query->whereIn('id', $contractIds);
            }
        }

        return $query;
    }

    private function canAccessContract(User $user, Tenant $tenant, Contract $contract): bool
    {
        if (in_array($user->tenantRole($tenant), ['tenant_owner', 'tenant_admin'], true)) {
            return true;
        }

        return $contract->participants()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    private function userCanReceiveActivity(int $userId, Tenant $tenant, Contract $contract): bool
    {
        $user = User::find($userId);

        if (! $user) {
            return false;
        }

        return $this->canAccessContract($user, $tenant, $contract);
    }

    private function authorizeActivityAccess(User $user, Tenant $tenant, Activity $activity): void
    {
        abort_unless((int) $activity->tenant_id === (int) $tenant->id, 404);

        $contract = $activity->contract()->firstOrFail();

        abort_unless($this->canAccessContract($user, $tenant, $contract), 403);
        abort_unless(ActivityPermissions::can($user, $tenant, ActivityPermissions::VIEW, $contract), 403);
    }

    /**
     * @param  Collection<int, Contract>  $contracts
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function assignableUsersByContract(Tenant $tenant, Collection $contracts): array
    {
        $globalUsers = $tenant->memberships()
            ->where('status', 'active')
            ->whereIn('role', ['tenant_owner', 'tenant_admin'])
            ->with('user:id,name,email,avatar_url')
            ->get()
            ->pluck('user')
            ->filter();

        return $contracts->mapWithKeys(function (Contract $contract) use ($globalUsers): array {
            $participants = $contract->participants()
                ->where('status', 'active')
                ->with('user:id,name,email,avatar_url')
                ->get()
                ->pluck('user')
                ->filter();

            $users = $globalUsers
                ->merge($participants)
                ->unique('id')
                ->sortBy('name')
                ->values()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                ])
                ->all();

            return [$contract->id => $users];
        })->all();
    }

    private function nextPosition(Contract $contract, string $status): int
    {
        return ((int) $contract->activities()->where('status', $status)->max('position')) + 1;
    }

    private function completedAtForStatusChange(Activity $activity, string $oldStatus, string $newStatus): mixed
    {
        if ($newStatus !== 'done') {
            return null;
        }

        if ($oldStatus === 'done') {
            return $activity->completed_at ?? now();
        }

        return now();
    }

    private function notifyStatusChanged(Activity $activity, User $actor, string $oldStatus, string $newStatus): void
    {
        $this->notifyActivityParticipants(
            $activity,
            new ActivityStatusChangedNotification($activity, $actor, $oldStatus, $newStatus),
        );
    }

    private function notifyActivityParticipants(Activity $activity, object $notification): void
    {
        $activity->loadMissing(['assignees', 'creator']);

        $activity->assignees
            ->when($activity->creator, fn ($users) => $users->push($activity->creator))
            ->unique('id')
            ->values()
            ->each(fn (User $user) => $user->notify($notification));
    }
}

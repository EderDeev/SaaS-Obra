<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityChecklistItem;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ActivityAssignedNotification;
use App\Notifications\ActivityCommentedNotification;
use App\Notifications\ActivityFileUploadedNotification;
use App\Notifications\ActivityStatusChangedNotification;
use App\Support\ActivityPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    private const STATUSES = ['todo', 'in_progress', 'review', 'done'];

    private const STATUS_LABELS = [
        'todo' => 'A fazer',
        'in_progress' => 'Em andamento',
        'review' => 'Em revisão',
        'done' => 'Concluídas',
    ];

    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    private const CATEGORIES = [
        'project' => 'Projeto',
        'quality' => 'Qualidade',
        'budget' => 'Orçamento',
        'measurement' => 'Medição',
        'documentation' => 'Documentação',
        'service_order' => 'Ordem de Serviço',
        'construction_diary' => 'Diário de Obra',
        'contract' => 'Contrato',
        'administrative' => 'Administrativo',
        'field' => 'Campo',
        'client' => 'Cliente',
    ];

    private const VISIBILITIES = [
        Activity::VISIBILITY_PUBLIC => 'Pública',
        Activity::VISIBILITY_RESTRICTED => 'Restrita',
    ];

    public function index(Request $request, Tenant $tenant): Response
    {
        abort_unless(ActivityPermissions::canAny($request->user(), $tenant, ActivityPermissions::VIEW), 403);

        $contracts = $this->accessibleContracts($request, $tenant, ActivityPermissions::VIEW)
            ->with(['obra'])
            ->orderBy('code')
            ->get();

        $contractIds = $contracts->pluck('id');
        $assignmentCounts = $this->activityAssignmentCounts($tenant, $contracts);

        $activities = $tenant->activities()
            ->whereIn('contract_id', $contractIds)
            ->visibleTo($request->user())
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
                'checklistItems.completedBy:id,name,email,avatar_url',
            ])
            ->orderBy('position')
            ->latest()
            ->get();

        $activities->each(function (Activity $activity) use ($request, $tenant): void {
            $contract = $activity->contract;

            $activity->setAttribute('can_edit', $this->canEditActivity($request->user(), $tenant, $contract, $activity));
            $activity->setAttribute('can_move', $this->canMoveActivity($request->user(), $tenant, $contract, $activity));
            $activity->setAttribute('can_interact', $this->canInteractWithActivity($request->user(), $tenant, $contract, $activity));
            $activity->setAttribute('can_delete', $this->canDeleteActivity($request->user(), $tenant, $contract, $activity));
        });

        return Inertia::render('Tenant/Activities/Index', [
            'tenant' => $tenant,
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->obra?->nome ?? $contract->name,
                'status' => $contract->status,
            ])->values(),
            'activities' => $activities,
            'assigneesByContract' => $this->assignableUsersByContract($tenant, $contracts, $assignmentCounts),
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
            'categories' => self::CATEGORIES,
            'visibilities' => self::VISIBILITIES,
            'canCreateActivities' => $contracts->contains(fn (Contract $contract): bool => ActivityPermissions::can($request->user(), $tenant, ActivityPermissions::CREATE, $contract)),
            'canEditActivities' => ActivityPermissions::canAny($request->user(), $tenant, ActivityPermissions::EDIT),
            'canDeleteActivities' => ActivityPermissions::canAny($request->user(), $tenant, ActivityPermissions::DELETE),
            'canViewActivityMetrics' => $contracts->contains(fn (Contract $contract): bool => ActivityPermissions::can($request->user(), $tenant, ActivityPermissions::VIEW_METRICS, $contract)),
        ]);
    }

    public function metrics(Request $request, Tenant $tenant): Response
    {
        abort_unless(ActivityPermissions::canAny($request->user(), $tenant, ActivityPermissions::VIEW_METRICS), 403);

        $filters = $request->validate([
            'period' => ['nullable', Rule::in(['30', '90', '180', '365', 'all'])],
            'contract_id' => ['nullable', 'integer'],
            'category' => ['nullable', Rule::in(array_keys(self::CATEGORIES))],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $period = $filters['period'] ?? '180';
        $contracts = $this->accessibleContracts($request, $tenant, ActivityPermissions::VIEW)
            ->with('obra:id,nome')
            ->orderBy('code')
            ->get()
            ->filter(fn (Contract $contract): bool => ActivityPermissions::can(
                $request->user(),
                $tenant,
                ActivityPermissions::VIEW_METRICS,
                $contract,
            ))
            ->values();

        abort_unless($contracts->isNotEmpty(), 403);
        $contractIds = $contracts->pluck('id')->map(fn ($id): int => (int) $id);
        $contractId = ! empty($filters['contract_id']) ? (int) $filters['contract_id'] : null;
        $assigneeId = ! empty($filters['assignee_id']) ? (int) $filters['assignee_id'] : null;

        if ($contractId !== null) {
            abort_unless($contractIds->contains($contractId), 403);
        }

        $activities = $tenant->activities()
            ->whereIn('contract_id', $contractIds)
            ->visibleTo($request->user())
            ->when($period !== 'all', fn (Builder $query): Builder => $query->where(
                'created_at',
                '>=',
                CarbonImmutable::now()->subDays((int) $period)->startOfDay(),
            ))
            ->when($contractId, fn (Builder $query): Builder => $query->where('contract_id', $contractId))
            ->when(
                $filters['category'] ?? null,
                fn (Builder $query, string $category): Builder => $query->where('category', $category),
            )
            ->when($assigneeId, function (Builder $query, int $userId): Builder {
                return $query->where(function (Builder $query) use ($userId): void {
                    $query
                        ->where('assigned_to_id', $userId)
                        ->orWhereHas('assignees', fn (Builder $query): Builder => $query->where('users.id', $userId));
                });
            })
            ->with([
                'contract:id,code,name,obra_id',
                'contract.obra:id,nome',
                'assignee:id,name,email,avatar_url',
                'assignees:id,name,email,avatar_url',
            ])
            ->get([
                'id',
                'tenant_id',
                'contract_id',
                'assigned_to_id',
                'created_by_id',
                'title',
                'category',
                'status',
                'priority',
                'due_date',
                'completed_at',
                'created_at',
            ]);

        $completed = $activities->filter(
            fn (Activity $activity): bool => $activity->status === 'done' && $activity->completed_at !== null,
        );
        $completedWithDeadline = $completed->filter(fn (Activity $activity): bool => $activity->due_date !== null);
        $completedOnTime = $completedWithDeadline->filter(fn (Activity $activity): bool => $this->wasCompletedOnTime($activity));
        $completedLate = $completedWithDeadline->reject(fn (Activity $activity): bool => $this->wasCompletedOnTime($activity));
        $open = $activities->where('status', '!=', 'done');
        $openOverdue = $open->filter(fn (Activity $activity): bool => $this->isOverdue($activity));
        $total = $activities->count();
        $averageResolutionDays = $completed->isEmpty()
            ? 0
            : round($completed->average(fn (Activity $activity): float => max(
                0,
                $activity->created_at->diffInMinutes($activity->completed_at) / 1440,
            )), 1);

        $assignees = collect($this->assignableUsersByContract($tenant, $contracts))
            ->flatten(1)
            ->unique('id')
            ->sortBy('name')
            ->values();

        return Inertia::render('Tenant/Activities/Metrics', [
            'tenant' => $tenant,
            'filters' => [
                'period' => $period,
                'contract_id' => $contractId,
                'category' => $filters['category'] ?? null,
                'assignee_id' => $assigneeId,
            ],
            'filterOptions' => [
                'contracts' => $contracts->map(fn (Contract $contract): array => [
                    'id' => $contract->id,
                    'code' => $contract->code,
                    'name' => $contract->obra?->nome ?? $contract->name,
                ])->values(),
                'categories' => self::CATEGORIES,
                'assignees' => $assignees,
            ],
            'summary' => [
                'total' => $total,
                'completed' => $completed->count(),
                'open' => $open->count(),
                'overdue_open' => $openOverdue->count(),
                'completion_rate' => $total > 0 ? round(($completed->count() / $total) * 100) : 0,
                'on_time_rate' => $completedWithDeadline->isNotEmpty()
                    ? round(($completedOnTime->count() / $completedWithDeadline->count()) * 100)
                    : 0,
                'average_resolution_days' => $averageResolutionDays,
            ],
            'charts' => [
                'statuses' => collect(self::STATUS_LABELS)
                    ->map(fn (string $label, string $key): array => [
                        'key' => $key,
                        'name' => $label,
                        'value' => $activities->where('status', $key)->count(),
                    ])
                    ->values(),
                'deadlines' => [
                    ['key' => 'on_time', 'name' => 'Concluídas no prazo', 'value' => $completedOnTime->count()],
                    ['key' => 'late', 'name' => 'Concluídas com atraso', 'value' => $completedLate->count()],
                    ['key' => 'overdue_open', 'name' => 'Abertas em atraso', 'value' => $openOverdue->count()],
                    ['key' => 'without_due_date', 'name' => 'Concluídas sem prazo', 'value' => $completed->whereNull('due_date')->count()],
                ],
                'categories' => $this->categoryMetrics($activities),
                'trend' => $this->activityTrend($activities),
            ],
            'responsibles' => $this->responsibleMetrics($activities),
            'resolvedActivities' => $completed
                ->sortByDesc('completed_at')
                ->take(12)
                ->map(fn (Activity $activity): array => $this->resolvedActivityData($activity))
                ->values(),
            'overdueActivities' => $openOverdue
                ->sortBy('due_date')
                ->take(10)
                ->map(fn (Activity $activity): array => $this->overdueActivityData($activity))
                ->values(),
        ]);
    }

    public function tourPreview(Request $request, Tenant $tenant): Response
    {
        abort_unless(ActivityPermissions::canAny($request->user(), $tenant, ActivityPermissions::VIEW), 403);

        $screen = $request->string('screen')->toString();
        $allowedScreens = ['create', 'board', 'detail', 'flow', 'metrics'];
        $screen = in_array($screen, $allowedScreens, true) ? $screen : 'create';

        if ($screen === 'metrics') {
            return Inertia::render('Tenant/Activities/Metrics', $this->activityTourMetricsProps($tenant));
        }

        return Inertia::render('Tenant/Activities/Index', $this->activityTourBoardProps($tenant, $screen));
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
            'activity_type' => ['sometimes', Rule::in(Activity::TYPES)],
            'checklist_items' => ['required_if:activity_type,'.Activity::TYPE_CHECKLIST, 'array', 'max:50'],
            'checklist_items.*' => ['required_if:activity_type,'.Activity::TYPE_CHECKLIST, 'nullable', 'string', 'max:500'],
            'category' => ['nullable', Rule::in(array_keys(self::CATEGORIES))],
            'visibility' => ['sometimes', Rule::in(array_keys(self::VISIBILITIES))],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
        ]);

        $contract = $tenant->contracts()->findOrFail($data['contract_id']);

        abort_unless($this->canAccessContract($request->user(), $tenant, $contract), 403);
        abort_unless(ActivityPermissions::can($request->user(), $tenant, ActivityPermissions::VIEW, $contract), 403);
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

        $activity = DB::transaction(function () use ($assignedUserIds, $contract, $data, $request, $tenant): Activity {
            $activity = $tenant->activities()->create([
                'contract_id' => $contract->id,
                'assigned_to_id' => $assignedUserIds->first(),
                'created_by_id' => $request->user()->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'activity_type' => $data['activity_type'] ?? Activity::TYPE_ACTIVITY,
                'category' => $data['category'] ?? 'project',
                'visibility' => $data['visibility'] ?? Activity::VISIBILITY_PUBLIC,
                'status' => 'todo',
                'priority' => $data['priority'],
                'due_date' => $data['due_date'] ?? null,
                'position' => $this->nextPosition($contract, 'todo'),
            ]);

            $activity->assignees()->sync($assignedUserIds);

            if ($activity->activity_type === Activity::TYPE_CHECKLIST) {
                $activity->checklistItems()->createMany(
                    collect($data['checklist_items'] ?? [])
                        ->map(fn (string $label, int $position): array => [
                            'label' => trim($label),
                            'position' => $position,
                        ])
                        ->all(),
                );
            }

            return $activity;
        });

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
        abort_unless(ActivityPermissions::can($request->user(), $tenant, ActivityPermissions::VIEW, $contract), 403);
        abort_unless($activity->isVisibleTo($request->user()), 403);

        if (! $request->has('title')) {
            abort_unless($this->canMoveActivity($request->user(), $tenant, $contract, $activity), 403);

            return $this->updateStatus($request, $tenant, $contract, $activity);
        }

        abort_unless($this->canEditActivity($request->user(), $tenant, $contract, $activity), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', Rule::in(array_keys(self::CATEGORIES))],
            'visibility' => ['sometimes', Rule::in(array_keys(self::VISIBILITIES))],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
            'assigned_to_ids' => ['nullable', 'array'],
            'assigned_to_ids.*' => ['integer', 'exists:users,id'],
            'new_checklist_items' => ['nullable', 'array', 'max:50'],
            'new_checklist_items.*' => ['required', 'string', 'max:500'],
        ]);

        $newChecklistItems = collect($data['new_checklist_items'] ?? [])
            ->map(fn (string $label): string => trim($label))
            ->values();

        if ($activity->activity_type !== Activity::TYPE_CHECKLIST && $newChecklistItems->isNotEmpty()) {
            throw ValidationException::withMessages([
                'new_checklist_items' => 'Novas etapas só podem ser adicionadas a uma atividade do tipo checklist.',
            ]);
        }

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

        DB::transaction(function () use ($activity, $assignedUserIds, $data, $newChecklistItems): void {
            Activity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();

            $activity->update([
                'assigned_to_id' => $assignedUserIds->first(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? $activity->category ?? 'project',
                'visibility' => $data['visibility'] ?? $activity->visibility,
                'priority' => $data['priority'],
                'due_date' => $data['due_date'] ?? null,
            ]);

            $activity->assignees()->sync($assignedUserIds);

            if ($newChecklistItems->isNotEmpty()) {
                $existingItems = $activity->checklistItems()->lockForUpdate()->get(['position']);

                if ($existingItems->count() + $newChecklistItems->count() > 50) {
                    throw ValidationException::withMessages([
                        'new_checklist_items' => 'O checklist pode ter no máximo 50 etapas.',
                    ]);
                }

                $nextPosition = $existingItems->isEmpty()
                    ? 0
                    : ((int) $existingItems->max('position')) + 1;

                $activity->checklistItems()->createMany(
                    $newChecklistItems
                        ->map(fn (string $label, int $index): array => [
                            'label' => $label,
                            'position' => $nextPosition + $index,
                        ])
                        ->all(),
                );
            }
        });

        $message = $newChecklistItems->isNotEmpty()
            ? 'Atividade atualizada e novas etapas adicionadas ao checklist.'
            : 'Atividade atualizada.';

        return back()->with('success', $message);
    }

    public function updateChecklistItem(
        Request $request,
        Tenant $tenant,
        Activity $activity,
        ActivityChecklistItem $checklistItem,
    ): RedirectResponse {
        abort_unless((int) $checklistItem->activity_id === (int) $activity->id, 404);
        abort_unless($activity->activity_type === Activity::TYPE_CHECKLIST, 404);
        $this->authorizeActivityAccess($request->user(), $tenant, $activity);

        $data = $request->validate([
            'is_completed' => ['required', 'boolean'],
        ]);
        $isCompleted = (bool) $data['is_completed'];

        $checklistItem->update([
            'is_completed' => $isCompleted,
            'completed_by_id' => $isCompleted ? $request->user()->id : null,
            'completed_at' => $isCompleted ? now() : null,
        ]);

        return back()->with('success', $isCompleted ? 'Etapa concluída.' : 'Etapa reaberta.');
    }

    public function destroy(Request $request, Tenant $tenant, Activity $activity): RedirectResponse
    {
        abort_unless((int) $activity->tenant_id === (int) $tenant->id, 404);
        $contract = $activity->contract()->firstOrFail();

        abort_unless($this->canAccessContract($request->user(), $tenant, $contract), 403);
        abort_unless(ActivityPermissions::can($request->user(), $tenant, ActivityPermissions::VIEW, $contract), 403);
        abort_unless($activity->isVisibleTo($request->user()), 403);
        abort_unless($this->canDeleteActivity($request->user(), $tenant, $contract, $activity), 403);

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
        abort_unless($activity->isVisibleTo($user), 403);
        abort_unless($this->canInteractWithActivity($user, $tenant, $contract, $activity), 403);
    }

    private function canEditActivity(User $user, Tenant $tenant, Contract $contract, Activity $activity): bool
    {
        return (int) $activity->created_by_id === (int) $user->id
            || ActivityPermissions::can($user, $tenant, ActivityPermissions::EDIT, $contract);
    }

    private function canDeleteActivity(User $user, Tenant $tenant, Contract $contract, Activity $activity): bool
    {
        return (int) $activity->created_by_id === (int) $user->id
            || ActivityPermissions::can($user, $tenant, ActivityPermissions::DELETE, $contract);
    }

    private function canMoveActivity(User $user, Tenant $tenant, Contract $contract, Activity $activity): bool
    {
        if ((int) $activity->created_by_id === (int) $user->id
            || (int) $activity->assigned_to_id === (int) $user->id
            || ActivityPermissions::can($user, $tenant, ActivityPermissions::EDIT, $contract)) {
            return true;
        }

        return $activity->assignees()->where('users.id', $user->id)->exists();
    }

    private function canInteractWithActivity(User $user, Tenant $tenant, Contract $contract, Activity $activity): bool
    {
        return $this->canMoveActivity($user, $tenant, $contract, $activity);
    }

    /**
     * @param  Collection<int, Contract>  $contracts
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function assignableUsersByContract(Tenant $tenant, Collection $contracts, ?Collection $assignmentCounts = null): array
    {
        $assignmentCounts ??= $this->activityAssignmentCounts($tenant, $contracts);

        $globalUsers = $tenant->memberships()
            ->where('status', 'active')
            ->whereIn('role', ['tenant_owner', 'tenant_admin'])
            ->with('user:id,name,email,avatar_url')
            ->get()
            ->pluck('user')
            ->filter();

        return $contracts->mapWithKeys(function (Contract $contract) use ($globalUsers, $assignmentCounts): array {
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
                    'activity_assignment_count' => (int) $assignmentCounts->get($contract->id.':'.$user->id, 0),
                ])
                ->sortByDesc('activity_assignment_count')
                ->values()
                ->all();

            return [$contract->id => $users];
        })->all();
    }

    /**
     * @param  Collection<int, Contract>  $contracts
     * @return Collection<string, int>
     */
    private function activityAssignmentCounts(Tenant $tenant, Collection $contracts): Collection
    {
        $contractIds = $contracts->pluck('id');

        if ($contractIds->isEmpty()) {
            return collect();
        }

        $pivotAssignments = DB::table('activities')
            ->join('activity_user', 'activity_user.activity_id', '=', 'activities.id')
            ->where('activities.tenant_id', $tenant->id)
            ->whereIn('activities.contract_id', $contractIds)
            ->whereNull('activities.deleted_at')
            ->select([
                'activities.contract_id',
                'activity_user.user_id',
                'activities.id as activity_id',
            ]);

        $legacyAssignments = DB::table('activities')
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $contractIds)
            ->whereNotNull('assigned_to_id')
            ->whereNull('deleted_at')
            ->select([
                'contract_id',
                'assigned_to_id as user_id',
                'id as activity_id',
            ]);

        return DB::query()
            ->fromSub($pivotAssignments->union($legacyAssignments), 'activity_assignments')
            ->select(['contract_id', 'user_id', DB::raw('COUNT(*) as assignment_count')])
            ->groupBy('contract_id', 'user_id')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $row->contract_id.':'.$row->user_id => (int) $row->assignment_count,
            ]);
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

    private function wasCompletedOnTime(Activity $activity): bool
    {
        return $activity->due_date !== null
            && $activity->completed_at !== null
            && $activity->completed_at->lte($activity->due_date->copy()->endOfDay());
    }

    private function isOverdue(Activity $activity): bool
    {
        return $activity->status !== 'done'
            && $activity->due_date !== null
            && $activity->due_date->lt(now()->startOfDay());
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @return Collection<int, array<string, mixed>>
     */
    private function categoryMetrics(Collection $activities): Collection
    {
        return collect(self::CATEGORIES)
            ->map(function (string $label, string $key) use ($activities): array {
                $categoryActivities = $activities->filter(
                    fn (Activity $activity): bool => ($activity->category ?: 'project') === $key,
                );

                return [
                    'key' => $key,
                    'label' => $label,
                    'total' => $categoryActivities->count(),
                    'completed' => $categoryActivities->where('status', 'done')->count(),
                ];
            })
            ->filter(fn (array $item): bool => $item['total'] > 0)
            ->sortByDesc('total')
            ->values();
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @return Collection<int, array<string, mixed>>
     */
    private function activityTrend(Collection $activities): Collection
    {
        $created = $activities
            ->groupBy(fn (Activity $activity): string => $activity->created_at->format('Y-m'))
            ->map->count();
        $completed = $activities
            ->filter(fn (Activity $activity): bool => $activity->completed_at !== null)
            ->groupBy(fn (Activity $activity): string => $activity->completed_at->format('Y-m'))
            ->map->count();

        return $created->keys()
            ->merge($completed->keys())
            ->unique()
            ->sort()
            ->map(fn (string $month): array => [
                'key' => $month,
                'label' => CarbonImmutable::createFromFormat('Y-m', $month)->format('m/Y'),
                'created' => (int) ($created[$month] ?? 0),
                'completed' => (int) ($completed[$month] ?? 0),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @return Collection<int, array<string, mixed>>
     */
    private function responsibleMetrics(Collection $activities): Collection
    {
        $metrics = [];

        foreach ($activities as $activity) {
            $responsibles = $activity->assignees->isNotEmpty()
                ? $activity->assignees
                : collect([$activity->assignee])->filter();

            if ($responsibles->isEmpty()) {
                $responsibles = collect([(object) [
                    'id' => null,
                    'name' => 'Sem responsável',
                    'email' => null,
                    'avatar_url' => null,
                ]]);
            }

            foreach ($responsibles->unique('id') as $responsible) {
                $key = $responsible->id ? (string) $responsible->id : 'unassigned';
                $metrics[$key] ??= [
                    'id' => $responsible->id,
                    'name' => $responsible->name,
                    'email' => $responsible->email,
                    'avatar_url' => $responsible->avatar_url,
                    'total' => 0,
                    'completed' => 0,
                    'open' => 0,
                    'overdue_open' => 0,
                    'on_time' => 0,
                    'late' => 0,
                ];

                $metrics[$key]['total']++;

                if ($activity->status === 'done') {
                    $metrics[$key]['completed']++;

                    if ($activity->due_date !== null) {
                        $metrics[$key][$this->wasCompletedOnTime($activity) ? 'on_time' : 'late']++;
                    }
                } else {
                    $metrics[$key]['open']++;

                    if ($this->isOverdue($activity)) {
                        $metrics[$key]['overdue_open']++;
                    }
                }
            }
        }

        return collect($metrics)
            ->map(function (array $item): array {
                $item['completion_rate'] = $item['total'] > 0
                    ? round(($item['completed'] / $item['total']) * 100)
                    : 0;
                $withDeadline = $item['on_time'] + $item['late'];
                $item['on_time_rate'] = $withDeadline > 0
                    ? round(($item['on_time'] / $withDeadline) * 100)
                    : null;

                return $item;
            })
            ->sortByDesc('total')
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedActivityData(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'title' => $activity->title,
            'contract' => $this->activityContractLabel($activity),
            'responsibles' => $this->activityResponsibleNames($activity),
            'due_date' => $activity->due_date?->toDateString(),
            'completed_at' => $activity->completed_at?->toIso8601String(),
            'result' => $activity->due_date === null
                ? 'without_due_date'
                : ($this->wasCompletedOnTime($activity) ? 'on_time' : 'late'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function overdueActivityData(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'title' => $activity->title,
            'contract' => $this->activityContractLabel($activity),
            'responsibles' => $this->activityResponsibleNames($activity),
            'due_date' => $activity->due_date?->toDateString(),
            'days_overdue' => $activity->due_date
                ? $activity->due_date->startOfDay()->diffInDays(now()->startOfDay())
                : 0,
        ];
    }

    private function activityContractLabel(Activity $activity): string
    {
        $name = $activity->contract?->obra?->nome ?? $activity->contract?->name;

        return trim(collect([$activity->contract?->code, $name])->filter()->implode(' · '));
    }

    /**
     * @return array<int, string>
     */
    private function activityResponsibleNames(Activity $activity): array
    {
        return ($activity->assignees->isNotEmpty()
            ? $activity->assignees
            : collect([$activity->assignee])->filter())
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function activityTourBoardProps(Tenant $tenant, string $screen): array
    {
        $contractId = -101;
        $responsibles = [
            [
                'id' => -201,
                'name' => 'Marina Costa',
                'email' => 'marina.costa@empresa.com',
                'avatar_url' => null,
                'activity_assignment_count' => 12,
            ],
            [
                'id' => -202,
                'name' => 'Carlos Mendes',
                'email' => 'carlos.mendes@empresa.com',
                'avatar_url' => null,
                'activity_assignment_count' => 8,
            ],
        ];
        $contract = [
            'id' => $contractId,
            'code' => 'CT-001',
            'name' => 'Jardim Central',
            'obra_id' => -301,
            'obra' => ['id' => -301, 'nome' => 'Jardim Central'],
        ];
        $activity = [
            'id' => -401,
            'tenant_id' => $tenant->id,
            'contract_id' => $contractId,
            'assigned_to_id' => $responsibles[0]['id'],
            'created_by_id' => -203,
            'title' => 'Checklist de fechamento da medição',
            'description' => 'Conferir os quantitativos executados, validar a memória de cálculo e registrar as pendências antes do fechamento da medição.',
            'activity_type' => Activity::TYPE_CHECKLIST,
            'category' => 'measurement',
            'visibility' => Activity::VISIBILITY_RESTRICTED,
            'status' => 'todo',
            'priority' => 'high',
            'due_date' => CarbonImmutable::now()->addDays(5)->toDateString(),
            'completed_at' => null,
            'position' => 1,
            'created_at' => CarbonImmutable::now()->subDays(3)->toIso8601String(),
            'contract' => $contract,
            'assignee' => $responsibles[0],
            'assignees' => $responsibles,
            'creator' => [
                'id' => -203,
                'name' => 'Ana Ribeiro',
                'email' => 'ana.ribeiro@empresa.com',
                'avatar_url' => null,
            ],
            'comments' => [
                [
                    'id' => -501,
                    'body' => 'Os quantitativos do pavimento térreo foram conferidos. Falta validar a memória da drenagem.',
                    'created_at' => CarbonImmutable::now()->subDay()->toIso8601String(),
                    'user' => $responsibles[0],
                ],
                [
                    'id' => -502,
                    'body' => 'Memória de cálculo atualizada e anexada para a revisão final.',
                    'created_at' => CarbonImmutable::now()->subHours(5)->toIso8601String(),
                    'user' => $responsibles[1],
                ],
            ],
            'files' => [
                [
                    'id' => -601,
                    'name' => 'memoria-medicao-julho.xlsx',
                    'url' => '#',
                    'size' => 184320,
                    'created_at' => CarbonImmutable::now()->subHours(5)->toIso8601String(),
                    'user' => $responsibles[1],
                ],
            ],
            'checklist_items' => [
                [
                    'id' => -701,
                    'label' => 'Conferir quantitativos executados',
                    'position' => 0,
                    'is_completed' => true,
                    'completed_by' => $responsibles[0],
                    'completed_at' => CarbonImmutable::now()->subHours(6)->toIso8601String(),
                ],
                [
                    'id' => -702,
                    'label' => 'Validar a memória de cálculo',
                    'position' => 1,
                    'is_completed' => true,
                    'completed_by' => $responsibles[1],
                    'completed_at' => CarbonImmutable::now()->subHours(3)->toIso8601String(),
                ],
                [
                    'id' => -703,
                    'label' => 'Registrar pendências da medição',
                    'position' => 2,
                    'is_completed' => false,
                    'completed_by' => null,
                    'completed_at' => null,
                ],
            ],
            '_tourData' => true,
        ];

        return [
            'tenant' => $tenant,
            'contracts' => [[
                'id' => $contractId,
                'code' => $contract['code'],
                'name' => $contract['name'],
                'status' => 'active',
            ]],
            'activities' => $screen === 'create' ? [] : [$activity],
            'assigneesByContract' => [(string) $contractId => $responsibles],
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
            'categories' => self::CATEGORIES,
            'visibilities' => self::VISIBILITIES,
            'canCreateActivities' => true,
            'canEditActivities' => true,
            'canDeleteActivities' => true,
            'tourMode' => true,
            'tourScreen' => $screen,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activityTourMetricsProps(Tenant $tenant): array
    {
        $responsible = [
            'id' => -201,
            'name' => 'Marina Costa',
            'email' => 'marina.costa@empresa.com',
            'avatar_url' => null,
        ];
        $contract = ['id' => -101, 'code' => 'CT-001', 'name' => 'Jardim Central'];

        return [
            'tenant' => $tenant,
            'filters' => [
                'period' => '180',
                'contract_id' => null,
                'category' => null,
                'assignee_id' => null,
            ],
            'filterOptions' => [
                'contracts' => [$contract],
                'categories' => self::CATEGORIES,
                'assignees' => [
                    $responsible,
                    [
                        'id' => -202,
                        'name' => 'Carlos Mendes',
                        'email' => 'carlos.mendes@empresa.com',
                        'avatar_url' => null,
                    ],
                ],
            ],
            'summary' => [
                'total' => 28,
                'completed' => 21,
                'open' => 7,
                'overdue_open' => 2,
                'completion_rate' => 75,
                'on_time_rate' => 86,
                'average_resolution_days' => 4.2,
            ],
            'charts' => [
                'statuses' => [
                    ['key' => 'todo', 'name' => 'A fazer', 'value' => 3],
                    ['key' => 'in_progress', 'name' => 'Em andamento', 'value' => 2],
                    ['key' => 'review', 'name' => 'Em revisão', 'value' => 2],
                    ['key' => 'done', 'name' => 'Concluídas', 'value' => 21],
                ],
                'deadlines' => [
                    ['key' => 'on_time', 'name' => 'Concluídas no prazo', 'value' => 18],
                    ['key' => 'late', 'name' => 'Concluídas com atraso', 'value' => 3],
                    ['key' => 'overdue_open', 'name' => 'Abertas em atraso', 'value' => 2],
                    ['key' => 'without_due_date', 'name' => 'Concluídas sem prazo', 'value' => 0],
                ],
                'categories' => [
                    ['key' => 'measurement', 'label' => 'Medição', 'total' => 9, 'completed' => 7],
                    ['key' => 'documentation', 'label' => 'Documentação', 'total' => 7, 'completed' => 6],
                    ['key' => 'project', 'label' => 'Projeto', 'total' => 6, 'completed' => 4],
                    ['key' => 'field', 'label' => 'Campo', 'total' => 4, 'completed' => 3],
                    ['key' => 'administrative', 'label' => 'Administrativo', 'total' => 2, 'completed' => 1],
                ],
                'trend' => [
                    ['key' => '2026-02', 'label' => '02/2026', 'created' => 3, 'completed' => 2],
                    ['key' => '2026-03', 'label' => '03/2026', 'created' => 5, 'completed' => 4],
                    ['key' => '2026-04', 'label' => '04/2026', 'created' => 4, 'completed' => 4],
                    ['key' => '2026-05', 'label' => '05/2026', 'created' => 6, 'completed' => 5],
                    ['key' => '2026-06', 'label' => '06/2026', 'created' => 5, 'completed' => 3],
                    ['key' => '2026-07', 'label' => '07/2026', 'created' => 5, 'completed' => 3],
                ],
            ],
            'responsibles' => [
                [
                    ...$responsible,
                    'total' => 12,
                    'completed' => 10,
                    'open' => 2,
                    'overdue_open' => 1,
                    'on_time' => 9,
                    'late' => 1,
                    'completion_rate' => 83,
                    'on_time_rate' => 90,
                ],
                [
                    'id' => -202,
                    'name' => 'Carlos Mendes',
                    'email' => 'carlos.mendes@empresa.com',
                    'avatar_url' => null,
                    'total' => 10,
                    'completed' => 8,
                    'open' => 2,
                    'overdue_open' => 0,
                    'on_time' => 7,
                    'late' => 1,
                    'completion_rate' => 80,
                    'on_time_rate' => 88,
                ],
            ],
            'resolvedActivities' => [
                [
                    'id' => -401,
                    'title' => 'Validar medição mensal da obra',
                    'contract' => 'CT-001 · Jardim Central',
                    'responsibles' => ['Marina Costa', 'Carlos Mendes'],
                    'due_date' => CarbonImmutable::now()->subDay()->toDateString(),
                    'completed_at' => CarbonImmutable::now()->subDays(2)->toIso8601String(),
                    'result' => 'on_time',
                ],
                [
                    'id' => -402,
                    'title' => 'Revisar relatório de documentação',
                    'contract' => 'CT-001 · Jardim Central',
                    'responsibles' => ['Carlos Mendes'],
                    'due_date' => CarbonImmutable::now()->subDays(6)->toDateString(),
                    'completed_at' => CarbonImmutable::now()->subDays(4)->toIso8601String(),
                    'result' => 'late',
                ],
            ],
            'overdueActivities' => [
                [
                    'id' => -403,
                    'title' => 'Consolidar pendências do diário de obra',
                    'contract' => 'CT-001 · Jardim Central',
                    'responsibles' => ['Marina Costa'],
                    'due_date' => CarbonImmutable::now()->subDays(3)->toDateString(),
                    'days_overdue' => 3,
                ],
            ],
            'tourSection' => 'metrics',
        ];
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

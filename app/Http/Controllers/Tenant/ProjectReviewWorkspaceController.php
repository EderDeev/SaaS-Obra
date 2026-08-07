<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ProjectDocumentVersion;
use App\Models\ProjectReviewChecklistItem;
use App\Models\ProjectReviewMarkup;
use App\Models\ProjectReviewMarkupReply;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ProjectReviewMarkupCreatedNotification;
use App\Support\ProjectPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectReviewWorkspaceController extends Controller
{
    public function storeMarkup(Request $request, Tenant $tenant, ProjectDocumentVersion $version): RedirectResponse
    {
        $version = $this->authorizedVersionForCommentManagement($request, $tenant, $version);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assigned_to_id' => ['nullable', 'integer'],
            'assigned_to_ids' => ['nullable', 'array', 'max:50'],
            'assigned_to_ids.*' => ['integer', 'distinct'],
            'priority' => ['required', Rule::in(['baixa', 'normal', 'alta', 'critica'])],
            'due_date' => ['nullable', 'date'],
            'viewer_state' => ['nullable', 'array'],
            'markup_payload' => ['nullable', 'array'],
        ]);

        $assigneeIds = $this->normalizedAssigneeIds($data);
        $this->ensureAssignableUsers($tenant, $version->document->contract_id, $assigneeIds);

        $markup = ProjectReviewMarkup::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $version->document->contract_id,
            'project_document_id' => $version->document->id,
            'project_document_version_id' => $version->id,
            'created_by_id' => $request->user()->id,
            'assigned_to_id' => $assigneeIds[0] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'markup_type' => 'pin',
            'markup_payload' => $data['markup_payload'] ?? ['source' => 'viewer_state'],
            'viewer_state' => $data['viewer_state'] ?? null,
            'priority' => $data['priority'],
            'status' => 'open',
            'due_date' => $data['due_date'] ?? null,
        ]);

        $this->syncMarkupAssignees($markup, $tenant, $assigneeIds);

        $notified = $this->notifyMarkupAssignees($markup, $request->user()) > 0;

        return back()->with('success', $notified
            ? 'Comentário visual registrado. Responsável notificado no sistema e por e-mail.'
            : 'Comentário visual registrado nesta versão do projeto.');
    }

    public function updateMarkup(Request $request, Tenant $tenant, ProjectReviewMarkup $markup): RedirectResponse
    {
        $this->authorizedMarkupForCommentManagement($request, $tenant, $markup);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'resolved'])],
            'assigned_to_id' => ['nullable', 'integer'],
            'assigned_to_ids' => ['nullable', 'array', 'max:50'],
            'assigned_to_ids.*' => ['integer', 'distinct'],
        ]);

        $hasAssigneePayload = array_key_exists('assigned_to_ids', $data) || array_key_exists('assigned_to_id', $data);
        $assigneeIds = $hasAssigneePayload ? $this->normalizedAssigneeIds($data) : [];

        if ($hasAssigneePayload) {
            $this->ensureAssignableUsers($tenant, $markup->contract_id, $assigneeIds);
        }

        $updates = [];

        if ($hasAssigneePayload) {
            $updates['assigned_to_id'] = $assigneeIds[0] ?? null;
        }

        if (array_key_exists('status', $data)) {
            $updates['status'] = $data['status'];

            if ($data['status'] === 'resolved') {
                $updates['closed_by_id'] = $request->user()->id;
                $updates['closed_at'] = now();
            } else {
                $updates['closed_by_id'] = null;
                $updates['closed_at'] = null;
            }
        }

        $previousAssigneeIds = $markup->assignees()
            ->pluck('users.id')
            ->push($markup->assigned_to_id)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $markup->update($updates);

        if ($hasAssigneePayload) {
            $this->syncMarkupAssignees($markup, $tenant, $assigneeIds);
            $newAssigneeIds = array_values(array_diff($assigneeIds, $previousAssigneeIds));
            $this->notifyMarkupAssignees($markup->refresh(), $request->user(), $newAssigneeIds);
        }

        return back()->with('success', 'Comentário visual atualizado.');
    }

    public function storeMarkupReply(Request $request, Tenant $tenant, ProjectReviewMarkup $markup): RedirectResponse
    {
        $markup = $this->authorizedMarkupForComment($request, $tenant, $markup);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'resolve' => ['sometimes', 'boolean'],
        ]);

        $resolve = (bool) ($data['resolve'] ?? false);

        if ($resolve) {
            $canResolve = ProjectPermissions::can(
                $request->user(),
                $tenant,
                ProjectPermissions::REVIEW,
                $markup->version->document->contract
            ) || $this->markupHasAssignee($markup, $request->user()->id);

            abort_unless($canResolve, 403);
        }

        DB::transaction(function () use ($request, $tenant, $markup, $data, $resolve): void {
            ProjectReviewMarkupReply::create([
                'tenant_id' => $tenant->id,
                'project_review_markup_id' => $markup->id,
                'created_by_id' => $request->user()->id,
                'body' => trim($data['body']),
                'resolves_markup' => $resolve,
            ]);

            if ($resolve) {
                $markup->forceFill([
                    'status' => 'resolved',
                    'closed_by_id' => $request->user()->id,
                    'closed_at' => now(),
                ])->save();
            }
        });

        return back()->with('success', $resolve
            ? 'Resposta registrada e comentário resolvido.'
            : 'Resposta registrada no comentário.');
    }

    public function destroyMarkup(Request $request, Tenant $tenant, ProjectReviewMarkup $markup): RedirectResponse
    {
        $this->authorizedMarkupForCommentManagement($request, $tenant, $markup);

        $markup->delete();

        return back()->with('success', 'Comentário visual removido.');
    }

    public function updateChecklistItem(Request $request, Tenant $tenant, ProjectReviewChecklistItem $item): RedirectResponse
    {
        $item->load('checklist.version.document.contract');
        abort_unless((int) $item->tenant_id === (int) $tenant->id, 404);
        abort_unless($item->checklist?->version?->document, 404);
        abort_unless(ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::REVIEW, $item->checklist->version->document->contract), 403);

        $data = $request->validate([
            'checked' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $item->forceFill([
            'checked' => $data['checked'],
            'checked_by_id' => $data['checked'] ? $request->user()->id : null,
            'checked_at' => $data['checked'] ? now() : null,
            'notes' => $data['notes'] ?? $item->notes,
        ])->save();

        return back();
    }

    private function authorizedVersionForCommentManagement(Request $request, Tenant $tenant, ProjectDocumentVersion $version): ProjectDocumentVersion
    {
        abort_unless((int) $version->tenant_id === (int) $tenant->id, 404);

        $version->load([
            'document.contract:id,tenant_id,code,name,obra_id',
        ]);

        abort_unless($version->document, 404);
        abort_unless(ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::COMMENTS, $version->document->contract), 403);

        return $version;
    }

    private function authorizedMarkupForCommentManagement(Request $request, Tenant $tenant, ProjectReviewMarkup $markup): ProjectReviewMarkup
    {
        abort_unless((int) $markup->tenant_id === (int) $tenant->id, 404);

        $markup->load('version.document.contract');
        abort_unless($markup->version?->document, 404);
        abort_unless(ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::COMMENTS, $markup->version->document->contract), 403);

        return $markup;
    }

    private function authorizedMarkupForComment(Request $request, Tenant $tenant, ProjectReviewMarkup $markup): ProjectReviewMarkup
    {
        abort_unless((int) $markup->tenant_id === (int) $tenant->id, 404);

        $markup->load('version.document.contract');
        abort_unless($markup->version?->document, 404);

        $contract = $markup->version->document->contract;
        $canView = ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::VIEW, $contract);
        $canReview = ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::REVIEW, $contract);

        abort_unless($canView || $canReview, 403);

        return $markup;
    }

    /**
     * @return array<int, int>
     */
    private function normalizedAssigneeIds(array $data): array
    {
        $ids = $data['assigned_to_ids'] ?? [];

        if ($ids === [] && ! empty($data['assigned_to_id'])) {
            $ids = [$data['assigned_to_id']];
        }

        return collect($ids)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function ensureAssignableUsers(Tenant $tenant, int $contractId, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $allowedIds = $this->contractUserIds($tenant, $contractId);

        if (array_diff($userIds, $allowedIds) !== []) {
            throw ValidationException::withMessages([
                'assigned_to_ids' => 'Selecione apenas usuários vinculados a este contrato.',
            ]);
        }
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function syncMarkupAssignees(ProjectReviewMarkup $markup, Tenant $tenant, array $userIds): void
    {
        $syncData = collect($userIds)
            ->mapWithKeys(fn (int $userId): array => [$userId => ['tenant_id' => $tenant->id]])
            ->all();

        $markup->assignees()->sync($syncData);
    }

    private function markupHasAssignee(ProjectReviewMarkup $markup, int $userId): bool
    {
        return (int) $markup->assigned_to_id === $userId
            || $markup->assignees()->where('users.id', $userId)->exists();
    }

    /**
     * @return array<int, int>
     */
    private function contractUserIds(Tenant $tenant, int $contractId): array
    {
        return User::query()
            ->where('is_platform_admin', false)
            ->where(function (Builder $query) use ($tenant, $contractId): void {
                $query
                    ->whereHas('tenantMemberships', function (Builder $query) use ($tenant): void {
                        $query->where('tenant_id', $tenant->id)->where('status', 'active');
                    })
                    ->orWhereHas('contractParticipations', function (Builder $query) use ($tenant, $contractId): void {
                        $query->where('tenant_id', $tenant->id)
                            ->where('contract_id', $contractId)
                            ->where('status', 'active');
                    });
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>|null  $userIds
     */
    private function notifyMarkupAssignees(ProjectReviewMarkup $markup, User $actor, ?array $userIds = null): int
    {
        $markup->loadMissing(['tenant', 'contract', 'document', 'version', 'assignees', 'assignee']);

        $assignees = $markup->assignees;

        if ($assignees->isEmpty() && $markup->assignee) {
            $assignees = collect([$markup->assignee]);
        }

        if ($userIds !== null) {
            $assignees = $assignees->whereIn('id', $userIds);
        }

        $assignees->unique('id')->each(
            fn (User $user) => $user->notify(new ProjectReviewMarkupCreatedNotification($markup, $actor))
        );

        return $assignees->unique('id')->count();
    }
}

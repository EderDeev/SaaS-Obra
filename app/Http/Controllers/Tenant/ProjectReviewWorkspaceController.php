<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ProjectDocumentVersion;
use App\Models\ProjectReviewChecklistItem;
use App\Models\ProjectReviewMarkup;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ProjectReviewMarkupCreatedNotification;
use App\Support\ProjectPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectReviewWorkspaceController extends Controller
{
    public function storeMarkup(Request $request, Tenant $tenant, ProjectDocumentVersion $version): RedirectResponse
    {
        $version = $this->authorizedVersionForReview($request, $tenant, $version);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assigned_to_id' => ['nullable', 'integer'],
            'priority' => ['required', Rule::in(['baixa', 'normal', 'alta', 'critica'])],
            'due_date' => ['nullable', 'date'],
            'viewer_state' => ['nullable', 'array'],
            'markup_payload' => ['nullable', 'array'],
        ]);

        $this->ensureAssignableUser($tenant, $version->document->contract_id, $data['assigned_to_id'] ?? null);

        $markup = ProjectReviewMarkup::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $version->document->contract_id,
            'project_document_id' => $version->document->id,
            'project_document_version_id' => $version->id,
            'created_by_id' => $request->user()->id,
            'assigned_to_id' => $data['assigned_to_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'markup_type' => 'pin',
            'markup_payload' => $data['markup_payload'] ?? ['source' => 'viewer_state'],
            'viewer_state' => $data['viewer_state'] ?? null,
            'priority' => $data['priority'],
            'status' => 'open',
            'due_date' => $data['due_date'] ?? null,
        ]);

        $notified = $this->notifyMarkupAssignee($markup, $request->user());

        return back()->with('success', $notified
            ? 'Comentário visual registrado. Responsável notificado no sistema e por e-mail.'
            : 'Comentário visual registrado nesta versão do projeto.');
    }

    public function updateMarkup(Request $request, Tenant $tenant, ProjectReviewMarkup $markup): RedirectResponse
    {
        $this->authorizedMarkupForReview($request, $tenant, $markup);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'resolved'])],
            'assigned_to_id' => ['nullable', 'integer'],
        ]);

        $this->ensureAssignableUser($tenant, $markup->contract_id, $data['assigned_to_id'] ?? null);

        $updates = [];

        if (array_key_exists('assigned_to_id', $data)) {
            $updates['assigned_to_id'] = $data['assigned_to_id'];
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

        $previousAssigneeId = $markup->assigned_to_id;

        $markup->update($updates);

        $assigneeChanged = array_key_exists('assigned_to_id', $updates)
            && $markup->assigned_to_id
            && (int) $markup->assigned_to_id !== (int) $previousAssigneeId;

        if ($assigneeChanged) {
            $markup->refresh();
            $this->notifyMarkupAssignee($markup, $request->user());
        }

        return back()->with('success', 'Comentário visual atualizado.');
    }

    public function destroyMarkup(Request $request, Tenant $tenant, ProjectReviewMarkup $markup): RedirectResponse
    {
        $this->authorizedMarkupForReview($request, $tenant, $markup);

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

    private function authorizedVersionForReview(Request $request, Tenant $tenant, ProjectDocumentVersion $version): ProjectDocumentVersion
    {
        abort_unless((int) $version->tenant_id === (int) $tenant->id, 404);

        $version->load([
            'document.contract:id,tenant_id,code,name,obra_id',
        ]);

        abort_unless($version->document, 404);
        abort_unless(ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::REVIEW, $version->document->contract), 403);

        return $version;
    }

    private function authorizedMarkupForReview(Request $request, Tenant $tenant, ProjectReviewMarkup $markup): ProjectReviewMarkup
    {
        abort_unless((int) $markup->tenant_id === (int) $tenant->id, 404);

        $markup->load('version.document.contract');
        abort_unless($markup->version?->document, 404);
        abort_unless(ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::REVIEW, $markup->version->document->contract), 403);

        return $markup;
    }

    private function ensureAssignableUser(Tenant $tenant, int $contractId, mixed $userId): void
    {
        if (! $userId) {
            return;
        }

        $allowedIds = $this->contractUserIds($tenant, $contractId);

        if (! in_array((int) $userId, $allowedIds, true)) {
            throw ValidationException::withMessages([
                'assigned_to_id' => 'Selecione um usuário vinculado a este contrato.',
            ]);
        }
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

    private function notifyMarkupAssignee(ProjectReviewMarkup $markup, User $actor): bool
    {
        if (! $markup->assigned_to_id) {
            return false;
        }

        $markup->loadMissing(['tenant', 'contract', 'document', 'version', 'assignee']);

        if (! $markup->assignee) {
            return false;
        }

        $markup->assignee->notify(new ProjectReviewMarkupCreatedNotification($markup, $actor));

        return true;
    }
}

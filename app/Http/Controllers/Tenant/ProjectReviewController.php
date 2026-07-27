<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\RemoveRejectedProjectVersionFromApsJob;
use App\Models\Contract;
use App\Models\ProjectDisciplineResponsavel;
use App\Models\ProjectDocument;
use App\Models\ProjectDocumentVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ProjectApprovedNotification;
use App\Notifications\ProjectRejectedNotification;
use App\Notifications\ProjectVerifiedForApprovalNotification;
use App\Support\ProjectCap;
use App\Support\ProjectPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectReviewController extends Controller
{
    private const STATUS_LABELS = [
        'em_analise' => 'Em analise',
        'em_aprovacao' => 'Em aprovacao',
        'ativo' => 'Aprovado',
        'reprovado' => 'Reprovado',
    ];

    public function index(Request $request, Tenant $tenant): Response
    {
        abort_unless(ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::REVIEW), 403);

        $contracts = $this->accessibleContracts($request, $tenant, ProjectPermissions::REVIEW)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'status']);
        $contractIds = $contracts->pluck('id');

        $documents = $tenant->projectDocuments()
            ->whereIn('contract_id', $contractIds)
            ->withExists(['versions as has_approved_version' => fn (Builder $query): Builder => $query->where('status', 'ativo')])
            ->when($this->mustRespectDisciplineResponsibles($request, $tenant), function (Builder $query) use ($request, $tenant): void {
                $query->where(function (Builder $query) use ($request, $tenant): void {
                    $query
                        ->where(function (Builder $query) use ($request, $tenant): void {
                            $query->where('status', 'em_analise')
                                ->whereHas('disciplina.projectResponsaveis', function (Builder $query) use ($request, $tenant): void {
                                    $this->responsavelScope($query, $request, $tenant, 'analise');
                                });
                        })
                        ->orWhere(function (Builder $query) use ($request, $tenant): void {
                            $query->where('status', 'em_aprovacao')
                                ->whereHas('disciplina.projectResponsaveis', function (Builder $query) use ($request, $tenant): void {
                                    $this->responsavelScope($query, $request, $tenant, 'aprovacao');
                                });
                        })
                        ->orWhere(function (Builder $query) use ($request, $tenant): void {
                            $query->whereIn('status', ['ativo', 'reprovado'])
                                ->whereHas('disciplina.projectResponsaveis', function (Builder $query) use ($request, $tenant): void {
                                    $this->responsavelScope($query, $request, $tenant);
                                });
                        });
                });
            })
            ->with([
                'contract:id,code,name,obra_id',
                'obra:id,nome,codigo',
                'disciplina:id,nome,sigla,cor',
                'phase:id,name,code',
                'creator:id,name,email',
                'reviewer:id,name,email',
                'approver:id,name,email',
                'latestVersion.uploader:id,name,email',
                'latestVersion.reviewer:id,name,email',
                'latestVersion.approver:id,name,email',
                'latestVersion.capRequester:id,name,email',
            ])
            ->latestSubmissionFirst()
            ->get();

        return Inertia::render('Tenant/Projects/Review', [
            'tenant' => $tenant,
            'contracts' => $contracts,
            'documents' => $documents,
            'statusLabels' => self::STATUS_LABELS,
            'capImpactLabels' => ProjectCap::IMPACT_LABELS,
            'stats' => [
                'pending' => $documents->where('status', 'em_analise')->count(),
                'approval' => $documents->where('status', 'em_aprovacao')->count(),
                'approved' => $documents->where('status', 'ativo')->count(),
                'rejected' => $documents->where('status', 'reprovado')->count(),
            ],
        ]);
    }

    public function update(Request $request, Tenant $tenant, ProjectDocument $document): RedirectResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);

        $contract = $document->contract()->firstOrFail();

        abort_unless($this->canAccessContract($request, $tenant, $contract), 403);
        abort_unless(ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::REVIEW, $contract), 403);
        abort_unless(in_array($document->status, ['em_analise', 'em_aprovacao'], true), 403);

        $responsavelTipo = $document->status === 'em_analise' ? 'analise' : 'aprovacao';

        abort_unless($this->canReviewDocumentDiscipline($request, $tenant, $document, $responsavelTipo), 403);

        $data = $request->validate([
            'action' => ['required', Rule::in(['aprovar', 'reprovar'])],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'action.required' => 'Escolha aprovar ou reprovar o projeto.',
        ]);

        $approved = $data['action'] === 'aprovar';
        $reviewNotes = trim((string) ($data['review_notes'] ?? ''));

        if (! $approved && $reviewNotes === '') {
            return back()->withErrors([
                'review_notes' => 'Informe o motivo da reprovação do projeto.',
            ]);
        }

        $rejectionStage = $document->status === 'em_analise' ? 'analysis' : 'approval';
        $latestVersion = $document->latestVersion()->first();

        if ($document->status === 'em_analise') {
            $updates = [
                'status' => $approved ? 'em_aprovacao' : 'reprovado',
                'reviewed_by_id' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes ?: null,
            ];

            $document->forceFill($updates)->save();
            $latestVersion?->forceFill($updates)->save();

            if ($approved) {
                $notifiedCount = $this->notifyApprovalResponsibles($document->fresh(['tenant', 'contract', 'obra', 'disciplina', 'phase', 'latestVersion']), $request->user());

                return back()->with('success', $notifiedCount > 0
                    ? "Projeto verificado e enviado para aprovacao. {$notifiedCount} aprovador(es) notificado(s) no sistema e por email."
                    : 'Projeto verificado e enviado para aprovacao. Nenhum aprovador cadastrado para esta disciplina.');
            }

            $notified = $this->notifySubmitterOfRejection(
                $document->fresh(['tenant', 'contract', 'obra', 'disciplina', 'phase', 'creator', 'latestVersion.uploader']),
                $request->user(),
                $reviewNotes,
                $rejectionStage,
            );
            $this->scheduleRejectedVersionCleanup($latestVersion);

            return back()->with('success', $notified
                ? 'Projeto reprovado na análise. O usuário foi notificado, o arquivo original foi preservado e a cópia da APS será removida.'
                : 'Projeto reprovado na análise. O arquivo original foi preservado e a cópia da APS será removida, mas não foi possível localizar o usuário que o submeteu.');
        }

        $updates = [
            'status' => $approved ? 'ativo' : 'reprovado',
            'approved_by_id' => $request->user()->id,
            'approved_at' => now(),
            'approval_notes' => $reviewNotes ?: null,
        ];

        $document->forceFill($updates)->save();
        $latestVersion?->forceFill($updates)->save();

        if ($approved) {
            $notifiedCount = $this->notifyContractUsersOfApproval($document->fresh(['tenant', 'contract', 'obra', 'disciplina', 'phase', 'latestVersion']), $request->user());

            return back()->with('success', $notifiedCount > 0
                ? "Projeto aprovado e liberado para a arvore principal. {$notifiedCount} usuario(s) do contrato notificado(s) no sistema e por email."
                : 'Projeto aprovado e liberado para a arvore principal. Nenhum usuario ativo vinculado ao contrato para notificar.');
        }

        $notified = $this->notifySubmitterOfRejection(
            $document->fresh(['tenant', 'contract', 'obra', 'disciplina', 'phase', 'creator', 'latestVersion.uploader']),
            $request->user(),
            $reviewNotes,
            $rejectionStage,
        );
        $this->scheduleRejectedVersionCleanup($latestVersion);

        return back()->with('success', $notified
            ? 'Projeto reprovado na aprovação final. O usuário foi notificado, o arquivo original foi preservado e a cópia da APS será removida.'
            : 'Projeto reprovado na aprovação final. O arquivo original foi preservado e a cópia da APS será removida, mas não foi possível localizar o usuário que o submeteu.');
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
            $contractIds = ProjectPermissions::contractIdsFor($request->user(), $tenant, $permission);

            if ($contractIds !== null) {
                $query->whereIn('id', $contractIds);
            }
        }

        return $query;
    }

    private function canAccessContract(Request $request, Tenant $tenant, Contract $contract): bool
    {
        if (in_array($request->user()->tenantRole($tenant), ['tenant_owner', 'tenant_admin'], true)) {
            return true;
        }

        return $contract->participants()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->exists();
    }

    private function mustRespectDisciplineResponsibles(Request $request, Tenant $tenant): bool
    {
        return ! in_array($request->user()->tenantRole($tenant), ['tenant_owner', 'tenant_admin'], true);
    }

    private function canReviewDocumentDiscipline(Request $request, Tenant $tenant, ProjectDocument $document, string $tipo): bool
    {
        if (! $this->mustRespectDisciplineResponsibles($request, $tenant)) {
            return true;
        }

        if (! $document->disciplina_id) {
            return false;
        }

        return ProjectDisciplineResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $document->contract_id)
            ->where('disciplina_id', $document->disciplina_id)
            ->where('user_id', $request->user()->id)
            ->where('tipo', $tipo)
            ->where('status', 'active')
            ->exists();
    }

    private function responsavelScope(Builder $query, Request $request, Tenant $tenant, ?string $tipo = null): void
    {
        $query->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->when($tipo, fn (Builder $query) => $query->where('tipo', $tipo), fn (Builder $query) => $query->whereIn('tipo', ['analise', 'aprovacao']))
            ->where('status', 'active')
            ->whereColumn('project_discipline_responsaveis.contract_id', 'project_documents.contract_id');
    }

    private function notifyApprovalResponsibles(ProjectDocument $document, User $actor): int
    {
        if (! $document->disciplina_id) {
            return 0;
        }

        $approvers = ProjectDisciplineResponsavel::query()
            ->where('tenant_id', $document->tenant_id)
            ->where('contract_id', $document->contract_id)
            ->where('disciplina_id', $document->disciplina_id)
            ->where('tipo', 'aprovacao')
            ->where('status', 'active')
            ->with('user:id,name,email')
            ->get()
            ->pluck('user')
            ->filter()
            ->unique('id')
            ->values();

        $notification = new ProjectVerifiedForApprovalNotification($document, $actor);

        $approvers->each(fn (User $user) => $user->notify($notification));

        return $approvers->count();
    }

    private function notifyContractUsersOfApproval(ProjectDocument $document, User $actor): int
    {
        $users = User::query()
            ->where('is_platform_admin', false)
            ->whereHas('contractParticipations', function (Builder $query) use ($document): void {
                $query->where('tenant_id', $document->tenant_id)
                    ->where('contract_id', $document->contract_id)
                    ->where('status', 'active');
            })
            ->orderBy('id')
            ->get(['id', 'name', 'email'])
            ->unique(fn (User $user): string => mb_strtolower($user->email))
            ->values();

        $notification = new ProjectApprovedNotification($document, $actor);

        $users->each(fn (User $user) => $user->notify($notification));

        return $users->count();
    }

    private function notifySubmitterOfRejection(ProjectDocument $document, User $actor, string $reason, string $stage): bool
    {
        $submitter = $document->latestVersion?->uploader ?: $document->creator;

        if (! $submitter) {
            return false;
        }

        $submitter->notify(new ProjectRejectedNotification($document, $actor, $reason, $stage));

        return true;
    }

    private function scheduleRejectedVersionCleanup(?ProjectDocumentVersion $version): void
    {
        if (! $version) {
            return;
        }

        $version->forceFill(['derivative_status' => 'removing'])->save();

        RemoveRejectedProjectVersionFromApsJob::dispatch($version->id)->afterResponse();
    }
}

<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\BoletimMedicao;
use App\Models\ContractAdditive;
use App\Models\GedDocument;
use App\Models\GedEmailProcessedMessage;
use App\Models\OrdemServico;
use App\Models\RdoDiario;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ActivityPermissions;
use App\Support\ContractPermissions;
use App\Support\DiarioObraPermissions;
use App\Support\DocumentationPermissions;
use App\Support\MedicaoPermissions;
use App\Support\OrdemServicoPermissions;
use App\Support\ProjectPermissions;
use App\Support\RncPermissions;
use App\Support\TenantRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant): Response
    {
        $user = $request->user();
        $tenantRole = $user->tenantRole($tenant);
        $contracts = $this->accessibleContracts($user, $tenant);
        $contractIds = (clone $contracts)->pluck('id');
        $activityContractIds = $this->permittedContractIds($user, $tenant, $contractIds, ActivityPermissions::class, ActivityPermissions::VIEW);
        $projectContractIds = $this->permittedContractIds($user, $tenant, $contractIds, ProjectPermissions::class, ProjectPermissions::VIEW);
        $rncContractIds = $this->permittedContractIds($user, $tenant, $contractIds, RncPermissions::class, RncPermissions::VIEW);
        $documentationContractIds = $this->permittedContractIds($user, $tenant, $contractIds, DocumentationPermissions::class, DocumentationPermissions::VIEW);
        $documentationEmailContractIds = $this->permittedContractIds($user, $tenant, $contractIds, DocumentationPermissions::class, DocumentationPermissions::EMAIL);
        $rdoContractIds = $this->permittedContractIds($user, $tenant, $contractIds, DiarioObraPermissions::class, DiarioObraPermissions::VIEW);
        $medicaoContractIds = $this->permittedContractIds($user, $tenant, $contractIds, MedicaoPermissions::class, MedicaoPermissions::VIEW);
        $ordemServicoContractIds = $this->permittedContractIds($user, $tenant, $contractIds, OrdemServicoPermissions::class, OrdemServicoPermissions::VIEW);
        $additiveContractIds = $this->permittedContractIds($user, $tenant, $contractIds, ContractPermissions::class, ContractPermissions::VIEW);

        $activities = $tenant->activities()
            ->whereIn('contract_id', $activityContractIds)
            ->visibleTo($user)
            ->with('contract:id,code,name')
            ->latest()
            ->get(['id', 'tenant_id', 'contract_id', 'created_by_id', 'assigned_to_id', 'title', 'category', 'visibility', 'status', 'priority', 'due_date', 'created_at']);
        $projects = $tenant->projectDocuments()
            ->whereIn('contract_id', $projectContractIds)
            ->with(['contract:id,code,name', 'disciplina:id,nome,sigla'])
            ->latest()
            ->get(['id', 'tenant_id', 'contract_id', 'disciplina_id', 'title', 'code', 'status', 'created_at']);
        $rncs = $tenant->relatorioNaoConformidades()
            ->whereIn('contract_id', $rncContractIds)
            ->with(['contract:id,code,name', 'disciplina:id,nome,sigla'])
            ->latest()
            ->get(['id', 'tenant_id', 'contract_id', 'disciplina_id', 'sequence_number', 'sequence_year', 'opened_at', 'prazo_resposta_acao_corretiva', 'status', 'created_at']);

        $openActivities = $activities->where('status', '!=', 'done');
        $openRncs = $rncs->where('status', 'aberta');
        $pendingProjects = $projects->whereIn('status', ['em_analise', 'em_aprovacao']);
        $today = today();
        $activitiesDueSoon = $openActivities->filter(
            fn ($activity): bool => $activity->due_date !== null
                && ! $activity->due_date->isBefore($today)
                && $activity->due_date->lte($today->copy()->addDays(7))
        );

        $documents = DocumentationPermissions::scopeReadableDocuments(GedDocument::query(), $user, $tenant)
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $documentationContractIds)
            ->with('contract:id,code,name')
            ->latest()
            ->get(['id', 'tenant_id', 'contract_id', 'title', 'status', 'created_at']);
        $rdos = RdoDiario::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $rdoContractIds)
            ->with('contract:id,code,name')
            ->latest('reference_date')
            ->get(['id', 'tenant_id', 'contract_id', 'code', 'reference_date', 'status', 'created_at']);
        $boletins = BoletimMedicao::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $medicaoContractIds)
            ->with('contract:id,code,name')
            ->latest('periodo')
            ->get(['id', 'tenant_id', 'contract_id', 'codigo', 'periodo', 'status', 'created_at']);
        $ordens = OrdemServico::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $ordemServicoContractIds)
            ->with('contract:id,code,name')
            ->latest()
            ->get(['id', 'tenant_id', 'contract_id', 'codigo', 'titulo', 'status', 'prazo_inicio', 'prazo_finalizacao', 'created_at']);
        $additives = ContractAdditive::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $additiveContractIds)
            ->with('contract:id,code,name')
            ->latest()
            ->limit(5)
            ->get(['id', 'tenant_id', 'contract_id', 'sequence_number', 'type', 'title', 'created_at']);

        $documentsInProgress = $documents->whereIn('status', ['uploaded', 'processing']);
        $pendingTriage = GedEmailProcessedMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending_triage')
            ->whereHas('rule', fn (Builder $query): Builder => $query->whereIn('contract_id', $documentationEmailContractIds))
            ->with('rule.contract:id,code,name')
            ->latest('processed_at')
            ->get(['id', 'tenant_id', 'rule_id', 'subject', 'processed_at', 'created_at']);
        $rdoAwaitingReview = $rdos->whereIn('status', ['em_aprovacao', 'devolvido_construtora']);
        $openBoletins = $boletins->where('status', 'aberto_lancamento');
        $pendingOrders = $ordens->whereIn('status', ['em_analise', 'em_aprovacao']);

        $myActivities = $tenant->activities()
            ->whereIn('contract_id', $activityContractIds)
            ->visibleTo($user)
            ->where('status', '!=', 'done')
            ->where(function (Builder $query) use ($user): void {
                $query
                    ->where('assigned_to_id', $user->id)
                    ->orWhereHas('assignees', fn (Builder $query): Builder => $query->where('users.id', $user->id));
            })
            ->with('contract:id,code,name')
            ->select([
                'activities.id',
                'activities.tenant_id',
                'activities.contract_id',
                'activities.created_by_id',
                'activities.assigned_to_id',
                'activities.title',
                'activities.activity_type',
                'activities.category',
                'activities.visibility',
                'activities.status',
                'activities.priority',
                'activities.due_date',
            ])
            ->withCount('checklistItems')
            ->withCount([
                'checklistItems as completed_checklist_items_count' => fn (Builder $query): Builder => $query->where('is_completed', true),
            ])
            ->orderByRaw('case when due_date is null then 1 else 0 end')
            ->orderBy('due_date')
            ->limit(6)
            ->get();

        return Inertia::render('Tenant/Dashboard', [
            'tenant' => $tenant,
            'role' => TenantRoles::label($tenantRole),
            'stats' => [
                'contracts' => $contractIds->count(),
                'activeContracts' => (clone $contracts)->where('status', 'active')->count(),
                'openActivities' => $openActivities->count(),
                'overdueActivities' => $openActivities->filter(fn ($activity): bool => $activity->due_date !== null && $activity->due_date->isBefore($today))->count(),
                'activitiesDueToday' => $openActivities->filter(fn ($activity): bool => $activity->due_date?->isSameDay($today) ?? false)->count(),
                'activitiesDueSoon' => $activitiesDueSoon->count(),
                'openRncs' => $openRncs->count(),
                'overdueRncs' => $openRncs->filter(fn ($rnc): bool => $rnc->prazo_resposta_acao_corretiva !== null && $rnc->prazo_resposta_acao_corretiva->isBefore($today))->count(),
                'pendingProjects' => $pendingProjects->count(),
                'documents' => $documents->count(),
                'documentsInProgress' => $documentsInProgress->count(),
                'pendingTriage' => $pendingTriage->count(),
                'rdoAwaitingReview' => $rdoAwaitingReview->count(),
                'openBoletins' => $openBoletins->count(),
                'pendingOrders' => $pendingOrders->count(),
                'additives' => $additives->count(),
                'users' => $this->canSeeTenantTotals($user, $tenantRole) ? $tenant->memberships()->where('status', 'active')->count() : null,
            ],
            'charts' => [
                'activitiesByStatus' => $this->statusChart($activities->countBy('status'), [
                    'todo' => 'A fazer',
                    'in_progress' => 'Em andamento',
                    'review' => 'Em revisao',
                    'done' => 'Concluidas',
                ]),
                'activitiesByCategory' => $this->statusChart($activities->countBy(fn ($activity): string => $activity->category ?: 'project'), [
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
                ]),
                'projectsByStatus' => $this->statusChart($projects->countBy('status'), [
                    'em_analise' => 'Em analise',
                    'em_aprovacao' => 'Em aprovacao',
                    'ativo' => 'Aprovados',
                    'reprovado' => 'Reprovados',
                    'inativo' => 'Inativos',
                ]),
                'rncsByStatus' => $this->statusChart($rncs->countBy('status'), [
                    'aberta' => 'Abertas',
                    'finalizada' => 'Finalizadas',
                ]),
            ],
            'myActivities' => $myActivities,
            'attentionItems' => $this->attentionItems($tenant, $openActivities, $pendingProjects, $openRncs, $pendingTriage, $documentsInProgress, $rdoAwaitingReview, $openBoletins, $pendingOrders),
            'recentEvents' => $this->recentEvents($tenant, $activities, $projects, $rncs, $documents, $rdos, $boletins, $ordens, $additives),
            'capabilities' => [
                'activities' => ActivityPermissions::canAny($user, $tenant, ActivityPermissions::VIEW),
                'createActivity' => ActivityPermissions::canAny($user, $tenant, ActivityPermissions::CREATE),
                'projects' => ProjectPermissions::canAny($user, $tenant, ProjectPermissions::VIEW),
                'uploadProject' => ProjectPermissions::canAny($user, $tenant, ProjectPermissions::UPLOAD),
                'rncs' => RncPermissions::canAny($user, $tenant, RncPermissions::VIEW),
                'createRnc' => RncPermissions::canAny($user, $tenant, RncPermissions::CREATE),
                'documentation' => DocumentationPermissions::canAny($user, $tenant, DocumentationPermissions::VIEW),
                'documentationEmail' => DocumentationPermissions::canAny($user, $tenant, DocumentationPermissions::EMAIL),
                'rdo' => DiarioObraPermissions::canAny($user, $tenant, DiarioObraPermissions::VIEW),
                'measurements' => MedicaoPermissions::canAny($user, $tenant, MedicaoPermissions::VIEW),
                'serviceOrders' => OrdemServicoPermissions::canAny($user, $tenant, OrdemServicoPermissions::VIEW),
                'additives' => ContractPermissions::canAny($user, $tenant, ContractPermissions::VIEW),
            ],
        ]);
    }

    private function accessibleContracts(User $user, Tenant $tenant): HasMany
    {
        $query = $tenant->contracts();

        if (! $this->canSeeTenantTotals($user, $user->tenantRole($tenant))) {
            $query->whereHas('participants', function (Builder $query) use ($user): void {
                $query->where('user_id', $user->id)->where('status', 'active');
            });
        }

        return $query;
    }

    private function canSeeTenantTotals(User $user, ?string $tenantRole): bool
    {
        return $user->is_platform_admin || in_array($tenantRole, ['tenant_owner', 'tenant_admin'], true);
    }

    /**
     * @param  class-string  $permissionsClass
     */
    private function permittedContractIds(User $user, Tenant $tenant, Collection $accessibleContractIds, string $permissionsClass, string $permission): Collection
    {
        if ($user->is_platform_admin) {
            return $accessibleContractIds;
        }

        $permittedContractIds = $permissionsClass::contractIdsFor($user, $tenant, $permission);

        return $permittedContractIds === null
            ? $accessibleContractIds
            : $accessibleContractIds->intersect($permittedContractIds)->values();
    }

    private function statusChart(Collection $counts, array $labels): array
    {
        return collect($labels)
            ->map(fn (string $label, string $key): array => [
                'key' => $key,
                'label' => $label,
                'value' => (int) ($counts[$key] ?? 0),
            ])
            ->values()
            ->all();
    }

    private function attentionItems(Tenant $tenant, Collection $activities, Collection $projects, Collection $rncs, Collection $pendingTriage, Collection $documentsInProgress, Collection $rdos, Collection $boletins, Collection $ordens): array
    {
        $today = today();

        return collect()
            ->merge($activities
                ->filter(fn ($activity): bool => $activity->due_date !== null && $activity->due_date->lte($today->copy()->addDays(7)))
                ->map(function ($activity) use ($tenant, $today): array {
                    $days = $today->diffInDays($activity->due_date, false);
                    $isOverdue = $days < 0;

                    return [
                        'type' => 'Atividade',
                        'title' => $activity->title,
                        'subtitle' => $activity->contract?->code.' - prazo em '.$activity->due_date->format('d/m/Y'),
                        'badge' => $isOverdue
                            ? 'Vencida ha '.abs($days).' dia(s)'
                            : ($days === 0 ? 'Vence hoje' : 'Vence em '.$days.' dia(s)'),
                        'group' => $isOverdue ? 'critical' : 'due',
                        'tone' => $isOverdue ? 'red' : 'amber',
                        'priority' => $isOverdue ? 0 : 10 + $days,
                        'url' => route('tenant.activities.index', $tenant, false),
                    ];
                }))
            ->merge($projects->take(4)->map(fn ($project): array => [
                'type' => 'Projeto',
                'title' => $project->title,
                'subtitle' => $project->contract?->code.' - '.($project->status === 'em_aprovacao' ? 'aguardando aprovacao' : 'aguardando analise'),
                'badge' => $project->status === 'em_aprovacao' ? 'Em aprovacao' : 'Em analise',
                'group' => 'workflow',
                'tone' => 'amber',
                'priority' => 20,
                'url' => route('tenant.projects.review.index', $tenant, false),
            ]))
            ->merge($rncs->take(4)->map(function ($rnc) use ($tenant, $today): array {
                $isOverdue = $rnc->prazo_resposta_acao_corretiva?->isBefore($today) ?? false;

                return [
                    'type' => 'RNC',
                    'title' => 'RNC '.$rnc->formatted_number,
                    'subtitle' => $rnc->contract?->code.' - '.($rnc->prazo_resposta_acao_corretiva?->format('d/m/Y') ?: 'sem prazo de resposta'),
                    'badge' => $isOverdue ? 'Resposta atrasada' : 'Acao pendente',
                    'group' => $isOverdue ? 'critical' : 'workflow',
                    'tone' => $isOverdue ? 'red' : 'amber',
                    'priority' => $isOverdue ? 1 : 21,
                    'url' => route('tenant.qualidade.rnc.show', [$tenant, $rnc], false),
                ];
            }))
            ->merge($pendingTriage->take(3)->map(fn ($message): array => [
                'type' => 'Triagem de e-mail',
                'title' => $message->subject ?: 'E-mail sem assunto',
                'subtitle' => $message->rule?->contract?->code.' - escolha o PDF principal',
                'badge' => 'Triagem pendente',
                'group' => 'workflow',
                'tone' => 'amber',
                'priority' => 22,
                'url' => route('tenant.ged.triage', $tenant, false),
            ]))
            ->merge($documentsInProgress->take(3)->map(fn ($document): array => [
                'type' => 'Documentação',
                'title' => $document->title,
                'subtitle' => $document->contract?->code.' - processamento de OCR',
                'badge' => 'Processando OCR',
                'group' => 'workflow',
                'tone' => 'blue',
                'priority' => 30,
                'url' => route('tenant.ged.index', $tenant, false),
            ]))
            ->merge($rdos->take(3)->map(fn ($rdo): array => [
                'type' => 'RDO',
                'title' => $rdo->code,
                'subtitle' => $rdo->contract?->code.' - aguardando fluxo',
                'badge' => $rdo->status === 'devolvido_construtora' ? 'Devolvido' : 'Aguardando fluxo',
                'group' => $rdo->status === 'devolvido_construtora' ? 'critical' : 'workflow',
                'tone' => $rdo->status === 'devolvido_construtora' ? 'red' : 'amber',
                'priority' => $rdo->status === 'devolvido_construtora' ? 2 : 23,
                'url' => route('tenant.diario-obra.rdo.dashboard', $tenant, false),
            ]))
            ->merge($boletins->take(3)->map(fn ($boletim): array => [
                'type' => 'Medição',
                'title' => $boletim->codigo,
                'subtitle' => $boletim->contract?->code.' - lançamento aberto',
                'badge' => 'Lancamento aberto',
                'group' => 'workflow',
                'tone' => 'blue',
                'priority' => 31,
                'url' => route('tenant.medicao.boletim-medicao.index', $tenant, false),
            ]))
            ->merge($ordens->take(3)->map(fn ($ordem): array => [
                'type' => 'Ordem de serviço',
                'title' => $ordem->codigo.' - '.$ordem->titulo,
                'subtitle' => $ordem->contract?->code.' - aguardando '.str_replace('_', ' ', $ordem->status),
                'badge' => 'Aguardando decisao',
                'group' => 'workflow',
                'tone' => 'amber',
                'priority' => 24,
                'url' => route('tenant.ordem-servico.analise.index', $tenant, false),
            ]))
            ->sortBy('priority')
            ->take(8)
            ->map(function (array $item): array {
                unset($item['priority']);

                return $item;
            })
            ->values()
            ->all();
    }

    private function recentEvents(Tenant $tenant, Collection $activities, Collection $projects, Collection $rncs, Collection $documents, Collection $rdos, Collection $boletins, Collection $ordens, Collection $additives): array
    {
        return collect()
            ->merge($activities->take(5)->map(fn ($activity): array => [
                'type' => 'Atividade',
                'title' => $activity->title,
                'subtitle' => 'Atividade registrada em '.$activity->contract?->code,
                'created_at' => $activity->created_at,
                'url' => route('tenant.activities.index', $tenant, false),
            ]))
            ->merge($projects->take(5)->map(fn ($project): array => [
                'type' => 'Projeto',
                'title' => $project->title,
                'subtitle' => 'Projeto submetido em '.$project->contract?->code,
                'created_at' => $project->created_at,
                'url' => route('tenant.projects.index', $tenant, false),
            ]))
            ->merge($rncs->take(5)->map(fn ($rnc): array => [
                'type' => 'RNC',
                'title' => 'RNC '.$rnc->formatted_number,
                'subtitle' => 'Nao conformidade registrada em '.$rnc->contract?->code,
                'created_at' => $rnc->created_at,
                'url' => route('tenant.qualidade.rnc.show', [$tenant, $rnc], false),
            ]))
            ->merge($documents->take(5)->map(fn ($document): array => [
                'type' => 'Documento',
                'title' => $document->title,
                'subtitle' => 'Documento recebido em '.$document->contract?->code,
                'created_at' => $document->created_at,
                'url' => route('tenant.ged.index', $tenant, false),
            ]))
            ->merge($rdos->take(5)->map(fn ($rdo): array => [
                'type' => 'RDO',
                'title' => $rdo->code,
                'subtitle' => 'RDO registrado em '.$rdo->contract?->code,
                'created_at' => $rdo->created_at,
                'url' => route('tenant.diario-obra.rdo.dashboard', $tenant, false),
            ]))
            ->merge($boletins->take(5)->map(fn ($boletim): array => [
                'type' => 'Medição',
                'title' => $boletim->codigo,
                'subtitle' => 'Boletim criado em '.$boletim->contract?->code,
                'created_at' => $boletim->created_at,
                'url' => route('tenant.medicao.boletim-medicao.index', $tenant, false),
            ]))
            ->merge($ordens->take(5)->map(fn ($ordem): array => [
                'type' => 'Ordem de serviço',
                'title' => $ordem->codigo.' - '.$ordem->titulo,
                'subtitle' => 'OS registrada em '.$ordem->contract?->code,
                'created_at' => $ordem->created_at,
                'url' => route('tenant.ordem-servico.os.index', $tenant, false),
            ]))
            ->merge($additives->map(fn ($additive): array => [
                'type' => 'Aditivo',
                'title' => 'Aditivo '.$additive->sequence_number.' - '.$additive->title,
                'subtitle' => 'Contrato '.$additive->contract?->code,
                'created_at' => $additive->created_at,
                'url' => route('tenant.contracts.show', [$tenant, $additive->contract_id], false),
            ]))
            ->sortByDesc('created_at')
            ->take(8)
            ->values()
            ->all();
    }
}

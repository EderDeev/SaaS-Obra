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

        $activities = $tenant->activities()
            ->whereIn('contract_id', $activityContractIds)
            ->with('contract:id,code,name')
            ->latest()
            ->get(['id', 'tenant_id', 'contract_id', 'title', 'category', 'status', 'priority', 'due_date', 'created_at']);
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

        $documents = GedDocument::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $contractIds)
            ->with('contract:id,code,name')
            ->latest()
            ->get(['id', 'tenant_id', 'contract_id', 'title', 'status', 'created_at']);
        $rdos = RdoDiario::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $contractIds)
            ->with('contract:id,code,name')
            ->latest('reference_date')
            ->get(['id', 'tenant_id', 'contract_id', 'code', 'reference_date', 'status', 'created_at']);
        $boletins = BoletimMedicao::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $contractIds)
            ->with('contract:id,code,name')
            ->latest('periodo')
            ->get(['id', 'tenant_id', 'contract_id', 'codigo', 'periodo', 'status', 'created_at']);
        $ordens = OrdemServico::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $contractIds)
            ->with('contract:id,code,name')
            ->latest()
            ->get(['id', 'tenant_id', 'contract_id', 'codigo', 'titulo', 'status', 'prazo_execucao', 'created_at']);
        $additives = ContractAdditive::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $contractIds)
            ->with('contract:id,code,name')
            ->latest()
            ->limit(5)
            ->get(['id', 'tenant_id', 'contract_id', 'sequence_number', 'type', 'title', 'created_at']);

        $documentsInProgress = $documents->whereIn('status', ['uploaded', 'processing']);
        $pendingTriage = GedEmailProcessedMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending_triage')
            ->whereHas('rule', fn (Builder $query): Builder => $query->whereIn('contract_id', $contractIds))
            ->with('rule.contract:id,code,name')
            ->latest('processed_at')
            ->get(['id', 'tenant_id', 'rule_id', 'subject', 'processed_at', 'created_at']);
        $rdoAwaitingReview = $rdos->whereIn('status', ['em_aprovacao', 'devolvido_construtora']);
        $openBoletins = $boletins->where('status', 'aberto_lancamento');
        $pendingOrders = $ordens->whereIn('status', ['em_analise', 'em_aprovacao']);

        $myActivities = $tenant->activities()
            ->whereIn('contract_id', $activityContractIds)
            ->where('status', '!=', 'done')
            ->where(function (Builder $query) use ($user): void {
                $query
                    ->where('assigned_to_id', $user->id)
                    ->orWhereHas('assignees', fn (Builder $query): Builder => $query->where('users.id', $user->id));
            })
            ->with('contract:id,code,name')
            ->orderByRaw('case when due_date is null then 1 else 0 end')
            ->orderBy('due_date')
            ->limit(6)
            ->get(['id', 'tenant_id', 'contract_id', 'title', 'category', 'status', 'priority', 'due_date']);

        return Inertia::render('Tenant/Dashboard', [
            'tenant' => $tenant,
            'role' => TenantRoles::label($tenantRole),
            'stats' => [
                'contracts' => $contractIds->count(),
                'activeContracts' => (clone $contracts)->where('status', 'active')->count(),
                'openActivities' => $openActivities->count(),
                'overdueActivities' => $openActivities->filter(fn ($activity): bool => $activity->due_date !== null && $activity->due_date->isBefore($today))->count(),
                'activitiesDueToday' => $openActivities->filter(fn ($activity): bool => $activity->due_date?->isSameDay($today) ?? false)->count(),
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
            'attentionItems' => $this->attentionItems($tenant, $myActivities, $pendingProjects, $openRncs, $pendingTriage, $documentsInProgress, $rdoAwaitingReview, $openBoletins, $pendingOrders),
            'recentEvents' => $this->recentEvents($tenant, $activities, $projects, $rncs, $documents, $rdos, $boletins, $ordens, $additives),
            'recentContracts' => $contracts
                ->with(['obra:id,nome', 'clienteEmpresa:id,nome', 'construtoraEmpresa:id,nome', 'gerenciadoraEmpresa:id,nome'])
                ->withCount([
                    'activities as open_activities_count' => fn (Builder $query): Builder => $query->where('status', '!=', 'done'),
                    'relatorioNaoConformidades as open_rncs_count' => fn (Builder $query): Builder => $query->where('status', 'aberta'),
                    'projectDocuments as pending_projects_count' => fn (Builder $query): Builder => $query->whereIn('status', ['em_analise', 'em_aprovacao']),
                ])
                ->latest()
                ->limit(5)
                ->get(),
            'capabilities' => [
                'activities' => ActivityPermissions::canAny($user, $tenant, ActivityPermissions::VIEW),
                'createActivity' => ActivityPermissions::canAny($user, $tenant, ActivityPermissions::CREATE),
                'projects' => ProjectPermissions::canAny($user, $tenant, ProjectPermissions::VIEW),
                'uploadProject' => ProjectPermissions::canAny($user, $tenant, ProjectPermissions::UPLOAD),
                'rncs' => RncPermissions::canAny($user, $tenant, RncPermissions::VIEW),
                'createRnc' => RncPermissions::canAny($user, $tenant, RncPermissions::CREATE),
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
        return collect()
            ->merge($activities->map(fn ($activity): array => [
                'type' => 'Atividade',
                'title' => $activity->title,
                'subtitle' => $activity->contract?->code.' - '.($activity->due_date?->format('d/m/Y') ?: 'sem prazo'),
                'tone' => $activity->due_date?->isPast() ? 'red' : 'blue',
                'url' => route('tenant.activities.index', $tenant, false),
            ]))
            ->merge($projects->take(4)->map(fn ($project): array => [
                'type' => 'Projeto',
                'title' => $project->title,
                'subtitle' => $project->contract?->code.' - '.($project->status === 'em_aprovacao' ? 'aguardando aprovacao' : 'aguardando analise'),
                'tone' => 'amber',
                'url' => route('tenant.projects.review.index', $tenant, false),
            ]))
            ->merge($rncs->take(4)->map(fn ($rnc): array => [
                'type' => 'RNC',
                'title' => 'RNC '.$rnc->formatted_number,
                'subtitle' => $rnc->contract?->code.' - '.($rnc->prazo_resposta_acao_corretiva?->format('d/m/Y') ?: 'sem prazo de resposta'),
                'tone' => $rnc->prazo_resposta_acao_corretiva?->isPast() ? 'red' : 'amber',
                'url' => route('tenant.qualidade.rnc.show', [$tenant, $rnc], false),
            ]))
            ->merge($pendingTriage->take(3)->map(fn ($message): array => [
                'type' => 'Triagem de e-mail',
                'title' => $message->subject ?: 'E-mail sem assunto',
                'subtitle' => $message->rule?->contract?->code.' - escolha o PDF principal',
                'tone' => 'amber',
                'url' => route('tenant.ged.triage', $tenant, false),
            ]))
            ->merge($documentsInProgress->take(3)->map(fn ($document): array => [
                'type' => 'Documentação',
                'title' => $document->title,
                'subtitle' => $document->contract?->code.' - processamento de OCR',
                'tone' => 'blue',
                'url' => route('tenant.ged.index', $tenant, false),
            ]))
            ->merge($rdos->take(3)->map(fn ($rdo): array => [
                'type' => 'RDO',
                'title' => $rdo->code,
                'subtitle' => $rdo->contract?->code.' - aguardando fluxo',
                'tone' => $rdo->status === 'devolvido_construtora' ? 'red' : 'amber',
                'url' => route('tenant.diario-obra.rdo.dashboard', $tenant, false),
            ]))
            ->merge($boletins->take(3)->map(fn ($boletim): array => [
                'type' => 'Medição',
                'title' => $boletim->codigo,
                'subtitle' => $boletim->contract?->code.' - lançamento aberto',
                'tone' => 'blue',
                'url' => route('tenant.medicao.boletim-medicao.index', $tenant, false),
            ]))
            ->merge($ordens->take(3)->map(fn ($ordem): array => [
                'type' => 'Ordem de serviço',
                'title' => $ordem->codigo.' - '.$ordem->titulo,
                'subtitle' => $ordem->contract?->code.' - aguardando '.str_replace('_', ' ', $ordem->status),
                'tone' => 'amber',
                'url' => route('tenant.ordem-servico.analise.index', $tenant, false),
            ]))
            ->take(10)
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

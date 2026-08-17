<?php

namespace App\Services\Assistant;

use App\Models\Activity;
use App\Models\BoletimMedicao;
use App\Models\Contract;
use App\Models\FolhaRosto;
use App\Models\GedDocument;
use App\Models\Orcamento;
use App\Models\OrdemServico;
use App\Models\ProjectDocument;
use App\Models\RdaApontamento;
use App\Models\RdoDiario;
use App\Models\RelatorioNaoConformidade;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ActivityPermissions;
use App\Support\BudgetPermissions;
use App\Support\ContractPermissions;
use App\Support\DiarioObraPermissions;
use App\Support\DocumentationPermissions;
use App\Support\MedicaoPermissions;
use App\Support\OrdemServicoPermissions;
use App\Support\ParametrizacaoPermissions;
use App\Support\ProjectPermissions;
use App\Support\RncPermissions;
use App\Support\TenantRoles;
use App\Support\TutorialCatalog;
use App\Support\UserPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AssistantContextBuilder
{
    public function __construct(private readonly AssistantAccessScope $access) {}

    /**
     * @param  Collection<int, int>  $contractIds
     * @return Collection<int, array{module:string,title:string,url:string,excerpt:string,content:string,_priority:int}>
     */
    public function sources(
        User $user,
        Tenant $tenant,
        Collection $contractIds,
        string $question,
        ?string $currentPath = null
    ): Collection {
        $capabilities = $this->capabilities($user, $tenant);

        return collect([
            $this->accessSource($user, $tenant, $contractIds, $capabilities),
            $this->operationalSource($user, $tenant, $contractIds, $capabilities),
            $this->attentionSource($user, $tenant, $contractIds, $capabilities),
            $this->moduleCatalogSource($tenant, $capabilities),
        ])->merge($this->workflowSources($tenant, $question, $currentPath, $capabilities));
    }

    private function accessSource(User $user, Tenant $tenant, Collection $contractIds, array $capabilities): array
    {
        $tenantIds = $this->access->tenantIds($user);
        $tenants = Tenant::query()
            ->whereIn('id', $tenantIds)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
        $contracts = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $contractIds)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
        $contractsAcrossTenants = $tenants->sum(
            fn (Tenant $accessibleTenant): int => $this->access->contractIds($user, $accessibleTenant)->count()
        );
        $moduleLines = collect($capabilities)
            ->filter(fn (array $module): bool => $module['view'])
            ->map(function (array $module): string {
                $permissions = $module['permissions'] !== []
                    ? ' Permissões: '.implode(', ', $module['permissions']).'.'
                    : '';

                return "- {$module['label']}.{$permissions}";
            })
            ->implode("\n");
        $scopedModuleLines = collect([
            'Atividades' => $this->access->activityContractIds($user, $tenant),
            'Projetos' => $this->access->projectContractIds($user, $tenant),
            'Qualidade / RNC' => $this->access->rncContractIds($user, $tenant),
        ])->map(function (Collection $ids, string $label) use ($contracts): string {
            $codes = $contracts->whereIn('id', $ids)->pluck('code')->values();

            return "- {$label}: ".($codes->isEmpty() ? 'nenhum contrato' : $codes->implode(', '));
        })->implode("\n");

        $content = implode("\n", [
            "Usuário autenticado: {$user->name}.",
            "Tenant atual: {$tenant->name} ({$tenant->slug}).",
            'Papel no tenant atual: '.TenantRoles::label($user->tenantRole($tenant)).'.',
            'Administrador do tenant: '.($this->access->isTenantAdministrator($user, $tenant) ? 'sim' : 'não').'.',
            "Tenants acessíveis pela conta: {$tenants->count()}.",
            "Contratos acessíveis em todos os tenants: {$contractsAcrossTenants}.",
            "Contratos acessíveis no tenant atual: {$contracts->count()}.",
            "Módulos e ações disponíveis no tenant atual:\n{$moduleLines}",
            "Escopo dos módulos com permissão por contrato:\n{$scopedModuleLines}",
            'Tenants: '.($tenants->pluck('name')->take(30)->implode(', ') ?: 'nenhum').'.',
            'Contratos do tenant atual: '.($contracts->take(30)->map(fn (Contract $contract): string => "{$contract->code} - {$contract->name}")->implode('; ') ?: 'nenhum').'.',
        ]);

        return $this->source(
            'Acesso',
            'Seu acesso, contratos, módulos e permissões',
            route('tenant.contracts.index', $tenant),
            $content,
            1000
        );
    }

    private function operationalSource(User $user, Tenant $tenant, Collection $contractIds, array $capabilities): array
    {
        $lines = [];
        $contractQuery = Contract::query()->where('tenant_id', $tenant->id)->whereIn('id', $contractIds);
        $lines[] = $this->summaryLine('Contratos', $contractQuery, 'status');

        if ($capabilities['activities']['view']) {
            $activityIds = $this->access->activityContractIds($user, $tenant);
            $activityQuery = Activity::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('contract_id', $activityIds)
                ->visibleTo($user);
            $lines[] = $this->summaryLine('Atividades visíveis', $activityQuery, 'status');
        }

        if ($capabilities['projects']['view']) {
            $projectQuery = ProjectDocument::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('contract_id', $this->access->projectContractIds($user, $tenant));
            $lines[] = $this->summaryLine('Projetos', $projectQuery, 'status');
        }

        if ($capabilities['quality']['view']) {
            $rncQuery = RelatorioNaoConformidade::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('contract_id', $this->access->rncContractIds($user, $tenant));
            $lines[] = $this->summaryLine('RNCs', $rncQuery, 'status');
        }

        $documentStats = $this->documentStats($user, $tenant, $contractIds);
        $lines[] = 'Documentos visíveis: '.$documentStats['total'].'; por status: '.$this->formatDistribution($documentStats['statuses']).'.';

        if ($capabilities['budgets']['view']) {
            $budgetQuery = BudgetPermissions::scopeVisibleTo(
                Orcamento::query()->where('tenant_id', $tenant->id),
                $user,
                $tenant
            );
            $lines[] = $this->summaryLine('Orçamentos visíveis', $budgetQuery, 'status');
        }

        if ($contractIds->isNotEmpty()) {
            $lines[] = $this->summaryLine('RDOs', RdoDiario::query()->where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds), 'status');
            $lines[] = $this->summaryLine('RDAs', RdaApontamento::query()->where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds), 'status');
            $lines[] = $this->summaryLine('Ordens de serviço', OrdemServico::query()->where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds), 'status');
            $lines[] = $this->summaryLine('Boletins de medição', BoletimMedicao::query()->where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds), 'status');
            $lines[] = $this->summaryLine('Folhas de rosto', FolhaRosto::query()->where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds), 'status');
        }

        return $this->source(
            'Visão geral',
            'Resumo dos registros que você pode consultar',
            route('tenant.dashboard', $tenant),
            implode("\n", $lines),
            920
        );
    }

    private function attentionSource(User $user, Tenant $tenant, Collection $contractIds, array $capabilities): array
    {
        $items = collect();

        Contract::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $contractIds)
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [today(), today()->addDays(180)])
            ->orderBy('ends_at')
            ->limit(6)
            ->get(['code', 'name', 'ends_at'])
            ->each(fn (Contract $contract) => $items->push(
                "Contrato {$contract->code} termina em {$contract->ends_at->format('d/m/Y')} (faltam ".today()->diffInDays($contract->ends_at).' dias).'
            ));

        if ($capabilities['activities']['view']) {
            Activity::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('contract_id', $this->access->activityContractIds($user, $tenant))
                ->visibleTo($user)
                ->whereNull('completed_at')
                ->whereDate('due_date', '<', today())
                ->orderBy('due_date')
                ->limit(6)
                ->get(['title', 'due_date'])
                ->each(fn (Activity $activity) => $items->push(
                    "Atividade atrasada: {$activity->title}; prazo {$activity->due_date->format('d/m/Y')}."
                ));
        }

        if ($capabilities['projects']['view']) {
            $pendingProjects = ProjectDocument::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('contract_id', $this->access->projectContractIds($user, $tenant))
                ->whereIn('status', ['em_analise', 'em_aprovacao'])
                ->count();

            if ($pendingProjects > 0) {
                $items->push("Projetos aguardando análise ou aprovação: {$pendingProjects}.");
            }
        }

        if ($capabilities['quality']['view']) {
            $openRncs = RelatorioNaoConformidade::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('contract_id', $this->access->rncContractIds($user, $tenant))
                ->where('status', '!=', 'finalizada')
                ->count();

            if ($openRncs > 0) {
                $items->push("RNCs ainda não finalizadas: {$openRncs}.");
            }
        }

        return $this->source(
            'Pendências',
            'Prazos e pontos de atenção acessíveis',
            route('tenant.dashboard', $tenant),
            $items->isEmpty() ? 'Nenhum prazo crítico ou ponto de atenção foi encontrado no escopo acessível.' : $items->implode("\n"),
            880
        );
    }

    private function moduleCatalogSource(Tenant $tenant, array $capabilities): array
    {
        $content = collect($this->workflowCatalog())
            ->filter(fn (array $guide): bool => ($capabilities[$guide['capability']]['view'] ?? false) && $guide['enabled'])
            ->map(fn (array $guide): string => "- {$guide['label']}: {$guide['summary']}")
            ->implode("\n");

        return $this->source(
            'Ajuda',
            'O que cada módulo disponível faz',
            route('tenant.dashboard', $tenant),
            $content,
            860
        );
    }

    private function workflowSources(Tenant $tenant, string $question, ?string $currentPath, array $capabilities): Collection
    {
        $needle = Str::ascii(Str::lower($question.' '.($currentPath ?? '')));

        return collect($this->workflowCatalog())
            ->filter(fn (array $guide): bool => ($capabilities[$guide['capability']]['view'] ?? false) && $guide['enabled'])
            ->map(function (array $guide, string $key) use ($needle): array {
                $score = collect([$key, $guide['capability'], $guide['label'], ...$guide['aliases']])
                    ->sum(fn (string $alias): int => str_contains($needle, Str::ascii(Str::lower($alias))) ? 1 : 0);

                return ['guide' => $guide, 'score' => $score];
            })
            ->filter(fn (array $item): bool => $item['score'] > 0)
            ->sortByDesc('score')
            ->take(3)
            ->map(fn (array $item): array => $this->source(
                'Ajuda',
                'Fluxo de '.$item['guide']['label'],
                str_starts_with($item['guide']['route'], 'platform.')
                    ? route($item['guide']['route'])
                    : route($item['guide']['route'], $tenant),
                $item['guide']['tutorial'] ?? $item['guide']['workflow'],
                900 + ($item['score'] * 10)
            ))
            ->values();
    }

    private function capabilities(User $user, Tenant $tenant): array
    {
        $hasTenantAccess = $user->hasTenantAccess($tenant);
        $isAdmin = $this->access->isTenantAdministrator($user, $tenant);

        return [
            'platform_admin' => $this->capability('Administração da plataforma', (bool) $user->is_platform_admin),
            'dashboard' => $this->capability('Visão geral', $hasTenantAccess),
            'contracts' => $this->capability(
                'Contratos',
                ContractPermissions::canAny($user, $tenant, ContractPermissions::VIEW),
                $this->allowedLabels(ContractPermissions::labels(), fn (string $permission): bool => ContractPermissions::canAny($user, $tenant, $permission))
            ),
            'activities' => $this->capability(
                'Atividades',
                ActivityPermissions::canAny($user, $tenant, ActivityPermissions::VIEW),
                $this->allowedLabels(ActivityPermissions::labels(), fn (string $permission): bool => ActivityPermissions::canAny($user, $tenant, $permission))
            ),
            'planning' => $this->capability('Planejamento', false),
            'budgets' => $this->capability(
                'Orçamentos',
                BudgetPermissions::can($user, $tenant, BudgetPermissions::VIEW),
                $this->allowedLabels(BudgetPermissions::labels(), fn (string $permission): bool => BudgetPermissions::can($user, $tenant, $permission))
            ),
            'measurement' => $this->capability(
                'Medição',
                MedicaoPermissions::canAny($user, $tenant, MedicaoPermissions::VIEW),
                $this->allowedLabels(MedicaoPermissions::labels(), fn (string $permission): bool => MedicaoPermissions::canAny($user, $tenant, $permission))
            ),
            'service_orders' => $this->capability(
                'Ordem de Serviço',
                OrdemServicoPermissions::canAny($user, $tenant, OrdemServicoPermissions::VIEW),
                $this->allowedLabels(OrdemServicoPermissions::labels(), fn (string $permission): bool => OrdemServicoPermissions::canAny($user, $tenant, $permission))
            ),
            'field' => $this->capability(
                'Diário de Obra (RDO e RDA)',
                DiarioObraPermissions::canAny($user, $tenant, DiarioObraPermissions::VIEW),
                $this->allowedLabels(DiarioObraPermissions::labels(), fn (string $permission): bool => DiarioObraPermissions::canAny($user, $tenant, $permission))
            ),
            'documents' => $this->capability(
                'Documentação',
                DocumentationPermissions::canAny($user, $tenant, DocumentationPermissions::VIEW),
                $this->allowedLabels(DocumentationPermissions::labels(), fn (string $permission): bool => DocumentationPermissions::canAny($user, $tenant, $permission))
            ),
            'projects' => $this->capability(
                'Projetos',
                ProjectPermissions::canAny($user, $tenant, ProjectPermissions::VIEW),
                $this->allowedLabels(ProjectPermissions::labels(), fn (string $permission): bool => ProjectPermissions::canAny($user, $tenant, $permission))
            ),
            'quality' => $this->capability(
                'Qualidade / RNC',
                RncPermissions::canAny($user, $tenant, RncPermissions::VIEW),
                $this->allowedLabels(RncPermissions::labels(), fn (string $permission): bool => RncPermissions::canAny($user, $tenant, $permission))
            ),
            'tutorials' => $this->capability('Tutoriais', $hasTenantAccess),
            'users' => $this->capability(
                'Usuários',
                UserPermissions::can($user, $tenant, UserPermissions::VIEW),
                $this->allowedLabels(UserPermissions::labels(), fn (string $permission): bool => UserPermissions::can($user, $tenant, $permission))
            ),
            'permissions' => $this->capability('Permissões', $isAdmin),
            'settings' => $this->capability(
                'Parametrização',
                ParametrizacaoPermissions::can($user, $tenant, ParametrizacaoPermissions::VIEW),
                $this->allowedLabels(ParametrizacaoPermissions::labels(), fn (string $permission): bool => ParametrizacaoPermissions::can($user, $tenant, $permission))
            ),
        ];
    }

    private function capability(string $label, bool $view, array $permissions = []): array
    {
        return compact('label', 'view', 'permissions');
    }

    private function allowedLabels(array $labels, callable $can): array
    {
        return collect($labels)
            ->filter(fn (string $label, string $permission): bool => $can($permission))
            ->values()
            ->all();
    }

    private function summaryLine(string $label, Builder $query, string $column): string
    {
        $distribution = (clone $query)
            ->selectRaw("{$column}, COUNT(*) as aggregate")
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn ($count): int => (int) $count)
            ->all();

        return "{$label}: ".array_sum($distribution).'; por status: '.$this->formatDistribution($distribution).'.';
    }

    private function documentStats(User $user, Tenant $tenant, Collection $contractIds): array
    {
        if ($contractIds->isEmpty()) {
            return ['total' => 0, 'statuses' => []];
        }

        $companyId = $this->access->companyId($user, $tenant);
        $total = 0;
        $statuses = [];

        GedDocument::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $contractIds)
            ->select(['id', 'tenant_id', 'contract_id', 'uploaded_by_id', 'metadata', 'status'])
            ->lazyById(500)
            ->each(function (GedDocument $document) use ($user, $tenant, $contractIds, $companyId, &$total, &$statuses): void {
                if (! $this->access->canReadDocumentWithinScope($user, $tenant, $document, $contractIds, $companyId)) {
                    return;
                }

                $total++;
                $status = filled($document->status) ? (string) $document->status : 'sem status';
                $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            });

        return ['total' => $total, 'statuses' => $statuses];
    }

    private function formatDistribution(array $distribution): string
    {
        if ($distribution === []) {
            return 'nenhum registro';
        }

        return collect($distribution)
            ->map(fn (int $count, string|int $status): string => ($status !== '' ? $status : 'sem status').": {$count}")
            ->implode(', ');
    }

    private function source(string $module, string $title, string $url, string $content, int $priority): array
    {
        $content = Str::limit($content, 2600);

        return [
            'module' => $module,
            'title' => $title,
            'url' => $url,
            'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $content) ?: '', 180),
            'content' => $content,
            '_priority' => $priority,
        ];
    }

    private function workflowCatalog(): array
    {
        return TutorialCatalog::assistantGuides();
    }

    /** @deprecated The canonical catalog is maintained by TutorialCatalog. */
    private function legacyWorkflowCatalog(): array
    {
        return [
            'contracts' => [
                'label' => 'Contratos',
                'aliases' => ['contrato', 'aditivo', 'parametrização contratual'],
                'route' => 'tenant.contracts.index',
                'enabled' => true,
                'summary' => 'centraliza vigência, valor, empresas, obras, disciplinas, equipe, aditivos e resumos dos módulos.',
                'workflow' => 'Fluxo: crie o contrato, parametrize empresas/obras/disciplinas, salve os vínculos e escolha o modo de medição. Use Aditivo para registrar custo e/ou novo prazo com documento. Ao abrir o contrato, acompanhe equipe, atividades, projetos e RNCs vinculados.',
                'tutorial' => <<<'TEXT'
Tutorial de contratos:
1. Em Contratos, clique em Novo contrato e informe identificação, vigência, valor e documento contratual.
2. Abra Parametrizar. Cadastre ou selecione empresas, obras e disciplinas e, por último, salve os vínculos do contrato. A obra principal deve ser uma obra pai.
3. Na aba Medição, escolha entre Simples e Controlada antes de iniciar medições. A modalidade não deve ser alterada depois que o contrato entrar em operação.
4. Vincule a equipe e confira quem participa do contrato. Esses vínculos delimitam o acesso dos usuários aos módulos por contrato.
5. Use Aditivo para registrar custo, prazo ou ambos, com título, motivação e documento. O novo prazo começa após a vigência atual e o histórico preserva o contrato base e todos os aditivos.
6. Ao abrir o contrato, acompanhe prazo restante, equipe e os resumos de atividades, projetos e RNCs. Os atalhos permitem criar registros já relacionados ao contrato.
TEXT,
            ],
            'activities' => [
                'label' => 'Atividades',
                'aliases' => ['atividade', 'tarefa', 'métricas de atividade'],
                'route' => 'tenant.activities.index',
                'enabled' => true,
                'summary' => 'organiza tarefas públicas ou restritas, responsáveis, comentários, anexos, prazos e métricas.',
                'workflow' => 'Fluxo: crie a atividade pelo modal, informe contrato, categoria, responsáveis, prazo, prioridade e visibilidade. Atividade pública aparece aos usuários do contrato; restrita apenas ao criador e vinculados. Os envolvidos movimentam, comentam e anexam arquivos. O criador gerencia a atividade e o dashboard Métricas mostra produtividade e cumprimento de prazos.',
                'tutorial' => <<<'TEXT'
Tutorial de atividades:
1. Em Atividades, clique em Nova atividade. Selecione contrato, categoria, prioridade, prazo e os responsáveis.
2. Escolha a visibilidade. Pública permite visualização pelos usuários do contrato; restrita limita o card ao criador e aos usuários vinculados à atividade.
3. Salve para criar o card no fluxo. Os participantes podem movimentar a atividade, comentar e anexar arquivos sem uma permissão adicional.
4. Abra o card para acompanhar descrição, responsáveis, prazo, histórico, comentários e anexos. O criador pode editar, excluir e gerenciar os responsáveis; outros usuários dependem das permissões globais para editar ou excluir atividades alheias.
5. Conclua a atividade no fluxo quando o trabalho terminar. Em Métricas, acompanhe produtividade, responsáveis, categorias, resolução dentro do prazo e atrasos, desde que possua a permissão de visualizar métricas.
TEXT,
            ],
            'budgets' => [
                'label' => 'Orçamentos',
                'aliases' => ['orçamento', 'orcamento', 'insumo', 'composição', 'composicao', 'base de preço', 'sinapi', 'sicro', 'sicro 3'],
                'route' => 'tenant.orcamentos.index',
                'enabled' => true,
                'summary' => 'estrutura bases de preço, insumos, composições, etapas, BDI, relatórios e acessos por orçamento.',
                'workflow' => 'Fluxo recomendado: consulte ou cadastre insumos, monte composições com coeficientes e custos e então crie o orçamento. No orçamento, configure bases, encargos e BDI, crie etapas e adicione composições ou insumos. O botão Acessos define quem apenas visualiza ou também edita; relatórios e finalização dependem das permissões globais.',
                'tutorial' => <<<'TEXT'
Tutorial de orçamento com SINAPI e SICRO:
Pré-requisito: o usuário precisa visualizar Orçamentos e ter as permissões exigidas para cadastrar, importar ou editar.
1. Em Orçamentos > Insumos, consulte a base SINAPI ou SICRO escolhendo banco, estado e data de referência. Use os filtros para localizar o código ou a descrição. Também é possível cadastrar insumo próprio ou importar uma base, conforme a permissão.
2. Em Composições, consulte as composições oficiais e abra o detalhe para conferir itens analíticos, coeficientes, unidades e preços por UF. Para uma composição própria, cadastre os dados e vincule seus insumos ou composições auxiliares.
3. Em Orçamentos, clique em Novo orçamento. Informe os dados gerais; depois configure arredondamento, encargos sociais e BDI; por fim escolha as bases SINAPI/SICRO, UF e competência. Se permitir preços zerados, esses valores precisarão ser preenchidos antes da conclusão.
4. Abra o orçamento, crie as etapas da estrutura analítica e adicione composições ou insumos. Informe quantidades e confira valor unitário, incidência de BDI e total de cada item.
5. Revise os totais, gere os relatórios e finalize quando a estrutura estiver conferida. Use Acessos para definir quem pode somente visualizar ou também editar o orçamento.
TEXT,
            ],
            'projects' => [
                'label' => 'Projetos',
                'aliases' => ['projeto', 'aps', 'autodesk', 'revisão', 'cap', 'lista mestra'],
                'route' => 'tenant.projects.visualizar.index',
                'enabled' => true,
                'summary' => 'controla responsáveis, submissão, análise, aprovação, visualização APS, comentários, CAP e revisões.',
                'workflow' => 'Fluxo: vincule responsáveis por disciplina, submeta o projeto com EAP e arquivo, analise e aprove ou reprove. Depois de aprovado, ele entra na árvore de visualização para inspeção e comentários. Revisões aprovadas atualizam a árvore, notificam usuários do contrato e geram histórico/CAP. A Lista Mestra filtra e exporta o acervo.',
                'tutorial' => <<<'TEXT'
Tutorial de projetos:
1. Em Responsáveis, vincule por contrato e disciplina quem analisa e quem aprova os projetos.
2. Em Submeter projeto, informe contrato, obra, trecho, disciplina, fase, tipo de documento, título e arquivo. Em obras sem divisão por trechos, use GER - Geral. Confira a EAP e a revisão no modal de confirmação.
3. Em Analisar projeto, abra o Viewer e o checklist, registre comentários técnicos e envie para aprovação. A reprovação exige motivo e notifica quem submeteu.
4. Depois da aprovação, o projeto entra na árvore de Visualizar projetos. Use o Viewer APS para navegar, medir, consultar objetos e propriedades e registrar comentários visuais com responsáveis e respostas.
5. Uma nova submissão de projeto já aprovado entra como revisão. Durante o processo, a versão vigente fica Em revisão. Após aprovada, a revisão atualiza a árvore e notifica os usuários do contrato.
6. Em Projetos revisados, consulte a CAP, o histórico e a comparação entre versões. A Lista Mestra combina filtros e exporta a relação em PDF ou Excel.
TEXT,
            ],
            'quality' => [
                'label' => 'Qualidade / RNC',
                'aliases' => ['qualidade', 'rnc', 'não conformidade', 'nao conformidade', 'ação corretiva', 'evidência'],
                'route' => 'tenant.qualidade.rnc.index',
                'enabled' => true,
                'summary' => 'opera o ciclo de não conformidade, responsáveis, ação corretiva, evidência, PDF e dashboard.',
                'workflow' => 'Fluxo: primeiro aloque responsáveis operacional, da construtora e de acompanhamento. Abra a RNC, vincule um ou mais projetos quando necessário e notifique. A construtora envia a ação corretiva; o responsável operacional analisa, solicita ajustes ou aprova e registra evidências. A finalização gera o PDF e alimenta o dashboard.',
                'tutorial' => <<<'TEXT'
Tutorial para abrir e conduzir uma RNC:
Pré-requisito: em Qualidade > RNC > Responsáveis, vincule ao contrato o responsável operacional, o responsável da construtora e, quando necessário, o responsável de acompanhamento.
1. Em Qualidade > RNC, clique em Nova RNC. Escolha contrato e obra, informe disciplina, gravidade, natureza, local e as datas solicitadas.
2. Vincule um ou mais projetos quando a ocorrência estiver relacionada a projetos. Descreva claramente o problema, acrescente observações, ações corretivas recomendadas, coordenadas e fotografias.
3. Salve a RNC e confira o registro. Enquanto ainda não foi notificada, ajuste os dados se necessário.
4. Clique em Notificar. O sistema comunica os responsáveis e libera o envio da proposta de ação corretiva.
5. O responsável da construtora informa a correção proposta, o prazo e o documento de apoio. O responsável operacional analisa: pode aprovar ou reprovar com justificativa.
6. Depois da aprovação da proposta, registre as evidências da execução com descrições, imagens e anexos. O envio conclui o fluxo conforme as permissões.
7. Consulte o histórico e o PDF final na própria RNC. O Dashboard de RNC mostra volumes, atrasos, propostas em análise e registros finalizados.
TEXT,
            ],
            'documents' => [
                'label' => 'Documentação',
                'aliases' => ['documento', 'documentação', 'ged', 'ocr', 'anexo', 'e-mail', 'email', 'triagem', 'lixeira'],
                'route' => 'tenant.ged.index',
                'enabled' => true,
                'summary' => 'gerencia PDFs, OCR, anexos, permissões, e-mails, triagem, parametrização e lixeira.',
                'workflow' => 'Fluxo: envie o PDF principal vinculado a um contrato e use Anexos para arquivos complementares. PDFs anexos também podem passar por OCR e ser visualizados. Regras de e-mail podem criar um PDF do e-mail e anexar os demais arquivos; quando há mais de um PDF e a regra não define o principal, a mensagem vai para Triagem. Exclusões vão para a Lixeira antes da remoção definitiva.',
                'tutorial' => <<<'TEXT'
Tutorial de documentação:
1. Em Documentação, clique em Enviar documento. O contrato é o único campo obrigatório e o documento principal deve ser PDF.
2. Abra o documento para editar detalhes, consultar o conteúdo OCR, anexos, metadados, notas, histórico e permissões.
3. Em Anexos, vincule vários arquivos complementares. PDFs anexos passam por OCR e podem ser visualizados; outros formatos ficam disponíveis para download.
4. Em E-mail, configure a conta e as regras de consumo. A regra pode processar anexos ou criar um PDF fiel ao e-mail e manter os arquivos recebidos como anexos do documento.
5. Quando um e-mail tiver mais de um PDF e não houver definição segura do principal, ele vai para Triagem para que o usuário faça a escolha. Mensagens já processadas não devem ser importadas novamente.
6. A Parametrização mantém tipos, etiquetas e correspondentes. Tipos ou etiquetas em uso não podem ser excluídos.
7. Excluir um documento o envia para a Lixeira, onde pode ser restaurado ou removido definitivamente.
TEXT,
            ],
            'field' => [
                'label' => 'Diário de Obra',
                'aliases' => ['diário de obra', 'diario de obra', 'rdo', 'rda', 'assinatura digital', 'offline'],
                'route' => 'tenant.diario-obra.rdo.calendar',
                'enabled' => true,
                'summary' => 'registra RDA de campo, consolida RDO, aprovações, assinatura digital e PDF diário.',
                'workflow' => 'Fluxo: configure responsáveis, cadastros reutilizáveis e parâmetros do RDO. O RDA é o apontamento de apoio preenchido no campo, inclusive pelo aplicativo mobile offline. Os apontamentos são consolidados no RDO, que segue pelas etapas de conferência e aprovação, recebe assinaturas digitais e gera o documento final.',
                'tutorial' => <<<'TEXT'
Tutorial de Diário de Obra, RDA e RDO:
Pré-requisito: em Diário de Obra, parametrize o contrato, obras ou frentes, dias de geração, prazos, continuidade, geração automática e assinatura digital. Cadastre mão de obra, equipamentos e subcontratadas e defina os responsáveis.
1. O RDA é o apontamento de campo que apoia o RDO oficial. O responsável registra clima, atividades, ocorrências, equipes, equipamentos e fotografias.
2. No aplicativo mobile, o RDA pode ser preenchido offline. Quando a conexão voltar, sincronize os dados e publique o apontamento. Somente RDAs publicados ficam disponíveis para consolidação.
3. No Calendário do RDO, escolha a data habilitada. Gere um RDO vazio ou copie o anterior, conforme a configuração do contrato.
4. Abra o RDO e importe os RDAs publicados da mesma data e frente. Revise as seções, elimine duplicidades e complete as informações necessárias.
5. Envie o RDO para análise. Gerenciadora e cliente registram aprovação, ressalva ou devolução com parecer, preservando o histórico.
6. Após a aprovação, gere o PDF e envie para assinatura digital dos participantes configurados. O sistema acompanha as assinaturas e guarda o PDF final e a trilha de auditoria.
7. Use o Dashboard do RDO para acompanhar geração, envio, aprovação, devoluções e preenchimento no período.
TEXT,
            ],
            'service_orders' => [
                'label' => 'Ordem de Serviço',
                'aliases' => ['ordem de serviço', 'ordem servico', 'os', 'fiscal', 'aprovação da os'],
                'route' => 'tenant.ordem-servico.os.index',
                'enabled' => true,
                'summary' => 'autoriza itens contratuais para medição controlada e acompanha análise, aprovação, custo previsto e real.',
                'workflow' => 'Fluxo: crie a OS em rascunho, vincule itens de contrato e responsáveis e ajuste enquanto estiver em rascunho. Ao enviar para análise, a edição é bloqueada. Fiscais analisam e aprovadores concluem; uma reprovação devolve a OS ao rascunho com comunicação aos responsáveis. Na medição controlada, apenas itens de OS aprovada podem ser pleiteados.',
                'tutorial' => <<<'TEXT'
Tutorial de Ordem de Serviço:
1. Em Ordem de Serviço > OS, clique em Nova OS. Informe contrato, obra, título, descrição, prazo, custos e selecione os itens contratuais autorizados.
2. Salve como rascunho. Enquanto estiver nesse estado, a OS pode ser reaberta e editada; revise itens, quantidades e responsáveis antes do envio.
3. Em Responsáveis, configure fiscais e aprovadores da obra. A listagem agrupa as funções por usuário.
4. Envie a OS para análise e confirme no diálogo. Depois do envio, a edição fica bloqueada.
5. Em Análise, o fiscal registra a decisão. Se aprovada, a OS segue para aprovação; se reprovada, retorna a rascunho com o motivo e os responsáveis recebem a comunicação.
6. O aprovador conclui ou reprova a OS. Uma OS aprovada libera seus itens para pleito quando o contrato usa Medição Controlada.
7. A listagem acompanha status, custo previsto, custo real calculado pelas quantidades medidas e o percentual medido dos itens.
TEXT,
            ],
            'measurement' => [
                'label' => 'Medição',
                'aliases' => ['medição', 'medicao', 'boletim', 'bm', 'folha de rosto', 'pleito', 'reajuste'],
                'route' => 'tenant.medicao.boletim-medicao.index',
                'enabled' => true,
                'summary' => 'controla itens de contrato, pleitos, folhas de rosto, boletins, reajustes, análises e relatórios.',
                'workflow' => 'O contrato define o modo de medição. No modo Controlada, uma OS aprovada libera quais itens podem entrar em folha de rosto e pleito. No modo Simples, a folha de rosto e o pleito podem usar itens do contrato sem OS. Depois, os valores seguem para análise, boletim, reajustes e relatórios. A escolha do modo deve ser feita na parametrização do contrato e não deve mudar após o início da operação.',
                'tutorial' => <<<'TEXT'
Tutorial de medição:
Pré-requisito: na Parametrização do contrato > Medição, escolha uma vez o modo Simples ou Controlada antes de iniciar o fluxo. Confira também os Itens de Contrato e os índices de reajuste.
1. No modo Controlada, crie uma Ordem de Serviço, vincule os itens autorizados e conclua o fluxo de análise e aprovação. Somente itens de OS aprovada ficam disponíveis para o pleito.
2. No modo Simples, não é necessária OS aprovada: a Folha de Rosto pode usar diretamente os itens do contrato.
3. Em Boletim Medição, cadastre ou abra a competência correta. A numeração do BM é sequencial dentro de cada contrato.
4. Em Folha de Rosto, selecione o BM aberto, a construtora solicitante e os itens. Informe as quantidades pleiteadas e o comentário do período; depois envie para análise.
5. Em Analisar pleito, os responsáveis conferem os itens e registram as quantidades aprovadas ou as devoluções em cada etapa do fluxo.
6. Confira reajustes, acumulados e valores do boletim. Congele ou finalize o BM somente depois da conferência.
7. Use Relatórios de Medição para gerar pleito preliminar, análise, sintético, resumo e demais saídas; o B.I. apresenta os indicadores e a curva ABC dos itens pleiteados.
TEXT,
            ],
            'users' => [
                'label' => 'Usuários',
                'aliases' => ['usuário', 'usuario', 'vincular usuário'],
                'route' => 'tenant.users.index',
                'enabled' => true,
                'summary' => 'administra usuários do tenant e seus vínculos com contratos.',
                'workflow' => 'Cadastre ou localize o usuário, edite os dados quando permitido e use Vincular para adicioná-lo a outros contratos. As ações disponíveis dependem das permissões globais de usuários.',
            ],
            'settings' => [
                'label' => 'Parametrização',
                'aliases' => ['parametrização', 'parametrizacao', 'empresa', 'obra', 'disciplina'],
                'route' => 'tenant.dashboard',
                'enabled' => true,
                'summary' => 'mantém empresas, obras, disciplinas e vínculos estruturais usados pelos módulos.',
                'workflow' => 'Use a Parametrização para manter empresas, obras e disciplinas e para organizar os vínculos disponíveis nos contratos. As seções exibidas respeitam as permissões específicas do usuário.',
            ],
            'dashboard' => [
                'label' => 'Visão geral',
                'aliases' => ['visão geral', 'visao geral', 'dashboard', 'resumo'],
                'route' => 'tenant.dashboard',
                'enabled' => true,
                'summary' => 'resume contratos, atividades, projetos, RNCs e indicadores operacionais do tenant.',
                'workflow' => 'A Visão geral reúne indicadores e atalhos para acompanhar a operação. Use os módulos para aprofundar os dados e os contratos para ver o resumo integrado de cada contrato.',
            ],
            'tutorials' => [
                'label' => 'Tutoriais',
                'aliases' => ['tutorial', 'tour', 'ajuda'],
                'route' => 'tenant.tutorials.index',
                'enabled' => true,
                'summary' => 'reúne orientações e tours operacionais do sistema.',
                'workflow' => 'Abra Tutoriais ou use os botões Iniciar tour nos módulos disponíveis para acompanhar fluxos demonstrativos passo a passo.',
            ],
        ];
    }
}

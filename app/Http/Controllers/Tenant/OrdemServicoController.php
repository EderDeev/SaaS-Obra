<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Empresa;
use App\Models\MedicaoItem;
use App\Models\Obra;
use App\Models\OrdemServico;
use App\Models\OrdemServicoAnalise;
use App\Models\OrdemServicoComentario;
use App\Models\OrdemServicoContractSetting;
use App\Models\OrdemServicoDocumento;
use App\Models\OrdemServicoItem;
use App\Models\OrdemServicoObraResponsavel;
use App\Models\ProjectDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OrdemServicoApprovalDecisionNotification;
use App\Notifications\OrdemServicoCommentNotification;
use App\Notifications\OrdemServicoLifecycleNotification;
use App\Notifications\OrdemServicoReadyForApprovalNotification;
use App\Notifications\OrdemServicoReturnedForCorrectionNotification;
use App\Notifications\OrdemServicoSubmittedForReviewNotification;
use App\Support\MedicaoReajusteCalculator;
use App\Support\OrdemServicoPermissions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrdemServicoController extends Controller
{
    public function index(Request $request, Tenant $tenant): Response
    {
        $contractQuery = $tenant->contracts()
            ->with('obra')
            ->orderBy('code');
        $permissionContractIds = OrdemServicoPermissions::contractIdsFor(
            $request->user(),
            $tenant,
            OrdemServicoPermissions::VIEW
        );

        if ($permissionContractIds !== null) {
            $contractQuery->whereIn('contracts.id', $permissionContractIds);
        }

        $contractModels = $contractQuery->get();
        $requestedContractId = $request->integer('contract_id');
        $selectedContract = $contractModels->firstWhere('id', $requestedContractId) ?? $contractModels->first();
        $selectedContractId = $selectedContract?->id;

        $contracts = $contractModels
            ->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->name,
                'obra_id' => $contract->obra_id,
            ]);

        $editOrderId = $request->integer('edit') ?: null;
        $canManageDrafts = $selectedContract
            ? OrdemServicoPermissions::can($request->user(), $tenant, OrdemServicoPermissions::MANAGE_DRAFTS, $selectedContract)
            : false;
        $ordemModels = OrdemServico::query()
            ->where('tenant_id', $tenant->id)
            ->when($selectedContractId, fn ($query) => $query->where('contract_id', $selectedContractId))
            ->with([
                'contract:id,code,name',
                'obra:id,nome,codigo',
                'creator:id,name,email,avatar_url',
            ])
            ->latest('id')
            ->get();
        $measurementSummaries = $this->orderMeasurementSummaries($ordemModels->pluck('id'));
        $ordens = $ordemModels->map(fn (OrdemServico $ordem): array => $this->serializeOrdemSummary(
            $ordem,
            $measurementSummaries->get($ordem->id)
        ));
        $editOrder = null;

        if ($editOrderId && $canManageDrafts) {
            $editOrderModel = OrdemServico::query()
                ->where('tenant_id', $tenant->id)
                ->when($selectedContractId, fn ($query) => $query->where('contract_id', $selectedContractId))
                ->where('status', 'rascunho')
                ->with([
                    'contract:id,code,name',
                    'obra:id,nome,codigo',
                    'projectDocument:id,title,code',
                    'projectDocuments:id,title,code',
                    'gerenciadoraEmpresa:id,nome,sigla,tipo_empresa_id',
                    'construtoraEmpresa:id,nome,sigla,tipo_empresa_id',
                    'creator:id,name,email,avatar_url',
                    'itens' => fn ($query) => $this->withMeasuredQuantity($query),
                    'itens.medicaoItem' => fn ($query) => $query
                        ->select('id', 'item', 'codigo', 'descricao', 'unidade', 'quantidade_prevista', 'valor_unitario', 'valor_com_bdi', 'valor_total'),
                    'itens.medicaoItem.reajusteIndice.indice.competencias',
                    'responsaveis.user:id,name,email,avatar_url',
                    'documentos:id,ordem_servico_id,uploaded_by_id,categoria,comentario_id,nome_original,size',
                    'documentos.uploader:id,name',
                ])
                ->find($editOrderId);

            $editOrder = $editOrderModel ? $this->serializeOrdem($editOrderModel) : null;
        }

        return Inertia::render('Tenant/OrdemServico/Index', [
            'selectedContractId' => $selectedContractId,
            'editOrderId' => $editOrderId,
            'editOrder' => $editOrder,
            'contracts' => $contracts,
            'ordens' => $ordens,
            'options' => [
                'obras' => $this->obraOptions($tenant, $selectedContractId),
                'projects' => $this->projectOptions($tenant, $selectedContractId),
                'empresas' => $this->empresaOptions($tenant, $selectedContractId),
                'users' => $this->userOptions($tenant, $selectedContractId),
            ],
            'can' => [
                'manage_drafts' => $canManageDrafts,
                'execute' => $selectedContract
                    ? OrdemServicoPermissions::can($request->user(), $tenant, OrdemServicoPermissions::EXECUTE, $selectedContract)
                    : false,
                'complete' => $selectedContract
                    ? OrdemServicoPermissions::can($request->user(), $tenant, OrdemServicoPermissions::COMPLETE, $selectedContract)
                    : false,
            ],
        ]);
    }

    public function show(Request $request, Tenant $tenant, OrdemServico $ordem): Response
    {
        $this->ensureTenantOrdem($tenant, $ordem);
        $contract = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($ordem->contract_id);

        abort_unless(
            OrdemServicoPermissions::can($request->user(), $tenant, OrdemServicoPermissions::VIEW, $contract),
            403
        );

        $itemSearch = mb_substr(trim((string) $request->query('items_search', '')), 0, 120);
        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $itemPaginator = null;
        $getItemPaginator = function () use (&$itemPaginator, $itemSearch, $likeOperator, $ordem) {
            if ($itemPaginator) {
                return $itemPaginator;
            }

            $measuredByItem = DB::table('folha_rosto_item_analises as measurement_analyses')
                ->join('folha_rosto_itens as measurement_lines', 'measurement_lines.id', '=', 'measurement_analyses.folha_rosto_item_id')
                ->join('folhas_rosto as measured_covers', 'measured_covers.id', '=', 'measurement_lines.folha_rosto_id')
                ->join('ordem_servico_itens as scoped_order_items', 'scoped_order_items.id', '=', 'measurement_lines.ordem_servico_item_id')
                ->where('scoped_order_items.ordem_servico_id', $ordem->id)
                ->where('measurement_analyses.setor', 'medicao')
                ->where('measured_covers.status', 'analisada')
                ->groupBy('measurement_lines.ordem_servico_item_id')
                ->selectRaw('measurement_lines.ordem_servico_item_id, SUM(measurement_analyses.quantidade_aprovada) as quantidade_medida');

            $itemPaginator = $ordem->itens()
                ->with([
                    'medicaoItem' => fn ($query) => $query
                        ->select('id', 'item', 'codigo', 'descricao', 'unidade', 'quantidade_prevista', 'valor_unitario', 'valor_com_bdi', 'valor_total'),
                    'medicaoItem.reajusteIndice.indice.competencias',
                ])
                ->when($itemSearch !== '', function ($query) use ($itemSearch, $likeOperator): void {
                    $term = '%'.$itemSearch.'%';

                    $query->whereHas('medicaoItem', function ($itemQuery) use ($term, $likeOperator): void {
                        $itemQuery->where(function ($searchQuery) use ($term, $likeOperator): void {
                            $searchQuery
                                ->where('item', $likeOperator, $term)
                                ->orWhere('codigo', $likeOperator, $term)
                                ->orWhere('descricao', $likeOperator, $term);
                        });
                    });
                })
                ->leftJoinSub($measuredByItem, 'measured_items', function ($join): void {
                    $join->on('measured_items.ordem_servico_item_id', '=', 'ordem_servico_itens.id');
                })
                ->select('ordem_servico_itens.*')
                ->selectRaw('COALESCE(measured_items.quantidade_medida, 0) as quantidade_medida')
                ->orderBy('ordem_servico_itens.id')
                ->paginate(perPage: 50, pageName: 'items_page')
                ->withQueryString();

            return $itemPaginator;
        };

        return Inertia::render('Tenant/OrdemServico/Show', [
            'ordem' => function () use ($ordem): array {
                $ordem->load([
                    'contract:id,code,name',
                    'obra:id,nome,codigo',
                    'projectDocument:id,title,code',
                    'projectDocuments:id,title,code',
                    'gerenciadoraEmpresa:id,nome,sigla,tipo_empresa_id',
                    'construtoraEmpresa:id,nome,sigla,tipo_empresa_id',
                    'creator:id,name,email,avatar_url',
                    'responsaveis.user:id,name,email,avatar_url',
                    'documentos:id,ordem_servico_id,uploaded_by_id,categoria,comentario_id,nome_original,size',
                    'documentos.uploader:id,name',
                    'comentarios' => fn ($query) => $query->whereNull('parent_id')->oldest('id'),
                    'comentarios.user:id,name,email,avatar_url',
                    'comentarios.mentions:id,name,email',
                    'comentarios.attachments:id,ordem_servico_id,comentario_id,nome_original,size',
                    'comentarios.replies.user:id,name,email,avatar_url',
                    'comentarios.replies.mentions:id,name,email',
                    'comentarios.replies.attachments:id,ordem_servico_id,comentario_id,nome_original,size',
                    'submittedBy:id,name,email',
                    'analyzedBy:id,name,email',
                    'approvalDecidedBy:id,name,email',
                    'executionStartedBy:id,name,email',
                    'completedBy:id,name,email',
                    'cancelledBy:id,name,email',
                    'analises.user:id,name,email',
                ]);

                $measurementSummary = $this->orderMeasurementSummaries(collect([$ordem->id]))->get($ordem->id);

                return $this->serializeOrdem($ordem, $measurementSummary);
            },
            'items' => fn () => $getItemPaginator()->getCollection()
                ->map(fn (OrdemServicoItem $item): array => $this->serializeOrdemItem($item))
                ->values(),
            'itemPagination' => function () use ($getItemPaginator): array {
                $paginator = $getItemPaginator();

                return [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ];
            },
            'itemFilters' => fn (): array => [
                'search' => $itemSearch,
            ],
            'options' => fn (): array => [
                'users' => $this->userOptions($tenant, $ordem->contract_id),
            ],
            'can' => [
                'manage_drafts' => OrdemServicoPermissions::can($request->user(), $tenant, OrdemServicoPermissions::MANAGE_DRAFTS, $contract),
                'execute' => OrdemServicoPermissions::can($request->user(), $tenant, OrdemServicoPermissions::EXECUTE, $contract),
                'complete' => OrdemServicoPermissions::can($request->user(), $tenant, OrdemServicoPermissions::COMPLETE, $contract),
            ],
        ]);
    }

    public function items(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'contract_id' => ['required', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'planilha' => ['nullable', 'string', 'max:30'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $contractId = (int) $validated['contract_id'];

        abort_unless(
            $tenant->contracts()->whereKey($contractId)->exists(),
            404
        );

        $baseQuery = MedicaoItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contractId)
            ->where('item_type', '!=', 'etapa')
            ->whereNotNull('codigo')
            ->where('codigo', '!=', '');

        $planilhas = (clone $baseQuery)
            ->selectRaw("split_part(item, '.', 1) as planilha")
            ->whereNotNull('item')
            ->where('item', '!=', '')
            ->distinct()
            ->orderBy('planilha')
            ->pluck('planilha')
            ->filter()
            ->values();

        $search = trim((string) ($validated['search'] ?? ''));
        $planilha = trim((string) ($validated['planilha'] ?? ''));

        $paginator = $baseQuery
            ->with('reajusteIndice.indice.competencias')
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';

                $query->where(function ($nested) use ($term): void {
                    $nested->where('item', 'ilike', $term)
                        ->orWhere('codigo', 'ilike', $term)
                        ->orWhere('descricao', 'ilike', $term);
                });
            })
            ->when($planilha !== '' && $planilha !== 'todas', function ($query) use ($planilha): void {
                $query->where(function ($nested) use ($planilha): void {
                    $nested->where('item', $planilha)
                        ->orWhere('item', 'like', $planilha.'.%');
                });
            })
            ->orderByRaw("string_to_array(item, '.')::int[]")
            ->paginate(
                perPage: 50,
                columns: ['id', 'contract_id', 'item', 'item_type', 'codigo', 'descricao', 'unidade', 'quantidade_prevista', 'valor_com_bdi', 'valor_total']
            )
            ->withQueryString();

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (MedicaoItem $item): array => $this->serializeItemOption($item))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'planilhas' => $planilhas,
            ],
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $this->validateOrderPayload($request, $tenant);
        [$contract, $obra, $requestedProjectIds, $items] = $this->resolveOrderSelections($tenant, $validated);

        DB::transaction(function () use ($request, $tenant, $contract, $obra, $validated, $items, $requestedProjectIds): void {
            [$codigo, $sequencial] = $this->nextCode($tenant, $contract, $obra);

            $ordem = OrdemServico::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'project_document_id' => $requestedProjectIds->first(),
                'gerenciadora_empresa_id' => $validated['gerenciadora_empresa_id'],
                'construtora_empresa_id' => $validated['construtora_empresa_id'],
                'created_by_id' => $request->user()?->id,
                'codigo' => $codigo,
                'sequencial' => $sequencial,
                'titulo' => $validated['titulo'],
                'descricao' => $validated['descricao'] ?? null,
                'prazo_inicio' => $validated['prazo_inicio'] ?? null,
                'prazo_finalizacao' => $validated['prazo_finalizacao'] ?? null,
                'prazo_execucao' => $validated['prazo_finalizacao'] ?? null,
                'custo_previsto' => 0,
                'custo_observacao' => $validated['custo_observacao'] ?? null,
                'status' => 'rascunho',
            ]);

            $this->syncOrderRelations($request, $tenant, $ordem, $requestedProjectIds, $items);
            $this->syncOrderPlannedCost($ordem);
        });

        return back()->with('success', 'Ordem de servico criada com sucesso.');
    }

    public function update(Request $request, Tenant $tenant, OrdemServico $ordem): RedirectResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);

        if ($ordem->status !== 'rascunho') {
            throw ValidationException::withMessages([
                'status' => 'Somente OS em rascunho podem ser editadas.',
            ]);
        }

        $validated = $this->validateOrderPayload($request, $tenant);
        [$contract, $obra, $requestedProjectIds, $items] = $this->resolveOrderSelections($tenant, $validated);

        DB::transaction(function () use ($request, $tenant, $ordem, $contract, $obra, $validated, $items, $requestedProjectIds): void {
            $codeChanged = $ordem->contract_id !== $contract->id || $ordem->obra_id !== $obra->id;
            [$codigo, $sequencial] = $codeChanged
                ? $this->nextCode($tenant, $contract, $obra)
                : [$ordem->codigo, $ordem->sequencial];

            $ordem->update([
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'project_document_id' => $requestedProjectIds->first(),
                'gerenciadora_empresa_id' => $validated['gerenciadora_empresa_id'],
                'construtora_empresa_id' => $validated['construtora_empresa_id'],
                'codigo' => $codigo,
                'sequencial' => $sequencial,
                'titulo' => $validated['titulo'],
                'descricao' => $validated['descricao'] ?? null,
                'prazo_inicio' => $validated['prazo_inicio'] ?? null,
                'prazo_finalizacao' => $validated['prazo_finalizacao'] ?? null,
                'prazo_execucao' => $validated['prazo_finalizacao'] ?? null,
                'custo_previsto' => 0,
                'custo_observacao' => $validated['custo_observacao'] ?? null,
            ]);

            $this->syncOrderRelations($request, $tenant, $ordem, $requestedProjectIds, $items);
            $this->syncOrderPlannedCost($ordem);
        });

        return back()->with('success', 'Ordem de servico atualizada com sucesso.');
    }

    public function submitForAnalysis(Request $request, Tenant $tenant, OrdemServico $ordem): RedirectResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);

        if ($ordem->status !== 'rascunho') {
            throw ValidationException::withMessages([
                'status' => 'Somente OS em rascunho podem ser enviadas para análise.',
            ]);
        }

        if (! $ordem->obra_id) {
            throw ValidationException::withMessages([
                'obra_id' => 'Vincule uma obra antes de enviar a OS para análise.',
            ]);
        }

        $fiscais = $this->responsaveisDaObra($tenant, $ordem, 'fiscal');

        if ($fiscais->isEmpty()) {
            throw ValidationException::withMessages([
                'responsaveis' => 'Cadastre ao menos um fiscal responsável pela obra antes de enviar para análise.',
            ]);
        }

        $this->validateSubmissionRequirements($tenant, $ordem);

        DB::transaction(function () use ($request, $ordem): void {
            $ordem->forceFill([
                'status' => 'em_analise',
                'submitted_for_review_at' => now(),
                'submitted_for_review_by_id' => $request->user()?->id,
            ])->save();

            $ordem->analises()->create([
                'user_id' => $request->user()?->id,
                'tipo' => 'analise',
                'decisao' => 'enviada',
                'observacao' => 'OS enviada para análise.',
            ]);
        });

        $ordem->refresh()->loadMissing(['tenant', 'contract', 'obra']);
        $actor = $request->user();

        if ($actor) {
            $fiscais->each(fn (User $user) => $user->notify(new OrdemServicoSubmittedForReviewNotification($ordem, $actor)));
        }

        return back()->with('success', 'OS enviada para análise. Os fiscais da obra foram notificados.');
    }

    public function analise(Request $request, Tenant $tenant): Response
    {
        $contractQuery = $tenant->contracts()->orderBy('code');
        $permissionContractIds = $this->contractIdsForAnyPermissions($request, $tenant, [
            OrdemServicoPermissions::ANALYZE,
            OrdemServicoPermissions::APPROVE,
        ]);

        if ($permissionContractIds !== null) {
            $contractQuery->whereIn('contracts.id', $permissionContractIds);
        }

        $contractModels = $contractQuery->get(['id', 'code', 'name']);
        $requestedContractId = $request->integer('contract_id');
        $selectedContractId = ($contractModels->firstWhere('id', $requestedContractId) ?? $contractModels->first())?->id;

        $contracts = $contractModels
            ->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->name,
            ]);

        $ordens = OrdemServico::query()
            ->where('tenant_id', $tenant->id)
            ->when($selectedContractId, fn ($query) => $query->where('contract_id', $selectedContractId))
            ->whereIn('status', ['em_analise', 'em_aprovacao', 'aprovada', 'recusada'])
            ->with([
                'contract:id,code,name',
                'obra:id,nome,codigo',
                'creator:id,name,email,avatar_url',
            ])
            ->latest('id')
            ->get()
            ->map(fn (OrdemServico $ordem): array => array_merge($this->serializeOrdemAnalysisSummary($ordem), [
                'can_analyze' => $this->canActOnOrdem($request, $tenant, $ordem, 'fiscal'),
                'can_approve' => $this->canActOnOrdem($request, $tenant, $ordem, 'aprovador'),
            ]));

        return Inertia::render('Tenant/OrdemServico/Analise', [
            'selectedContractId' => $selectedContractId,
            'contracts' => $contracts,
            'ordens' => $ordens,
        ]);
    }

    public function analiseDetalhes(Request $request, Tenant $tenant, OrdemServico $ordem): JsonResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);

        $ordem->load([
            'contract:id,code,name',
            'obra:id,nome,codigo',
            'projectDocument:id,tenant_id,contract_id,obra_id,title,code,status',
            'projectDocument.openRncs:id,tenant_id,project_document_id,sequence_number,sequence_year,opened_at,status',
            'projectDocument.latestApprovedVersion',
            'projectDocument.latestVersion',
            'projectDocuments:id,tenant_id,contract_id,obra_id,title,code,status',
            'projectDocuments.openRncs:id,tenant_id,project_document_id,sequence_number,sequence_year,opened_at,status',
            'projectDocuments.latestApprovedVersion',
            'projectDocuments.latestVersion',
            'gerenciadoraEmpresa:id,nome,sigla',
            'construtoraEmpresa:id,nome,sigla',
            'creator:id,name,email,avatar_url',
            'itens' => fn ($query) => $this->withMeasuredQuantity($query),
            'itens.medicaoItem' => fn ($query) => $query
                ->select('id', 'item', 'codigo', 'descricao', 'unidade', 'quantidade_prevista', 'valor_unitario', 'valor_com_bdi', 'valor_total'),
            'itens.medicaoItem.reajusteIndice.indice.competencias',
            'documentos:id,ordem_servico_id,nome_original,size',
            'responsaveis.user:id,name,email,avatar_url',
            'submittedBy:id,name,email',
            'analyzedBy:id,name,email',
            'approvalDecidedBy:id,name,email',
            'analises.user:id,name,email',
        ]);

        return response()->json([
            'ordem' => array_merge($this->serializeOrdem($ordem), [
                'projects' => $this->serializeAnalysisProjects($tenant, $ordem),
                'can_analyze' => $this->canActOnOrdem($request, $tenant, $ordem, 'fiscal'),
                'can_approve' => $this->canActOnOrdem($request, $tenant, $ordem, 'aprovador'),
            ]),
        ]);
    }

    public function analyze(Request $request, Tenant $tenant, OrdemServico $ordem): RedirectResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);
        $this->authorizeOrdemAction($request, $tenant, $ordem, 'fiscal');

        if ($ordem->status !== 'em_analise') {
            throw ValidationException::withMessages([
                'status' => 'Somente OS em análise podem ser enviadas para aprovação.',
            ]);
        }

        $validated = $request->validate([
            'observacao' => ['nullable', 'string'],
        ]);

        $aprovadores = $this->responsaveisDaObra($tenant, $ordem, 'aprovador');

        if ($aprovadores->isEmpty()) {
            throw ValidationException::withMessages([
                'responsaveis' => 'Cadastre ao menos um aprovador responsável pela obra antes de enviar para aprovação.',
            ]);
        }

        DB::transaction(function () use ($request, $ordem, $validated): void {
            $ordem->forceFill([
                'status' => 'em_aprovacao',
                'analyzed_at' => now(),
                'analyzed_by_id' => $request->user()?->id,
                'analysis_observation' => $validated['observacao'] ?? null,
            ])->save();

            $ordem->analises()->create([
                'user_id' => $request->user()?->id,
                'tipo' => 'analise',
                'decisao' => 'analisada',
                'observacao' => $validated['observacao'] ?? null,
            ]);
        });

        $ordem->refresh()->loadMissing(['tenant', 'contract', 'obra']);
        $actor = $request->user();

        if ($actor) {
            $aprovadores->each(fn (User $user) => $user->notify(new OrdemServicoReadyForApprovalNotification($ordem, $actor)));
        }

        return back()->with('success', 'Análise registrada e OS enviada para aprovação.');
    }

    public function approve(Request $request, Tenant $tenant, OrdemServico $ordem): RedirectResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);
        $this->authorizeOrdemAction($request, $tenant, $ordem, 'aprovador');

        if ($ordem->status !== 'em_aprovacao') {
            throw ValidationException::withMessages([
                'status' => 'Somente OS em aprovação podem ser aprovadas ou recusadas.',
            ]);
        }

        $validated = $request->validate([
            'decisao' => ['required', Rule::in(['aprovar'])],
            'observacao' => ['nullable', 'string'],
        ]);

        $status = 'aprovada';

        DB::transaction(function () use ($request, $ordem, $validated, $status): void {
            $ordem->forceFill([
                'status' => $status,
                'approval_decided_at' => now(),
                'approval_decided_by_id' => $request->user()?->id,
                'approval_observation' => $validated['observacao'] ?? null,
            ])->save();

            $ordem->analises()->create([
                'user_id' => $request->user()?->id,
                'tipo' => 'aprovacao',
                'decisao' => $status,
                'observacao' => $validated['observacao'] ?? null,
            ]);
        });

        $ordem->refresh()->loadMissing(['tenant', 'contract', 'obra', 'creator']);
        $actor = $request->user();

        if ($actor) {
            $notifiables = $this->responsaveisDaObra($tenant, $ordem, 'fiscal')
                ->push($ordem->creator)
                ->filter()
                ->unique('id');

            $notifiables->each(fn (User $user) => $user->notify(new OrdemServicoApprovalDecisionNotification(
                $ordem,
                $actor,
                $status,
                $validated['observacao'] ?? null
            )));
        }

        return back()->with('success', 'OS aprovada com sucesso.');
    }

    public function startExecution(Request $request, Tenant $tenant, OrdemServico $ordem): RedirectResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);

        if ($ordem->status !== 'aprovada') {
            throw ValidationException::withMessages([
                'status' => 'Somente uma OS aprovada pode iniciar a execução.',
            ]);
        }

        DB::transaction(function () use ($request, $ordem): void {
            $ordem->forceFill([
                'status' => 'em_execucao',
                'execution_started_at' => now(),
                'execution_started_by_id' => $request->user()?->id,
            ])->save();

            $ordem->analises()->create([
                'user_id' => $request->user()?->id,
                'tipo' => 'execucao',
                'decisao' => 'iniciada',
                'observacao' => 'Execução da OS iniciada.',
            ]);
        });

        $this->notifyOrderParticipants(
            $ordem,
            $request->user(),
            'Execução da OS iniciada',
            'A ordem de serviço foi liberada para execução.'
        );

        return back()->with('success', 'Execução da OS iniciada.');
    }

    public function complete(Request $request, Tenant $tenant, OrdemServico $ordem): RedirectResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);

        if ($ordem->status !== 'em_execucao') {
            throw ValidationException::withMessages([
                'status' => 'Somente uma OS em execução pode ser concluída.',
            ]);
        }

        $validated = $request->validate([
            'completion_summary' => ['required', 'string', 'max:10000'],
            'evidencias' => ['required', 'array', 'min:1', 'max:20'],
            'evidencias.*' => ['file', 'max:30720'],
        ]);

        DB::transaction(function () use ($request, $tenant, $ordem, $validated): void {
            $ordem->forceFill([
                'status' => 'concluida',
                'completed_at' => now(),
                'completed_by_id' => $request->user()?->id,
                'completion_summary' => $validated['completion_summary'],
            ])->save();

            foreach ($request->file('evidencias', []) as $file) {
                $path = $file->store("tenant-{$tenant->id}/ordens-servico/os-{$ordem->id}/conclusao", 'public');

                $ordem->documentos()->create([
                    'uploaded_by_id' => $request->user()?->id,
                    'categoria' => 'conclusao',
                    'nome_original' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize() ?: 0,
                ]);
            }

            $ordem->analises()->create([
                'user_id' => $request->user()?->id,
                'tipo' => 'execucao',
                'decisao' => 'concluida',
                'observacao' => $validated['completion_summary'],
            ]);
        });

        $this->notifyOrderParticipants(
            $ordem,
            $request->user(),
            'Ordem de serviço concluída',
            'A execução foi encerrada com resumo e evidências finais.'
        );

        return back()->with('success', 'OS concluída e evidências registradas.');
    }

    public function cancel(Request $request, Tenant $tenant, OrdemServico $ordem): RedirectResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);

        if (! in_array($ordem->status, ['rascunho', 'aprovada', 'em_execucao'], true)) {
            throw ValidationException::withMessages([
                'status' => 'A OS não pode ser cancelada neste estágio.',
            ]);
        }

        if ($this->hasMeasuredItems($ordem)) {
            throw ValidationException::withMessages([
                'status' => 'A OS possui medição aprovada e não pode ser cancelada.',
            ]);
        }

        $validated = $request->validate([
            'motivo' => ['required', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($request, $ordem, $validated): void {
            $ordem->forceFill([
                'status' => 'cancelada',
                'cancelled_at' => now(),
                'cancelled_by_id' => $request->user()?->id,
                'cancellation_reason' => $validated['motivo'],
            ])->save();

            $ordem->analises()->create([
                'user_id' => $request->user()?->id,
                'tipo' => 'execucao',
                'decisao' => 'cancelada',
                'observacao' => $validated['motivo'],
            ]);
        });

        $this->notifyOrderParticipants(
            $ordem,
            $request->user(),
            'Ordem de serviço cancelada',
            $validated['motivo']
        );

        return back()->with('success', 'OS cancelada. O histórico foi preservado.');
    }

    public function reject(Request $request, Tenant $tenant, OrdemServico $ordem): RedirectResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);

        if (! in_array($ordem->status, ['em_analise', 'em_aprovacao'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Somente OS em análise ou aprovação podem ser devolvidas para correção.',
            ]);
        }

        $stage = $ordem->status === 'em_analise' ? 'analise' : 'aprovacao';
        $stageLabel = $stage === 'analise' ? 'análise' : 'aprovação';
        $responsibility = $stage === 'analise' ? 'fiscal' : 'aprovador';

        $this->authorizeOrdemAction($request, $tenant, $ordem, $responsibility);

        $validated = $request->validate([
            'motivo' => ['required', 'string', 'max:5000'],
        ]);

        $ordem->loadMissing([
            'creator',
            'submittedBy',
            'analyzedBy',
            'approvalDecidedBy',
            'responsaveis.user',
        ]);

        $actor = $request->user();
        $notifiables = $this->responsaveisDaObra($tenant, $ordem, 'fiscal')
            ->merge($this->responsaveisDaObra($tenant, $ordem, 'aprovador'))
            ->merge($ordem->responsaveis->pluck('user'))
            ->push($ordem->creator)
            ->push($ordem->submittedBy)
            ->push($ordem->analyzedBy)
            ->push($ordem->approvalDecidedBy)
            ->filter()
            ->unique('id')
            ->values();

        DB::transaction(function () use ($actor, $ordem, $stage, $validated): void {
            $ordem->analises()->create([
                'user_id' => $actor?->id,
                'tipo' => $stage,
                'decisao' => 'reprovada',
                'observacao' => $validated['motivo'],
            ]);

            $ordem->forceFill([
                'status' => 'rascunho',
                'submitted_for_review_at' => null,
                'submitted_for_review_by_id' => null,
                'analyzed_at' => null,
                'analyzed_by_id' => null,
                'analysis_observation' => null,
                'approval_decided_at' => null,
                'approval_decided_by_id' => null,
                'approval_observation' => null,
            ])->save();
        });

        $ordem->refresh()->loadMissing(['tenant', 'contract', 'obra', 'creator']);

        if ($actor) {
            $notifiables->each(fn (User $user) => $user->notify(
                new OrdemServicoReturnedForCorrectionNotification(
                    $ordem,
                    $actor,
                    $stageLabel,
                    $validated['motivo']
                )
            ));
        }

        return back()->with(
            'success',
            "OS devolvida para correção durante a {$stageLabel}. Os responsáveis foram notificados."
        );
    }

    public function storeComment(Request $request, Tenant $tenant, OrdemServico $ordem): RedirectResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);
        $contract = Contract::query()->where('tenant_id', $tenant->id)->findOrFail($ordem->contract_id);
        abort_unless(OrdemServicoPermissions::can($request->user(), $tenant, OrdemServicoPermissions::VIEW, $contract), 403);

        $validated = $request->validate([
            'tipo' => ['required', Rule::in(['comentario', 'pendencia'])],
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer'],
            'mention_user_ids' => ['nullable', 'array', 'max:20'],
            'mention_user_ids.*' => ['integer', Rule::exists('users', 'id')],
            'anexos' => ['nullable', 'array', 'max:10'],
            'anexos.*' => ['file', 'max:30720'],
        ]);

        $parent = null;
        if (! empty($validated['parent_id'])) {
            $parent = OrdemServicoComentario::query()
                ->where('ordem_servico_id', $ordem->id)
                ->whereNull('parent_id')
                ->findOrFail($validated['parent_id']);
        }

        $mentionIds = collect($validated['mention_user_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        foreach ($mentionIds as $userId) {
            abort_unless(
                $this->userHasContractAccess($tenant, (int) $ordem->contract_id, $userId),
                422,
                'Mencione apenas usuários vinculados ao contrato.'
            );
        }

        $comment = DB::transaction(function () use ($request, $tenant, $ordem, $validated, $parent, $mentionIds): OrdemServicoComentario {
            $comment = $ordem->comentarios()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $request->user()?->id,
                'parent_id' => $parent?->id,
                'tipo' => $parent?->tipo ?? $validated['tipo'],
                'body' => $validated['body'],
                'status' => 'aberta',
            ]);

            $comment->mentions()->sync($mentionIds->all());

            foreach ($request->file('anexos', []) as $file) {
                $path = $file->store("tenant-{$tenant->id}/ordens-servico/os-{$ordem->id}/comentarios/{$comment->id}", 'public');

                $ordem->documentos()->create([
                    'uploaded_by_id' => $request->user()?->id,
                    'categoria' => 'comentario',
                    'comentario_id' => $comment->id,
                    'nome_original' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize() ?: 0,
                ]);
            }

            return $comment;
        });

        $recipients = User::query()
            ->whereIn('id', $mentionIds)
            ->get()
            ->merge($parent?->user ? collect([$parent->user]) : collect())
            ->filter(fn (User $user): bool => $user->id !== $request->user()?->id)
            ->unique('id');

        $recipients->each(fn (User $user) => $user->notify(
            new OrdemServicoCommentNotification($ordem, $comment, $request->user())
        ));

        return back()->with('success', $comment->tipo === 'pendencia' ? 'Pendência registrada.' : 'Comentário adicionado.');
    }

    public function resolveComment(
        Request $request,
        Tenant $tenant,
        OrdemServico $ordem,
        OrdemServicoComentario $comentario
    ): RedirectResponse {
        $this->ensureTenantOrdem($tenant, $ordem);
        abort_unless((int) $comentario->ordem_servico_id === (int) $ordem->id, 404);
        abort_unless($comentario->tipo === 'pendencia', 422);

        $contract = Contract::query()->where('tenant_id', $tenant->id)->findOrFail($ordem->contract_id);
        $comentario->loadMissing('mentions:id');
        $canResolve = (int) $comentario->user_id === (int) $request->user()?->id
            || $comentario->mentions->contains('id', $request->user()?->id)
            || $ordem->responsaveis()->where('user_id', $request->user()?->id)->exists()
            || OrdemServicoPermissions::can($request->user(), $tenant, OrdemServicoPermissions::EXECUTE, $contract)
            || OrdemServicoPermissions::can($request->user(), $tenant, OrdemServicoPermissions::COMPLETE, $contract);

        abort_unless($canResolve, 403);

        $comentario->forceFill([
            'status' => $comentario->status === 'resolvida' ? 'aberta' : 'resolvida',
            'resolved_at' => $comentario->status === 'resolvida' ? null : now(),
            'resolved_by_id' => $comentario->status === 'resolvida' ? null : $request->user()?->id,
        ])->save();

        return back()->with('success', $comentario->status === 'resolvida' ? 'Pendência resolvida.' : 'Pendência reaberta.');
    }

    public function downloadDocument(
        Request $request,
        Tenant $tenant,
        OrdemServico $ordem,
        OrdemServicoDocumento $documento
    ): StreamedResponse {
        $this->ensureTenantOrdem($tenant, $ordem);
        abort_unless((int) $documento->ordem_servico_id === (int) $ordem->id, 404);
        abort_unless(Storage::disk('public')->exists($documento->path), 404);

        return Storage::disk('public')->download($documento->path, $documento->nome_original);
    }

    public function pdf(Request $request, Tenant $tenant, OrdemServico $ordem): HttpResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);
        abort_unless($ordem->status === 'concluida', 422, 'O PDF final fica disponível após a conclusão da OS.');

        $ordem->load([
            'contract:id,code,name',
            'obra:id,codigo,nome',
            'gerenciadoraEmpresa:id,nome,sigla,logo_path',
            'construtoraEmpresa:id,nome,sigla,logo_path',
            'creator:id,name,email',
            'executionStartedBy:id,name,email',
            'completedBy:id,name,email',
            'itens.medicaoItem:id,item,codigo,descricao,unidade',
            'responsaveis.user:id,name,email',
            'documentos:id,ordem_servico_id,uploaded_by_id,categoria,nome_original,size',
            'analises.user:id,name,email',
            'comentarios' => fn ($query) => $query->whereNull('parent_id')->oldest('id'),
            'comentarios.user:id,name,email',
            'comentarios.replies.user:id,name,email',
        ]);

        $pdf = Pdf::loadView('pdf.ordem-servico', [
            'ordem' => $ordem,
            'generatedAt' => now(),
        ])->setPaper('a4');

        return $pdf->download("{$ordem->codigo}.pdf");
    }

    public function responsaveis(Request $request, Tenant $tenant): Response
    {
        $contractQuery = $tenant->contracts()->orderBy('code');
        $permissionContractIds = OrdemServicoPermissions::contractIdsFor(
            $request->user(),
            $tenant,
            OrdemServicoPermissions::RESPONSIBLES
        );

        if ($permissionContractIds !== null) {
            $contractQuery->whereIn('contracts.id', $permissionContractIds);
        }

        $contractModels = $contractQuery->get(['id', 'code', 'name']);
        $requestedContractId = $request->integer('contract_id');
        $selectedContractId = ($contractModels->firstWhere('id', $requestedContractId) ?? $contractModels->first())?->id;

        $contracts = $contractModels
            ->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->name,
            ]);

        $responsaveis = OrdemServicoObraResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->when($selectedContractId, fn ($query) => $query->where('contract_id', $selectedContractId))
            ->with(['contract:id,code,name', 'obra:id,codigo,nome', 'user:id,name,email,avatar_url'])
            ->orderBy('tipo')
            ->latest('id')
            ->get()
            ->map(fn (OrdemServicoObraResponsavel $responsavel): array => [
                'id' => $responsavel->id,
                'tipo' => $responsavel->tipo,
                'tipo_label' => $responsavel->tipo === 'aprovador' ? 'Aprovador' : 'Fiscal',
                'contract' => $responsavel->contract ? [
                    'id' => $responsavel->contract->id,
                    'code' => $responsavel->contract->code,
                    'name' => $responsavel->contract->name,
                ] : null,
                'obra' => $responsavel->obra ? [
                    'id' => $responsavel->obra->id,
                    'codigo' => $responsavel->obra->codigo,
                    'nome' => $responsavel->obra->nome,
                ] : null,
                'user' => $responsavel->user ? [
                    'id' => $responsavel->user->id,
                    'name' => $responsavel->user->name,
                    'email' => $responsavel->user->email,
                    'avatar_url' => $responsavel->user->avatar_url,
                ] : null,
            ]);

        return Inertia::render('Tenant/OrdemServico/Responsaveis', [
            'selectedContractId' => $selectedContractId,
            'contracts' => $contracts,
            'obras' => $this->obraOptions($tenant, $selectedContractId),
            'users' => $this->userOptions($tenant, $selectedContractId),
            'responsaveis' => $responsaveis,
        ]);
    }

    public function settings(Request $request, Tenant $tenant): Response
    {
        $contractQuery = $tenant->contracts()->orderBy('code');
        $permissionContractIds = OrdemServicoPermissions::contractIdsFor(
            $request->user(),
            $tenant,
            OrdemServicoPermissions::SETTINGS
        );

        if ($permissionContractIds !== null) {
            $contractQuery->whereIn('contracts.id', $permissionContractIds);
        }

        $contractModels = $contractQuery->get(['id', 'code', 'name']);
        $requestedContractId = $request->integer('contract_id');
        $selectedContractId = ($contractModels->firstWhere('id', $requestedContractId) ?? $contractModels->first())?->id;
        $setting = $selectedContractId
            ? OrdemServicoContractSetting::query()->firstOrNew([
                'tenant_id' => $tenant->id,
                'contract_id' => $selectedContractId,
            ])
            : null;

        return Inertia::render('Tenant/OrdemServico/Settings', [
            'selectedContractId' => $selectedContractId,
            'contracts' => $contractModels->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->name,
            ]),
            'requirements' => $this->serializeRequirements($setting),
        ]);
    }

    public function updateRequirements(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'contract_id' => ['required', Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id)],
            'require_project' => ['required', 'boolean'],
            'require_document' => ['required', 'boolean'],
            'require_deadline' => ['required', 'boolean'],
            'require_execution_responsible' => ['required', 'boolean'],
        ]);

        $contract = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($validated['contract_id']);

        abort_unless(
            OrdemServicoPermissions::can($request->user(), $tenant, OrdemServicoPermissions::SETTINGS, $contract),
            403
        );

        $setting = OrdemServicoContractSetting::query()->firstOrNew([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
        ]);

        $setting->fill([
            'require_project' => (bool) $validated['require_project'],
            'require_document' => (bool) $validated['require_document'],
            'require_deadline' => (bool) $validated['require_deadline'],
            'require_execution_responsible' => (bool) $validated['require_execution_responsible'],
            'updated_by_id' => $request->user()?->id,
        ]);

        if (! $setting->exists) {
            $setting->created_by_id = $request->user()?->id;
        }

        $setting->save();

        return back()->with('success', 'Parametrização da OS atualizada para este contrato.');
    }

    public function storeResponsavel(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'contract_id' => ['required', Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id)],
            'obra_id' => ['required', Rule::exists('obras', 'id')->where('tenant_id', $tenant->id)],
            'user_id' => ['required', Rule::exists('users', 'id')],
            'tipo' => ['required', Rule::in(['fiscal', 'aprovador'])],
        ]);

        abort_unless(
            Obra::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $validated['contract_id'])
                ->whereKey($validated['obra_id'])
                ->exists(),
            422,
            'A obra selecionada não pertence ao contrato.'
        );

        abort_unless(
            $this->userHasContractAccess($tenant, (int) $validated['contract_id'], (int) $validated['user_id']),
            422,
            'O usuário selecionado não possui vínculo com este contrato.'
        );

        $responsavel = OrdemServicoObraResponsavel::withTrashed()->firstOrNew([
            'tenant_id' => $tenant->id,
            'obra_id' => $validated['obra_id'],
            'user_id' => $validated['user_id'],
            'tipo' => $validated['tipo'],
        ]);

        $responsavel->fill([
            'contract_id' => $validated['contract_id'],
            'created_by_id' => $request->user()?->id,
            'status' => 'active',
        ]);

        if ($responsavel->trashed()) {
            $responsavel->restore();
        }

        $responsavel->save();

        return back()->with('success', 'Responsável da obra cadastrado com sucesso.');
    }

    public function destroyResponsavel(Tenant $tenant, OrdemServicoObraResponsavel $responsavel): RedirectResponse
    {
        abort_unless($responsavel->tenant_id === $tenant->id, 404);

        $responsavel->forceFill(['status' => 'inactive'])->save();
        $responsavel->delete();

        return back()->with('success', 'Responsável removido da obra.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateOrderPayload(Request $request, Tenant $tenant): array
    {
        return $request->validate([
            'contract_id' => ['required', Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id)],
            'obra_id' => ['required', Rule::exists('obras', 'id')->where('tenant_id', $tenant->id)],
            'project_document_id' => ['nullable', Rule::exists('project_documents', 'id')->where('tenant_id', $tenant->id)],
            'project_document_ids' => ['nullable', 'array'],
            'project_document_ids.*' => ['integer', Rule::exists('project_documents', 'id')->where('tenant_id', $tenant->id)],
            'gerenciadora_empresa_id' => ['required', Rule::exists('empresas', 'id')->where('tenant_id', $tenant->id)],
            'construtora_empresa_id' => ['required', Rule::exists('empresas', 'id')->where('tenant_id', $tenant->id)],
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'prazo_inicio' => ['nullable', 'date'],
            'prazo_finalizacao' => ['nullable', 'date', 'after_or_equal:prazo_inicio'],
            'custo_observacao' => ['nullable', 'string'],
            'item_ids' => ['array'],
            'item_ids.*' => ['integer', Rule::exists('medicao_itens', 'id')->where('tenant_id', $tenant->id)],
            'responsavel_ids' => ['nullable', 'array'],
            'responsavel_ids.*' => ['integer', Rule::exists('users', 'id')],
            'documentos' => ['array'],
            'documentos.*' => ['file', 'max:30720'],
        ], [
            'prazo_finalizacao.after_or_equal' => 'O prazo para finalização deve ser igual ou posterior ao prazo para início.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{Contract, Obra, Collection<int, int>, Collection<int, MedicaoItem>}
     */
    private function resolveOrderSelections(Tenant $tenant, array $validated): array
    {
        $contract = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($validated['contract_id']);

        $obra = Obra::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->findOrFail($validated['obra_id']);

        $requestedProjectIds = collect($validated['project_document_ids'] ?? [])
            ->when(! empty($validated['project_document_id']), fn ($collection) => $collection->push($validated['project_document_id']))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $projects = ProjectDocument::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->where('obra_id', $obra->id)
            ->whereIn('id', $requestedProjectIds)
            ->get(['id', 'obra_id']);

        abort_if(
            $projects->count() !== $requestedProjectIds->count(),
            422,
            'Um ou mais projetos selecionados nao pertencem ao contrato/obra da OS.'
        );

        foreach (['gerenciadora_empresa_id', 'construtora_empresa_id'] as $empresaField) {
            abort_unless(
                Empresa::query()
                    ->where('tenant_id', $tenant->id)
                    ->where(function ($query) use ($contract) {
                        $query->whereNull('contract_id')
                            ->orWhere('contract_id', $contract->id);
                    })
                    ->whereKey($validated[$empresaField])
                    ->exists(),
                422,
                'A empresa selecionada nao pertence ao contrato.'
            );
        }

        $requestedItemIds = collect($validated['item_ids'] ?? [])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $items = MedicaoItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->where('item_type', '!=', 'etapa')
            ->whereNotNull('codigo')
            ->where('codigo', '!=', '')
            ->whereIn('id', $requestedItemIds)
            ->get();

        abort_if(
            $items->count() !== $requestedItemIds->count(),
            422,
            'Itens de etapa/cabecalho nao podem ser vinculados a uma OS.'
        );

        return [$contract, $obra, $requestedProjectIds, $items];
    }

    /**
     * @param  Collection<int, int>  $projectIds
     * @param  Collection<int, MedicaoItem>  $items
     */
    private function syncOrderRelations(
        Request $request,
        Tenant $tenant,
        OrdemServico $ordem,
        Collection $projectIds,
        Collection $items
    ): void {
        $ordem->projectDocuments()->sync($projectIds->all());

        $responsibleIds = collect($request->input('responsavel_ids', []))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        foreach ($responsibleIds as $userId) {
            abort_unless(
                $this->userHasContractAccess($tenant, (int) $ordem->contract_id, $userId),
                422,
                'Um ou mais responsáveis não possuem vínculo com o contrato.'
            );
        }

        $ordem->responsaveis()->whereNotIn('user_id', $responsibleIds->all())->delete();

        foreach ($responsibleIds as $userId) {
            $ordem->responsaveis()->updateOrCreate(
                ['user_id' => $userId],
                ['papel' => 'execucao']
            );
        }

        $itemIds = $items->pluck('id');
        $itemsToRemove = $ordem->itens();

        if ($itemIds->isNotEmpty()) {
            $itemsToRemove->whereNotIn('medicao_item_id', $itemIds);
        }

        $itemsToRemove->delete();

        foreach ($items as $item) {
            $ordem->itens()->updateOrCreate(
                ['medicao_item_id' => $item->id],
                [
                    'quantidade_solicitada' => $item->quantidade_prevista,
                    'valor_previsto' => $item->valor_total,
                ]
            );
        }

        foreach ($request->file('documentos', []) as $file) {
            $path = $file->store("tenant-{$tenant->id}/ordens-servico/os-{$ordem->id}", 'public');

            $ordem->documentos()->create([
                'uploaded_by_id' => $request->user()?->id,
                'categoria' => 'execucao',
                'nome_original' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize() ?: 0,
            ]);
        }
    }

    private function syncOrderPlannedCost(OrdemServico $ordem): void
    {
        $ordem->forceFill([
            'custo_previsto' => $ordem->itens()->sum('valor_previsto'),
        ])->save();
    }

    /**
     * @return array{string, int}
     */
    private function nextCode(Tenant $tenant, Contract $contract, Obra $obra): array
    {
        Obra::query()
            ->whereKey($obra->id)
            ->lockForUpdate()
            ->firstOrFail();

        $next = OrdemServico::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->where('obra_id', $obra->id)
            ->max('sequencial') + 1;

        $codigo = collect([$contract->code, $obra->codigo, 'OS', str_pad((string) $next, 3, '0', STR_PAD_LEFT)])
            ->map(fn (?string $part): string => mb_strtoupper((string) $part))
            ->map(fn (string $part): string => preg_replace('/\s+/', '', trim($part)) ?? '')
            ->map(fn (string $part): string => preg_replace('/[^A-Z0-9]/', '', $part) ?? '')
            ->filter()
            ->implode('-');

        return [$codigo, $next];
    }

    private function serializeOrdemAnalysisSummary(OrdemServico $ordem): array
    {
        return [
            'id' => $ordem->id,
            'codigo' => $ordem->codigo,
            'titulo' => $ordem->titulo,
            'status' => $ordem->status,
            'custo_previsto' => (float) $ordem->custo_previsto,
            'dias_desde_submissao' => $this->reviewAgeInDays($ordem),
            'contract' => $ordem->contract ? [
                'id' => $ordem->contract->id,
                'code' => $ordem->contract->code,
                'name' => $ordem->contract->name,
            ] : null,
            'obra' => $ordem->obra ? [
                'id' => $ordem->obra->id,
                'codigo' => $ordem->obra->codigo,
                'nome' => $ordem->obra->nome,
            ] : null,
            'solicitante' => $ordem->creator ? [
                'id' => $ordem->creator->id,
                'name' => $ordem->creator->name,
                'email' => $ordem->creator->email,
                'avatar_url' => $ordem->creator->avatar_url,
            ] : null,
        ];
    }

    private function serializeAnalysisProjects(Tenant $tenant, OrdemServico $ordem): array
    {
        $projects = $ordem->projectDocuments->isNotEmpty()
            ? $ordem->projectDocuments
            : collect($ordem->projectDocument ? [$ordem->projectDocument] : []);

        return $projects
            ->map(function (ProjectDocument $project) use ($tenant): array {
                $version = $project->latestApprovedVersion ?: $project->latestVersion;
                $openRncs = $project->openRncs;
                $firstOpenRnc = $openRncs->first();

                return [
                    'id' => $project->id,
                    'code' => $project->code,
                    'title' => $project->title,
                    'status' => $project->status,
                    'open_rncs_count' => $openRncs->count(),
                    'first_open_rnc' => $firstOpenRnc ? [
                        'id' => $firstOpenRnc->id,
                        'number' => $firstOpenRnc->formatted_number,
                        'url' => route('tenant.qualidade.rnc.show', [$tenant, $firstOpenRnc]),
                    ] : null,
                    'url' => $version
                        ? route('tenant.projects.viewer', [$tenant, $version]).'?workspace=visualizar&origin=ordem-servico'
                        : route('tenant.projects.index', $tenant),
                ];
            })
            ->values()
            ->all();
    }

    private function orderMeasurementSummaries(Collection $orderIds): Collection
    {
        if ($orderIds->isEmpty()) {
            return collect();
        }

        $measuredByItem = DB::table('folha_rosto_item_analises as measurement_analyses')
            ->join('folha_rosto_itens as measurement_lines', 'measurement_lines.id', '=', 'measurement_analyses.folha_rosto_item_id')
            ->join('folhas_rosto as measured_covers', 'measured_covers.id', '=', 'measurement_lines.folha_rosto_id')
            ->where('measurement_analyses.setor', 'medicao')
            ->where('measured_covers.status', 'analisada')
            ->whereNotNull('measurement_lines.ordem_servico_item_id')
            ->groupBy('measurement_lines.ordem_servico_item_id')
            ->selectRaw('measurement_lines.ordem_servico_item_id, SUM(measurement_analyses.quantidade_aprovada) as quantidade_medida');

        return DB::table('ordem_servico_itens as order_items')
            ->leftJoinSub($measuredByItem, 'measured_items', function ($join): void {
                $join->on('measured_items.ordem_servico_item_id', '=', 'order_items.id');
            })
            ->whereIn('order_items.ordem_servico_id', $orderIds)
            ->groupBy('order_items.ordem_servico_id')
            ->selectRaw(<<<'SQL'
                order_items.ordem_servico_id,
                COUNT(order_items.id) as itens_count,
                SUM(order_items.valor_previsto) as custo_previsto,
                SUM(
                    CASE
                        WHEN COALESCE(measured_items.quantidade_medida, 0) > 0 THEN 1
                        ELSE 0
                    END
                ) as itens_medidos_count,
                SUM(
                    COALESCE(measured_items.quantidade_medida, 0) *
                    CASE
                        WHEN order_items.quantidade_solicitada > 0
                            THEN order_items.valor_previsto / order_items.quantidade_solicitada
                        ELSE 0
                    END
                ) as custo_real,
                AVG(
                    CASE
                        WHEN order_items.quantidade_solicitada > 0 THEN
                            CASE
                                WHEN (COALESCE(measured_items.quantidade_medida, 0) / order_items.quantidade_solicitada) * 100 > 100
                                    THEN 100
                                ELSE (COALESCE(measured_items.quantidade_medida, 0) / order_items.quantidade_solicitada) * 100
                            END
                        ELSE 0
                    END
                ) as percentual_medido
                SQL)
            ->get()
            ->keyBy('ordem_servico_id');
    }

    private function serializeOrdemSummary(OrdemServico $ordem, ?object $measurementSummary): array
    {
        return [
            'id' => $ordem->id,
            'codigo' => $ordem->codigo,
            'titulo' => $ordem->titulo,
            'status' => $ordem->status,
            'custo_previsto' => round((float) ($measurementSummary?->custo_previsto ?? $ordem->custo_previsto), 2),
            'custo_real' => round((float) ($measurementSummary?->custo_real ?? 0), 2),
            'percentual_medido' => round((float) ($measurementSummary?->percentual_medido ?? 0), 2),
            'itens_count' => (int) ($measurementSummary?->itens_count ?? 0),
            'itens_medidos_count' => (int) ($measurementSummary?->itens_medidos_count ?? 0),
            'itens' => [],
            'contract' => $ordem->contract ? [
                'id' => $ordem->contract->id,
                'code' => $ordem->contract->code,
                'name' => $ordem->contract->name,
            ] : null,
            'obra' => $ordem->obra ? [
                'id' => $ordem->obra->id,
                'codigo' => $ordem->obra->codigo,
                'nome' => $ordem->obra->nome,
            ] : null,
            'solicitante' => $ordem->creator ? [
                'id' => $ordem->creator->id,
                'name' => $ordem->creator->name,
                'email' => $ordem->creator->email,
                'avatar_url' => $ordem->creator->avatar_url,
            ] : null,
        ];
    }

    private function serializeOrdem(OrdemServico $ordem, ?object $measurementSummary = null): array
    {
        $items = $ordem->relationLoaded('itens')
            ? $ordem->itens->map(fn (OrdemServicoItem $item): array => $this->serializeOrdemItem($item))->values()
            : collect();
        $measuredItemsCount = $items
            ->filter(fn (array $item): bool => (float) $item['quantidade_medida'] > 0)
            ->count();

        return [
            'id' => $ordem->id,
            'codigo' => $ordem->codigo,
            'sequencial' => $ordem->sequencial,
            'titulo' => $ordem->titulo,
            'descricao' => $ordem->descricao,
            'status' => $ordem->status,
            'prazo_inicio' => $ordem->prazo_inicio?->format('Y-m-d'),
            'prazo_inicio_label' => $ordem->prazo_inicio?->format('d/m/Y'),
            'prazo_finalizacao' => $ordem->prazo_finalizacao?->format('Y-m-d'),
            'prazo_finalizacao_label' => $ordem->prazo_finalizacao?->format('d/m/Y'),
            'custo_previsto' => round((float) ($measurementSummary?->custo_previsto ?? $ordem->custo_previsto), 2),
            'custo_real' => round((float) ($measurementSummary?->custo_real ?? $items->sum('custo_real')), 2),
            'percentual_medido' => round((float) ($measurementSummary?->percentual_medido ?? 0), 2),
            'itens_count' => (int) ($measurementSummary?->itens_count ?? $items->count()),
            'itens_medidos_count' => (int) ($measurementSummary?->itens_medidos_count ?? $measuredItemsCount),
            'custo_observacao' => $ordem->custo_observacao,
            'contract' => $ordem->contract ? [
                'id' => $ordem->contract->id,
                'code' => $ordem->contract->code,
                'name' => $ordem->contract->name,
            ] : null,
            'obra' => $ordem->obra ? [
                'id' => $ordem->obra->id,
                'codigo' => $ordem->obra->codigo,
                'nome' => $ordem->obra->nome,
            ] : null,
            'project' => $ordem->projectDocument ? [
                'id' => $ordem->projectDocument->id,
                'code' => $ordem->projectDocument->code,
                'title' => $ordem->projectDocument->title,
            ] : null,
            'projects' => ($ordem->relationLoaded('projectDocuments') && $ordem->projectDocuments->isNotEmpty()
                ? $ordem->projectDocuments
                : collect($ordem->projectDocument ? [$ordem->projectDocument] : []))
                ->map(fn (ProjectDocument $project): array => [
                    'id' => $project->id,
                    'code' => $project->code,
                    'title' => $project->title,
                ])
                ->values(),
            'gerenciadora_empresa' => $ordem->gerenciadoraEmpresa ? [
                'id' => $ordem->gerenciadoraEmpresa->id,
                'nome' => $ordem->gerenciadoraEmpresa->nome,
                'sigla' => $ordem->gerenciadoraEmpresa->sigla,
            ] : null,
            'construtora_empresa' => $ordem->construtoraEmpresa ? [
                'id' => $ordem->construtoraEmpresa->id,
                'nome' => $ordem->construtoraEmpresa->nome,
                'sigla' => $ordem->construtoraEmpresa->sigla,
            ] : null,
            'solicitante' => $ordem->creator ? [
                'id' => $ordem->creator->id,
                'name' => $ordem->creator->name,
                'email' => $ordem->creator->email,
                'avatar_url' => $ordem->creator->avatar_url,
            ] : null,
            'itens' => $items,
            'responsaveis' => $ordem->responsaveis->map(fn ($responsavel): array => [
                'id' => $responsavel->id,
                'user_id' => $responsavel->user_id,
                'name' => $responsavel->user?->name,
                'email' => $responsavel->user?->email,
                'avatar_url' => $responsavel->user?->avatar_url,
            ])->values(),
            'documentos' => $ordem->documentos->map(fn (OrdemServicoDocumento $documento): array => [
                'id' => $documento->id,
                'categoria' => $documento->categoria,
                'nome_original' => $documento->nome_original,
                'size' => (int) $documento->size,
                'uploader' => $documento->uploader ? [
                    'id' => $documento->uploader->id,
                    'name' => $documento->uploader->name,
                ] : null,
            ])->values(),
            'comentarios' => $ordem->relationLoaded('comentarios')
                ? $ordem->comentarios->map(fn (OrdemServicoComentario $comentario): array => $this->serializeComment($comentario))->values()
                : [],
            'submitted_for_review_at' => $ordem->submitted_for_review_at?->format('d/m/Y H:i'),
            'dias_desde_submissao' => $this->reviewAgeInDays($ordem),
            'submitted_by' => $ordem->submittedBy ? [
                'id' => $ordem->submittedBy->id,
                'name' => $ordem->submittedBy->name,
                'email' => $ordem->submittedBy->email,
            ] : null,
            'analyzed_at' => $ordem->analyzed_at?->format('d/m/Y H:i'),
            'analyzed_by' => $ordem->analyzedBy ? [
                'id' => $ordem->analyzedBy->id,
                'name' => $ordem->analyzedBy->name,
                'email' => $ordem->analyzedBy->email,
            ] : null,
            'analysis_observation' => $ordem->analysis_observation,
            'approval_decided_at' => $ordem->approval_decided_at?->format('d/m/Y H:i'),
            'approval_decided_by' => $ordem->approvalDecidedBy ? [
                'id' => $ordem->approvalDecidedBy->id,
                'name' => $ordem->approvalDecidedBy->name,
                'email' => $ordem->approvalDecidedBy->email,
            ] : null,
            'approval_observation' => $ordem->approval_observation,
            'execution_started_at' => $ordem->execution_started_at?->format('d/m/Y H:i'),
            'execution_started_by' => $ordem->executionStartedBy ? [
                'id' => $ordem->executionStartedBy->id,
                'name' => $ordem->executionStartedBy->name,
            ] : null,
            'completed_at' => $ordem->completed_at?->format('d/m/Y H:i'),
            'completed_by' => $ordem->completedBy ? [
                'id' => $ordem->completedBy->id,
                'name' => $ordem->completedBy->name,
            ] : null,
            'completion_summary' => $ordem->completion_summary,
            'cancelled_at' => $ordem->cancelled_at?->format('d/m/Y H:i'),
            'cancelled_by' => $ordem->cancelledBy ? [
                'id' => $ordem->cancelledBy->id,
                'name' => $ordem->cancelledBy->name,
            ] : null,
            'cancellation_reason' => $ordem->cancellation_reason,
            'analises' => $ordem->relationLoaded('analises') ? $ordem->analises->map(fn (OrdemServicoAnalise $analise): array => [
                'id' => $analise->id,
                'tipo' => $analise->tipo,
                'decisao' => $analise->decisao,
                'observacao' => $analise->observacao,
                'created_at' => $analise->created_at?->format('d/m/Y H:i'),
                'user' => $analise->user ? [
                    'id' => $analise->user->id,
                    'name' => $analise->user->name,
                    'email' => $analise->user->email,
                ] : null,
            ])->values() : [],
            'documentos_count' => $ordem->documentos->count(),
            'created_at' => $ordem->created_at?->format('d/m/Y H:i'),
        ];
    }

    private function serializeOrdemItem(OrdemServicoItem $item): array
    {
        $measuredQuantity = (float) ($item->quantidade_medida ?? 0);
        $actualCost = round($measuredQuantity * $this->orderItemUnitValue($item), 2);

        return [
            'id' => $item->id,
            'medicao_item_id' => $item->medicao_item_id,
            'item' => $item->medicaoItem?->item,
            'codigo' => $item->medicaoItem?->codigo,
            'descricao' => $item->medicaoItem?->descricao,
            'unidade' => $item->medicaoItem?->unidade,
            'quantidade_solicitada' => (float) $item->quantidade_solicitada,
            'quantidade_medida' => $measuredQuantity,
            'percentual_medido' => $this->measuredPercentage($item),
            'custo_real' => $actualCost,
            'valor_total' => (float) $item->valor_previsto,
            'valor_total_p0' => (float) $item->valor_previsto,
            'valor_previsto' => (float) $item->valor_previsto,
            'valor_reajustado' => $this->adjustedValue(
                (float) $item->valor_previsto,
                $item->medicaoItem
            ),
            'valor_total_reajustado' => $this->adjustedValue(
                (float) $item->valor_previsto,
                $item->medicaoItem
            ),
            'percentual_reajuste' => $this->adjustmentPercentage($item->medicaoItem),
        ];
    }

    private function serializeComment(OrdemServicoComentario $comentario): array
    {
        return [
            'id' => $comentario->id,
            'parent_id' => $comentario->parent_id,
            'tipo' => $comentario->tipo,
            'body' => $comentario->body,
            'status' => $comentario->status,
            'created_at' => $comentario->created_at?->format('d/m/Y H:i'),
            'resolved_at' => $comentario->resolved_at?->format('d/m/Y H:i'),
            'user' => $comentario->user ? [
                'id' => $comentario->user->id,
                'name' => $comentario->user->name,
                'email' => $comentario->user->email,
                'avatar_url' => $comentario->user->avatar_url,
            ] : null,
            'mentions' => $comentario->relationLoaded('mentions')
                ? $comentario->mentions->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                ])->values()
                : [],
            'attachments' => $comentario->relationLoaded('attachments')
                ? $comentario->attachments->map(fn (OrdemServicoDocumento $documento): array => [
                    'id' => $documento->id,
                    'nome_original' => $documento->nome_original,
                    'size' => (int) $documento->size,
                ])->values()
                : [],
            'replies' => $comentario->relationLoaded('replies')
                ? $comentario->replies->map(fn (OrdemServicoComentario $reply): array => $this->serializeComment($reply))->values()
                : [],
        ];
    }

    private function reviewAgeInDays(OrdemServico $ordem): int
    {
        if (! $ordem->submitted_for_review_at) {
            return 0;
        }

        return (int) max(
            0,
            $ordem->submitted_for_review_at
                ->copy()
                ->startOfDay()
                ->diffInDays(now()->startOfDay())
        );
    }

    private function withMeasuredQuantity($query)
    {
        return $query->withSum([
            'folhaRostoItemAnalises as quantidade_medida' => fn ($analysisQuery) => $analysisQuery
                ->where('setor', 'medicao')
                ->whereHas(
                    'folhaRostoItem.folhaRosto',
                    fn ($folhaQuery) => $folhaQuery->where('status', 'analisada')
                ),
        ], 'quantidade_aprovada');
    }

    private function measuredPercentage(OrdemServicoItem $item): float
    {
        $requested = (float) $item->quantidade_solicitada;

        if ($requested <= 0) {
            return 0;
        }

        return round(min(100, ((float) ($item->quantidade_medida ?? 0) / $requested) * 100), 2);
    }

    private function orderItemUnitValue(OrdemServicoItem $item): float
    {
        $requested = (float) $item->quantidade_solicitada;

        if ($requested > 0) {
            return (float) $item->valor_previsto / $requested;
        }

        $medicaoItem = $item->medicaoItem;

        if (! $medicaoItem) {
            return 0;
        }

        $unitValue = (float) ($medicaoItem->valor_com_bdi ?: $medicaoItem->valor_unitario);

        if ($unitValue > 0) {
            return $unitValue;
        }

        $contractQuantity = (float) $medicaoItem->quantidade_prevista;

        return $contractQuantity > 0
            ? (float) $medicaoItem->valor_total / $contractQuantity
            : 0;
    }

    private function obraOptions(Tenant $tenant, ?int $contractId): array
    {
        return Obra::query()
            ->where('tenant_id', $tenant->id)
            ->when($contractId, fn ($query) => $query->where('contract_id', $contractId))
            ->orderBy('codigo')
            ->get(['id', 'contract_id', 'codigo', 'nome'])
            ->map(fn (Obra $obra): array => [
                'id' => $obra->id,
                'contract_id' => $obra->contract_id,
                'label' => trim(($obra->codigo ? "{$obra->codigo} - " : '').$obra->nome),
            ])
            ->values()
            ->all();
    }

    private function projectOptions(Tenant $tenant, ?int $contractId): array
    {
        return ProjectDocument::query()
            ->where('tenant_id', $tenant->id)
            ->when($contractId, fn ($query) => $query->where('contract_id', $contractId))
            ->where('status', 'ativo')
            ->whereNull('inactive_at')
            ->orderBy('code')
            ->get(['id', 'contract_id', 'obra_id', 'code', 'title'])
            ->map(fn (ProjectDocument $project): array => [
                'id' => $project->id,
                'contract_id' => $project->contract_id,
                'obra_id' => $project->obra_id,
                'label' => trim(($project->code ? "{$project->code} - " : '').$project->title),
            ])
            ->values()
            ->all();
    }

    private function serializeItemOption(MedicaoItem $item): array
    {
        return [
            'id' => $item->id,
            'contract_id' => $item->contract_id,
            'item' => $item->item,
            'item_type' => $item->item_type,
            'planilha' => explode('.', (string) $item->item)[0] ?: null,
            'codigo' => $item->codigo,
            'descricao' => $item->descricao,
            'unidade' => $item->unidade,
            'quantidade_prevista' => (float) $item->quantidade_prevista,
            'valor_com_bdi' => (float) $item->valor_com_bdi,
            'valor_total' => (float) $item->valor_total,
            'valor_total_p0' => (float) $item->valor_total,
            'valor_total_reajustado' => $this->adjustedValue((float) $item->valor_total, $item),
            'percentual_reajuste' => $this->adjustmentPercentage($item),
            'label' => trim("{$item->item} - {$item->codigo} - {$item->descricao}"),
        ];
    }

    private function adjustedValue(float $baseValue, ?MedicaoItem $item): float
    {
        return MedicaoReajusteCalculator::adjustedValue($baseValue, $item, null, 2);
    }

    private function adjustmentPercentage(?MedicaoItem $item): float
    {
        return MedicaoReajusteCalculator::percentage($item);
    }

    private function empresaOptions(Tenant $tenant, ?int $contractId): array
    {
        return Empresa::query()
            ->with('tipoEmpresa:id,nome')
            ->where('tenant_id', $tenant->id)
            ->when($contractId, fn ($query) => $query->where(function ($subquery) use ($contractId) {
                $subquery->whereNull('contract_id')
                    ->orWhere('contract_id', $contractId);
            }))
            ->orderBy('nome')
            ->get(['id', 'tenant_id', 'contract_id', 'tipo_empresa_id', 'nome', 'sigla'])
            ->map(fn (Empresa $empresa): array => [
                'id' => $empresa->id,
                'contract_id' => $empresa->contract_id,
                'nome' => $empresa->nome,
                'sigla' => $empresa->sigla,
                'tipo_nome' => $empresa->tipoEmpresa?->nome,
                'tipo_slug' => (string) str($empresa->tipoEmpresa?->nome ?? '')->lower()->ascii()->slug('_'),
                'label' => trim(($empresa->sigla ? "{$empresa->sigla} - " : '').$empresa->nome),
            ])
            ->values()
            ->all();
    }

    private function userOptions(Tenant $tenant, ?int $contractId): array
    {
        if (! $contractId) {
            return [];
        }

        $tenantUserIds = DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->pluck('user_id');

        $contractUserIds = DB::table('contract_participants')
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contractId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->pluck('user_id');

        return User::query()
            ->whereIn('id', $tenantUserIds->merge($contractUserIds)->unique()->values())
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'avatar_url'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'label' => "{$user->name} - {$user->email}",
            ])
            ->values()
            ->all();
    }

    private function validateSubmissionRequirements(Tenant $tenant, OrdemServico $ordem): void
    {
        $settings = OrdemServicoContractSetting::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $ordem->contract_id)
            ->first();

        if (! $settings) {
            return;
        }

        $ordem->loadMissing(['projectDocuments:id', 'documentos:id,ordem_servico_id', 'responsaveis:id,ordem_servico_id']);
        $errors = [];

        if ($settings->require_project && $ordem->projectDocuments->isEmpty() && ! $ordem->project_document_id) {
            $errors['project_document_ids'] = 'Vincule ao menos um projeto antes de enviar a OS para análise.';
        }

        if ($settings->require_document && $ordem->documentos->isEmpty()) {
            $errors['documentos'] = 'Anexe ao menos um documento antes de enviar a OS para análise.';
        }

        if ($settings->require_deadline && ! $ordem->prazo_inicio) {
            $errors['prazo_inicio'] = 'Informe o prazo para início antes de enviar a OS para análise.';
        }

        if ($settings->require_deadline && ! $ordem->prazo_finalizacao) {
            $errors['prazo_finalizacao'] = 'Informe o prazo para finalização antes de enviar a OS para análise.';
        }

        if ($settings->require_execution_responsible && $ordem->responsaveis->isEmpty()) {
            $errors['responsavel_ids'] = 'Vincule ao menos um responsável pela execução antes de enviar a OS para análise.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function hasMeasuredItems(OrdemServico $ordem): bool
    {
        return $ordem->itens()
            ->whereHas('folhaRostoItemAnalises', function ($query): void {
                $query->where('setor', 'medicao')
                    ->where('quantidade_aprovada', '>', 0)
                    ->whereHas(
                        'folhaRostoItem.folhaRosto',
                        fn ($folhaQuery) => $folhaQuery->where('status', 'analisada')
                    );
            })
            ->exists();
    }

    private function notifyOrderParticipants(
        OrdemServico $ordem,
        ?User $actor,
        string $headline,
        string $bodyText
    ): void {
        if (! $actor) {
            return;
        }

        $ordem->loadMissing([
            'creator',
            'submittedBy',
            'analyzedBy',
            'approvalDecidedBy',
            'responsaveis.user',
            'tenant',
            'contract',
            'obra',
        ]);

        $recipients = $ordem->responsaveis->pluck('user')
            ->merge($this->responsaveisDaObra($ordem->tenant, $ordem, 'fiscal'))
            ->merge($this->responsaveisDaObra($ordem->tenant, $ordem, 'aprovador'))
            ->push($ordem->creator)
            ->push($ordem->submittedBy)
            ->push($ordem->analyzedBy)
            ->push($ordem->approvalDecidedBy)
            ->filter()
            ->reject(fn (User $user): bool => (int) $user->id === (int) $actor->id)
            ->unique('id');

        $tone = $ordem->status === 'cancelada'
            ? 'danger'
            : ($ordem->status === 'concluida' ? 'success' : 'info');

        $recipients->each(fn (User $user) => $user->notify(
            new OrdemServicoLifecycleNotification($ordem, $actor, $headline, $bodyText, $tone)
        ));
    }

    /** @return array<string, bool> */
    private function serializeRequirements(?OrdemServicoContractSetting $settings): array
    {
        return [
            'require_project' => (bool) $settings?->require_project,
            'require_document' => (bool) $settings?->require_document,
            'require_deadline' => (bool) $settings?->require_deadline,
            'require_execution_responsible' => (bool) $settings?->require_execution_responsible,
        ];
    }

    private function ensureTenantOrdem(Tenant $tenant, OrdemServico $ordem): void
    {
        abort_unless($ordem->tenant_id === $tenant->id, 404);
    }

    private function authorizeOrdemAction(Request $request, Tenant $tenant, OrdemServico $ordem, string $tipo): void
    {
        abort_unless($this->canActOnOrdem($request, $tenant, $ordem, $tipo), 403);
    }

    private function canActOnOrdem(Request $request, Tenant $tenant, OrdemServico $ordem, string $tipo): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        $contract = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->find($ordem->contract_id);
        $permission = $tipo === 'aprovador'
            ? OrdemServicoPermissions::APPROVE
            : OrdemServicoPermissions::ANALYZE;

        if (! $contract || ! OrdemServicoPermissions::can($user, $tenant, $permission, $contract)) {
            return false;
        }

        if ($user->is_platform_admin) {
            return true;
        }

        $tenantRole = DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role');

        if (in_array($tenantRole, ['tenant_owner', 'tenant_admin'], true)) {
            return true;
        }

        if (! $ordem->obra_id) {
            return false;
        }

        return OrdemServicoObraResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $ordem->contract_id)
            ->where('obra_id', $ordem->obra_id)
            ->where('user_id', $user->id)
            ->where('tipo', $tipo)
            ->where('status', 'active')
            ->exists();
    }

    /** @param array<int, string> $permissions */
    private function contractIdsForAnyPermissions(Request $request, Tenant $tenant, array $permissions): ?Collection
    {
        $contractIds = collect();

        foreach ($permissions as $permission) {
            $ids = OrdemServicoPermissions::contractIdsFor($request->user(), $tenant, $permission);

            if ($ids === null) {
                return null;
            }

            $contractIds = $contractIds->merge($ids);
        }

        return $contractIds->map(fn ($id): int => (int) $id)->unique()->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function responsaveisDaObra(Tenant $tenant, OrdemServico $ordem, string $tipo): Collection
    {
        if (! $ordem->obra_id) {
            return collect();
        }

        return User::query()
            ->whereIn('id', OrdemServicoObraResponsavel::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $ordem->contract_id)
                ->where('obra_id', $ordem->obra_id)
                ->where('tipo', $tipo)
                ->where('status', 'active')
                ->select('user_id'))
            ->orderBy('name')
            ->get();
    }

    private function userHasContractAccess(Tenant $tenant, int $contractId, int $userId): bool
    {
        $isTenantUser = DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->exists();

        if ($isTenantUser) {
            return true;
        }

        return DB::table('contract_participants')
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contractId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();
    }
}

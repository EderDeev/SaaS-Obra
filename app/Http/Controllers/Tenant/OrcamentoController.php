<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\OrcamentoComposicao;
use App\Models\OrcamentoComposicaoAnaliticoItem;
use App\Models\OrcamentoComposicaoItem;
use App\Models\OrcamentoInsumo;
use App\Models\OrcamentoInsumoGrupo;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrcamentoController extends Controller
{
    private const BANKS = ['SINAPI', 'SICRO3', 'PROPRIA'];

    private const TYPES = ['material', 'labor', 'equipment', 'service'];

    private const SICRO3_CALCULATION_METHOD = 'sicro3_round_4_2';

    private const CALCULATION_METHODS = ['truncate_2', 'round_2', 'none', 'sicro3_round_4_2'];

    private const SICRO3_INSUMO_ITEM_SECTIONS = [
        'equipamentos' => ['code' => 'A', 'label' => 'Equipamentos'],
        'mao_de_obra' => ['code' => 'B', 'label' => 'Mao de obra'],
        'material' => ['code' => 'C', 'label' => 'Material'],
    ];

    private const SICRO3_COMPOSICAO_ITEM_SECTIONS = [
        'atividades_auxiliares' => ['code' => 'D', 'label' => 'Atividades Auxiliares'],
        'tempo_fixo' => ['code' => 'E', 'label' => 'Tempo Fixo'],
        'momento_transporte' => ['code' => 'F', 'label' => 'Momento de Transporte'],
    ];

    private const CSV_IMPORT_BATCH_SIZE = 500;

    private const POSTGRES_CSV_IMPORT_BATCH_SIZE = 2000;

    private const CSV_UPLOAD_MAX_KB = 102400;

    private array $composicaoPriceSummaryCache = [];

    private array $analyticItemsCache = [];

    private array $firstReferenceDateCache = [];

    private array $compositionReferenceDateStringsCache = [];

    private array $normalizedCompositionReferencesCache = [];

    private const BRAZILIAN_STATES = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO',
        'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI',
        'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    public function index(Tenant $tenant): Response
    {
        return Inertia::render('Tenant/Orcamentos/Index', [
            'tenant' => $tenant,
        ]);
    }

    public function composicoes(Request $request, Tenant $tenant): Response
    {
        $state = mb_strtoupper((string) $request->query('state', 'PA'));
        $state = in_array($state, self::BRAZILIAN_STATES, true) ? $state : 'PA';
        $perPage = (int) $request->query('perPage', 50);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
        $hasSearched = $request->boolean('searched');
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'type' => trim((string) $request->query('type', 'all')),
            'orderBy' => in_array($request->query('orderBy'), ['code', 'description', 'unit'], true) ? $request->query('orderBy') : 'code',
            'base' => in_array(mb_strtoupper((string) $request->query('base', 'SINAPI')), self::BANKS, true) ? mb_strtoupper((string) $request->query('base', 'SINAPI')) : 'SINAPI',
            'baseScope' => in_array($request->query('baseScope'), ['official', 'own'], true) ? $request->query('baseScope') : 'official',
            'state' => $state,
            'perPage' => $perPage,
        ];
        $composicoes = [];

        if ($hasSearched) {
            $query = $this->composicoesAvailableForTenant($tenant)
                ->withCount('items')
                ->withSum('items as items_preco_onerado_sum', 'preco_onerado')
                ->withSum('items as items_preco_desonerado_sum', 'preco_desonerado');

            if ($filters['baseScope'] === 'own') {
                $query
                    ->where('tenant_id', $tenant->id)
                    ->where('is_global', false)
                    ->where(function (Builder $query) use ($filters): void {
                        $query->where('uf', $filters['state'])->orWhereNull('uf');
                    });
            } else {
                $query
                    ->where('is_global', true)
                    ->where('modelo', $filters['base'])
                    ->where(function (Builder $query) use ($filters): void {
                        $query->where('uf', $filters['state'])->orWhereNull('uf');
                    });
            }

            if ($filters['search'] !== '') {
                $search = mb_strtolower($filters['search']);

                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereRaw('LOWER(descricao) LIKE ?', ['%'.$search.'%'])
                        ->orWhereRaw('LOWER(codigo) LIKE ?', ['%'.$search.'%']);
                });
            }

            if ($filters['type'] !== '' && $filters['type'] !== 'all') {
                $query->where('tipo_composicao', $filters['type']);
            }

            match ($filters['orderBy']) {
                'description' => $query->orderBy('descricao'),
                'unit' => $query->orderBy('unidade')->orderBy('descricao'),
                default => $query->orderBy('codigo'),
            };

            $composicoes = $query
                ->paginate($perPage)
                ->withQueryString();
            $summaries = $this->composicaoListPriceSummaries($tenant, $composicoes->getCollection());

            $composicoes->setCollection(
                $composicoes->getCollection()
                    ->map(fn (OrcamentoComposicao $composicao): array => $this->serializeComposicao(
                        $composicao,
                        null,
                        $summaries[$composicao->id] ?? $this->fastComposicaoPriceSummary($composicao),
                    )),
            );
        }

        return Inertia::render('Tenant/Orcamentos/Composicoes', [
            'tenant' => $tenant,
            'filters' => $filters,
            'hasSearched' => $hasSearched,
            'composicoes' => $composicoes,
            'totalComposicoes' => Cache::remember(
                "tenant:{$tenant->id}:orcamento:composicoes:total",
                now()->addMinutes(5),
                fn (): int => $this->composicoesAvailableForTenant($tenant)->count(),
            ),
            'canManageTenantComposicoes' => $this->canManageTenantInsumos($request, $tenant),
            'canManageGlobalComposicoes' => $this->canManageGlobalInsumos($request),
            'typeOptions' => Cache::remember(
                "tenant:{$tenant->id}:orcamento:composicoes:types",
                now()->addMinutes(5),
                fn (): array => $this->composicaoTypeOptions($tenant),
            ),
        ]);
    }

    public function createComposicao(Request $request, Tenant $tenant): Response
    {
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        return Inertia::render('Tenant/Orcamentos/Composicoes/Create', [
            'tenant' => $tenant,
            'options' => $this->compositionFormOptions($tenant),
        ]);
    }

    public function storeComposicao(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:50', Rule::unique('orcamento_composicoes', 'codigo')->where('tenant_id', $tenant->id)->whereNull('deleted_at')],
            'descricao' => ['required', 'string', 'max:1000'],
            'tipo_composicao' => ['required', 'string', 'max:120'],
            'unidade' => ['required', 'string', 'max:20'],
            'uf' => ['required', 'string', Rule::in(self::BRAZILIAN_STATES)],
            'modelo' => ['required', 'string', Rule::in(['SINAPI', 'SICRO3'])],
            'metodo_calculo' => ['required', 'string', Rule::in(self::CALCULATION_METHODS)],
            'producao_equipe' => ['nullable', 'string', 'max:30'],
            'adicional_mao_obra' => ['nullable', 'string', 'max:30'],
            'fator_influencia_chuvas' => ['nullable', 'string', 'max:30'],
            'observacao' => ['nullable', 'string', 'max:3000'],
            'base_references' => ['required', 'array', 'min:1'],
            'base_references.*.codigo' => ['required', 'string', 'max:40'],
            'base_references.*.nome' => ['required', 'string', 'max:80'],
            'base_references.*.uf' => ['nullable', 'string', 'max:2'],
            'base_references.*.localidade' => ['nullable', 'string', 'max:120'],
            'base_references.*.data' => ['required', 'string', 'max:20'],
        ]);

        $baseReferences = collect($data['base_references'])
            ->map(fn (array $reference): array => [
                'codigo' => trim($reference['codigo']),
                'nome' => trim($reference['nome']),
                'uf' => isset($reference['uf']) ? mb_strtoupper(trim((string) $reference['uf'])) : null,
                'localidade' => trim((string) ($reference['localidade'] ?? '')),
                'data' => trim($reference['data']),
            ])
            ->values()
            ->all();

        $model = mb_strtoupper($data['modelo']);
        $composicao = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $request->user()->id,
            'codigo' => trim($data['codigo']),
            'descricao' => trim($data['descricao']),
            'tipo_composicao' => trim($data['tipo_composicao']),
            'unidade' => mb_strtoupper(trim($data['unidade'])),
            'uf' => mb_strtoupper($data['uf']),
            'modelo' => $model,
            'metodo_calculo' => $model === 'SICRO3' ? self::SICRO3_CALCULATION_METHOD : $data['metodo_calculo'],
            'producao_equipe' => $model === 'SICRO3' ? ($this->parseDecimal($data['producao_equipe'] ?? null) ?? '1.000000') : null,
            'adicional_mao_obra' => $model === 'SICRO3' ? $this->parseDecimal($data['adicional_mao_obra'] ?? null) : null,
            'fator_influencia_chuvas' => $model === 'SICRO3' ? $this->parseDecimal($data['fator_influencia_chuvas'] ?? null) : null,
            'observacao' => $data['observacao'] ? trim($data['observacao']) : null,
            'base_references' => $baseReferences,
        ]);

        return redirect()
            ->route('tenant.orcamentos.composicoes.show', [$tenant, $composicao])
            ->with('success', 'Composicao criada.');
    }

    public function showComposicao(Tenant $tenant, OrcamentoComposicao $composicao): Response
    {
        abort_unless($composicao->tenant_id === $tenant->id || $composicao->is_global, 404);

        $composicao->load(['items' => fn ($query) => $query->orderBy('created_at')->orderBy('id')]);
        $composicao->loadCount('items');
        $composicao->loadSum('items as items_preco_onerado_sum', 'preco_onerado');
        $composicao->loadSum('items as items_preco_desonerado_sum', 'preco_desonerado');
        $detail = $this->composicaoAnaliticoDetail($tenant, $composicao);
        $summary = $this->composicaoSummaryFromDetailState($composicao, $detail)
            ?? $this->fastComposicaoPriceSummary($composicao);

        return Inertia::render('Tenant/Orcamentos/Composicoes/Show', [
            'tenant' => $tenant,
            'composicao' => $this->serializeComposicao($composicao, null, $summary),
            'detail' => $detail,
            'items' => $composicao->items->map(fn (OrcamentoComposicaoItem $item): array => $this->serializeComposicaoItem($item, $composicao))->values(),
            'insumoOptions' => $this->insumoOptionsForComposicao($tenant, $composicao),
            'composicaoOptions' => $this->composicaoOptionsForComposicao($tenant, $composicao),
            'insumoFormOptions' => [
                'grupos' => $this->insumoGrupoOptions($tenant),
                'types' => [
                    ['value' => 'material', 'label' => 'Material'],
                    ['value' => 'labor', 'label' => 'Mao de obra'],
                    ['value' => 'equipment', 'label' => 'Equipamento'],
                    ['value' => 'service', 'label' => 'Servico'],
                ],
                'classifications' => $this->insumoTypeOptions($tenant),
            ],
        ]);
    }

    public function storeComposicaoItem(Request $request, Tenant $tenant, OrcamentoComposicao $composicao): RedirectResponse
    {
        abort_unless($composicao->tenant_id === $tenant->id, 404);
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        $isSicro3Parent = $this->isSicro3Composicao($composicao);

        $data = $request->validate([
            'item_type' => ['required', Rule::in(['insumo', 'composicao'])],
            'source_id' => ['required', 'integer'],
            'coeficiente' => ['nullable', 'string', 'max:30'],
            'sicro3_section' => [
                Rule::requiredIf($isSicro3Parent && $request->input('item_type') === 'composicao'),
                'nullable',
                'string',
                Rule::in(array_keys(self::SICRO3_COMPOSICAO_ITEM_SECTIONS)),
            ],
            'sicro3_utilizacao_operativa' => ['nullable', 'string', 'max:30'],
            'sicro3_utilizacao_improdutiva' => ['nullable', 'string', 'max:30'],
            'sicro3_referenced_item_id' => ['nullable', 'integer'],
            'sicro3_transport_ln_code' => ['nullable', 'string', 'max:50'],
            'sicro3_transport_rp_code' => ['nullable', 'string', 'max:50'],
            'sicro3_transport_p_code' => ['nullable', 'string', 'max:50'],
            'sicro3_transport_fe_code' => ['nullable', 'string', 'max:50'],
            'sicro3_transport_type' => ['nullable', 'string', Rule::in(['ln', 'rp', 'p', 'fe'])],
        ]);

        $coefficient = $this->parseCoefficient($data['coeficiente'] ?? null);
        $sicro3Section = $isSicro3Parent && $data['item_type'] === 'composicao'
            ? ($data['sicro3_section'] ?? null)
            : null;
        if ($isSicro3Parent
            && $data['item_type'] === 'composicao'
            && in_array($sicro3Section, ['tempo_fixo', 'momento_transporte'], true)
            && empty($data['sicro3_referenced_item_id'])) {
            throw ValidationException::withMessages([
                'sicro3_referenced_item_id' => 'Selecione o item referenciado para esta secao SICRO3.',
            ]);
        }
        $sicro3Context = $isSicro3Parent
            ? $this->sicro3ItemContextFromPayload($data, $composicao)
            : [];

        if ($data['item_type'] === 'insumo') {
            $insumo = $this->insumosAvailableForTenant($tenant)->findOrFail($data['source_id']);
            $item = $this->itemDataFromInsumo($request, $tenant, $composicao, $insumo, $coefficient, $sicro3Context);
        } else {
            $child = OrcamentoComposicao::query()
                ->where(function (Builder $query) use ($tenant): void {
                    $query->where('tenant_id', $tenant->id)->orWhere('is_global', true);
                })
                ->whereKey($data['source_id'])
                ->where('id', '!=', $composicao->id)
                ->firstOrFail();

            if ($isSicro3Parent && $sicro3Section === 'momento_transporte') {
                if (empty($data['sicro3_transport_type'])) {
                    throw ValidationException::withMessages([
                        'sicro3_transport_type' => 'Selecione o tipo da composicao de transporte.',
                    ]);
                }

                $sicro3Context = $this->withSelectedSicro3TransportComposition(
                    $sicro3Context,
                    (string) $data['sicro3_transport_type'],
                    $child,
                );
            }

            $item = $this->itemDataFromChildComposicao($request, $tenant, $composicao, $child, $coefficient, $sicro3Section, $sicro3Context);
        }

        OrcamentoComposicaoItem::create($item);
        $this->recalculateComposicaoTotals($composicao);

        return back()->with('success', 'Item adicionado a composicao.');
    }

    public function composicaoItemOptions(Request $request, Tenant $tenant, OrcamentoComposicao $composicao): JsonResponse
    {
        abort_unless($composicao->tenant_id === $tenant->id || $composicao->is_global, 404);
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        $data = $request->validate([
            'item_type' => ['required', Rule::in(['insumo', 'composicao'])],
            'base' => ['nullable', 'string', 'max:30'],
            'codigo' => ['nullable', 'string', 'max:80'],
            'descricao' => ['nullable', 'string', 'max:160'],
        ]);

        $base = filled($data['base'] ?? null) ? mb_strtoupper(trim((string) $data['base'])) : null;
        $codigo = filled($data['codigo'] ?? null) ? trim((string) $data['codigo']) : null;
        $descricao = filled($data['descricao'] ?? null) ? trim((string) $data['descricao']) : null;

        $options = $data['item_type'] === 'insumo'
            ? $this->insumoOptionsForComposicao($tenant, $composicao, $base, $codigo, $descricao)
            : $this->composicaoOptionsForComposicao($tenant, $composicao, $base, $codigo, $descricao);

        return response()->json(['options' => $options]);
    }

    public function storeComposicaoCreatedInsumo(Request $request, Tenant $tenant, OrcamentoComposicao $composicao): RedirectResponse
    {
        abort_unless($composicao->tenant_id === $tenant->id, 404);
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        $data = $request->validate([
            'codigo_insumo' => ['required', 'string', 'max:50'],
            'descricao' => ['required', 'string', 'max:1000'],
            'unidade' => ['required', 'string', 'max:20'],
            'tipo' => ['nullable', 'string', Rule::in(self::TYPES)],
            'classificacao' => ['nullable', 'string', 'max:80'],
            'grupo_id' => ['nullable', 'integer', Rule::exists('orcamento_insumo_grupos', 'id')->where('tenant_id', $tenant->id)->whereNull('deleted_at')],
            'uf' => ['required', 'string', Rule::in(self::BRAZILIAN_STATES)],
            'preco_nao_desonerado' => ['nullable', 'string', 'max:30'],
            'preco_desonerado' => ['nullable', 'string', 'max:30'],
            'custo_improdutivo_nao_desonerado' => ['nullable', 'string', 'max:30'],
            'custo_improdutivo_desonerado' => ['nullable', 'string', 'max:30'],
            'data' => ['required', 'string', 'max:10'],
            'coeficiente' => ['nullable', 'string', 'max:30'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);

        $referenceDate = $this->parseReferenceDate($data['data']);
        $classificacao = $this->normalizeClassification($data['classificacao'] ?? null);
        $tipo = $data['tipo'] ?: $this->typeFromClassification($classificacao);
        $isEquipment = $tipo === 'equipment';
        $insumo = OrcamentoInsumo::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'banco' => 'PROPRIA',
                'codigo_insumo' => trim($data['codigo_insumo']),
                'uf' => mb_strtoupper($data['uf']),
                'origem_preco' => 'PR',
                'data_referencia' => $referenceDate->toDateString(),
            ],
            [
                'created_by_id' => $request->user()->id,
                'grupo_id' => $data['grupo_id'] ?? null,
                'tipo' => $tipo,
                'classificacao' => $classificacao,
                'descricao' => trim($data['descricao']),
                'unidade' => mb_strtoupper(trim($data['unidade'])),
                'preco_nao_desonerado' => $this->parseDecimal($data['preco_nao_desonerado'] ?? null),
                'preco_desonerado' => $this->parseDecimal($data['preco_desonerado'] ?? null),
                'custo_improdutivo_nao_desonerado' => $isEquipment ? $this->parseDecimal($data['custo_improdutivo_nao_desonerado'] ?? null) : null,
                'custo_improdutivo_desonerado' => $isEquipment ? $this->parseDecimal($data['custo_improdutivo_desonerado'] ?? null) : null,
            ],
        );

        OrcamentoComposicaoItem::create(array_merge(
            $this->itemDataFromInsumo($request, $tenant, $composicao, $insumo, $this->parseCoefficient($data['coeficiente'] ?? null)),
            ['observacao' => ($data['observacao'] ?? null) ? trim($data['observacao']) : null],
        ));
        $this->recalculateComposicaoTotals($composicao);

        return back()->with('success', 'Insumo criado e adicionado a composicao.');
    }

    public function updateComposicaoItem(Request $request, Tenant $tenant, OrcamentoComposicao $composicao, OrcamentoComposicaoItem $item): RedirectResponse
    {
        abort_unless($composicao->tenant_id === $tenant->id && $item->tenant_id === $tenant->id && $item->orcamento_composicao_id === $composicao->id, 404);
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        $data = $request->validate([
            'coeficiente' => ['required', 'string', 'max:30'],
        ]);

        $coefficient = $this->parseCoefficient($data['coeficiente']);
        $section = $this->isSicro3Composicao($composicao)
            ? ($item->sicro3_section ?: $this->sicro3SectionFromInsumoItem($item))
            : null;
        $lineTotals = $section !== null || $this->isSicro3Composicao($composicao)
            ? $this->sicro3ItemLineTotals(
                $section,
                $coefficient,
                (float) ($item->preco_unitario_onerado ?? 0),
                (float) ($item->preco_unitario_desonerado ?? 0),
                $item->custo_improdutivo_onerado !== null ? (float) $item->custo_improdutivo_onerado : null,
                $item->custo_improdutivo_desonerado !== null ? (float) $item->custo_improdutivo_desonerado : null,
                $item->sicro3_utilizacao_operativa !== null ? (float) $item->sicro3_utilizacao_operativa : null,
                $item->sicro3_utilizacao_improdutiva !== null ? (float) $item->sicro3_utilizacao_improdutiva : null,
            )
            : [
                'onerado' => (float) ($item->preco_unitario_onerado ?? 0) * $coefficient,
                'desonerado' => (float) ($item->preco_unitario_desonerado ?? 0) * $coefficient,
            ];

        $item->forceFill([
            'coeficiente' => $coefficient,
            'preco_onerado' => $this->storeMoney($lineTotals['onerado']),
            'preco_desonerado' => $this->storeMoney($lineTotals['desonerado']),
        ])->save();

        $this->recalculateComposicaoTotals($composicao);

        return back()->with('success', 'Coeficiente atualizado.');
    }

    public function destroyComposicaoItem(Request $request, Tenant $tenant, OrcamentoComposicao $composicao, OrcamentoComposicaoItem $item): RedirectResponse
    {
        abort_unless($composicao->tenant_id === $tenant->id && $item->tenant_id === $tenant->id && $item->orcamento_composicao_id === $composicao->id, 404);
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        $item->delete();
        $this->recalculateComposicaoTotals($composicao);

        return back()->with('success', 'Item removido da composicao.');
    }

    public function insumos(Request $request, Tenant $tenant): Response
    {
        $hasSearched = $request->boolean('searched');
        $state = mb_strtoupper((string) $request->query('state', 'PA'));
        $state = in_array($state, self::BRAZILIAN_STATES, true) ? $state : 'PA';
        $perPage = (int) $request->query('perPage', 50);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'bank' => mb_strtoupper((string) $request->query('bank', 'SINAPI')),
            'orderBy' => (string) $request->query('orderBy', 'description'),
            'state' => $state,
            'type' => (string) $request->query('type', 'all'),
            'date' => (string) $request->query('date', ''),
            'perPage' => $perPage,
        ];
        $filters['bank'] = $filters['bank'] === 'TODOS' || in_array($filters['bank'], self::BANKS, true)
            ? $filters['bank']
            : 'SINAPI';

        $insumos = [
            'data' => [],
            'current_page' => 1,
            'last_page' => 1,
            'from' => null,
            'to' => null,
            'total' => 0,
            'links' => [],
        ];

        if ($hasSearched) {
            $referenceDate = $filters['date'] !== '' ? $this->parseReferenceDateOrNull($filters['date']) : null;

            $query = $this->insumosAvailableForTenant($tenant)->with('grupo');

            if ($filters['bank'] !== 'TODOS') {
                $query->where('banco', $filters['bank']);
            }

            $query->where(function (Builder $query) use ($filters): void {
                $query
                    ->where('uf', $filters['state'])
                    ->orWhere(function (Builder $query): void {
                        $query->where('banco', 'PROPRIA')->whereNull('uf');
                    });
            });

            if ($filters['type'] !== 'all') {
                $type = $filters['type'];
                $query->where(function (Builder $query) use ($type): void {
                    $query
                        ->where('classificacao', $type)
                        ->orWhere('tipo', $type);
                });
            }

            if ($referenceDate) {
                $query->whereDate('data_referencia', $referenceDate->toDateString());
            }

            if ($filters['search'] !== '') {
                $search = $filters['search'];
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('codigo_insumo', 'like', "%{$search}%")
                        ->orWhere('descricao', 'like', "%{$search}%")
                        ->orWhere('classificacao', 'like', "%{$search}%");
                });
            }

            match ($filters['orderBy']) {
                'code' => $query->orderBy('codigo_insumo')->orderBy('uf'),
                'unit' => $query->orderBy('unidade')->orderBy('descricao'),
                'price' => $query->orderByDesc('preco_nao_desonerado')->orderBy('descricao'),
                default => $query->orderBy('descricao')->orderBy('uf'),
            };

            $insumos = $query
                ->paginate($perPage)
                ->withQueryString()
                ->through(fn (OrcamentoInsumo $insumo): array => $this->serializeInsumo($insumo));
        }

        return Inertia::render('Tenant/Orcamentos/Insumos', [
            'tenant' => $tenant,
            'filters' => $filters,
            'hasSearched' => $hasSearched,
            'insumos' => $insumos,
            'totalInsumos' => OrcamentoInsumo::query()
                ->where(function (Builder $query) use ($tenant): void {
                    $query->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id);
                })
                ->count(),
            'typeOptions' => $this->insumoTypeOptions($tenant, $filters['bank']),
            'typeOptionsByBank' => $this->insumoTypeOptionsByBank($tenant),
            'dateOptions' => $this->insumoDateOptions($tenant),
            'grupoOptions' => $this->insumoGrupoOptions($tenant),
            'grupos' => $this->insumoGruposForPage($tenant),
            'canManageTenantInsumos' => $this->canManageTenantInsumos($request, $tenant),
            'canManageGlobalInsumos' => $this->canManageGlobalInsumos($request),
        ]);
    }

    public function storeInsumo(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        $data = $request->validate([
            'tipo' => ['nullable', 'string', Rule::in(self::TYPES)],
            'classificacao' => ['nullable', 'string', 'max:80'],
            'grupo_id' => ['nullable', 'integer', Rule::exists('orcamento_insumo_grupos', 'id')->where('tenant_id', $tenant->id)->whereNull('deleted_at')],
            'codigo_insumo' => ['required', 'string', 'max:50'],
            'descricao' => ['required', 'string', 'max:1000'],
            'unidade' => ['required', 'string', 'max:20'],
            'uf' => ['required', 'string', Rule::in(self::BRAZILIAN_STATES)],
            'preco_nao_desonerado' => ['nullable', 'string', 'max:30'],
            'preco_desonerado' => ['nullable', 'string', 'max:30'],
            'custo_improdutivo_nao_desonerado' => ['nullable', 'string', 'max:30'],
            'custo_improdutivo_desonerado' => ['nullable', 'string', 'max:30'],
            'data' => ['required', 'string', 'max:10'],
            'observacao' => ['nullable', 'string', 'max:3000'],
        ]);

        $referenceDate = $this->parseReferenceDate($data['data']);
        $classificacao = $this->normalizeClassification($data['classificacao'] ?? null);
        $tipo = $data['tipo'] ?: $this->typeFromClassification($classificacao);
        $isEquipment = $tipo === 'equipment';

        OrcamentoInsumo::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'banco' => 'PROPRIA',
                'codigo_insumo' => trim($data['codigo_insumo']),
                'uf' => mb_strtoupper($data['uf']),
                'origem_preco' => 'PR',
                'data_referencia' => $referenceDate->toDateString(),
            ],
            [
                'created_by_id' => $request->user()->id,
                'grupo_id' => $data['grupo_id'] ?? null,
                'tipo' => $tipo,
                'classificacao' => $classificacao,
                'descricao' => trim($data['descricao']),
                'unidade' => mb_strtoupper(trim($data['unidade'])),
                'preco_nao_desonerado' => $this->parseDecimal($data['preco_nao_desonerado'] ?? null),
                'preco_desonerado' => $this->parseDecimal($data['preco_desonerado'] ?? null),
                'custo_improdutivo_nao_desonerado' => $isEquipment ? $this->parseDecimal($data['custo_improdutivo_nao_desonerado'] ?? null) : null,
                'custo_improdutivo_desonerado' => $isEquipment ? $this->parseDecimal($data['custo_improdutivo_desonerado'] ?? null) : null,
                'observacao' => ($data['observacao'] ?? null) ? trim($data['observacao']) : null,
            ],
        );

        return back()->with('success', 'Insumo salvo.');
    }

    public function storeInsumoGrupo(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        $data = $request->validate([
            'nome' => [
                'required',
                'string',
                'max:120',
                Rule::unique('orcamento_insumo_grupos', 'nome')
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('deleted_at'),
            ],
            'descricao' => ['nullable', 'string', 'max:1000'],
        ]);

        OrcamentoInsumoGrupo::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $request->user()->id,
            'nome' => trim($data['nome']),
            'descricao' => ($data['descricao'] ?? null) ? trim($data['descricao']) : null,
        ]);

        return back()->with('success', 'Grupo de insumos criado.');
    }

    public function updateInsumoGrupo(Request $request, Tenant $tenant, OrcamentoInsumoGrupo $grupo): RedirectResponse
    {
        abort_unless($grupo->tenant_id === $tenant->id, 404);
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        $data = $request->validate([
            'nome' => [
                'required',
                'string',
                'max:120',
                Rule::unique('orcamento_insumo_grupos', 'nome')
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('deleted_at')
                    ->ignore($grupo->id),
            ],
            'descricao' => ['nullable', 'string', 'max:1000'],
        ]);

        $grupo->forceFill([
            'nome' => trim($data['nome']),
            'descricao' => ($data['descricao'] ?? null) ? trim($data['descricao']) : null,
        ])->save();

        return back()->with('success', 'Grupo de insumos atualizado.');
    }

    public function destroyInsumoGrupo(Request $request, Tenant $tenant, OrcamentoInsumoGrupo $grupo): RedirectResponse
    {
        abort_unless($grupo->tenant_id === $tenant->id, 404);
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        DB::transaction(function () use ($grupo): void {
            OrcamentoInsumo::query()
                ->where('grupo_id', $grupo->id)
                ->update(['grupo_id' => null]);

            $grupo->delete();
        });

        return back()->with('success', 'Grupo de insumos removido.');
    }

    public function importInsumos(Request $request, Tenant $tenant): RedirectResponse
    {
        $scope = (string) $request->input('scope', 'tenant');

        if ($scope === 'tenant') {
            abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

            $data = $request->validate([
                'scope' => ['required', Rule::in(['tenant'])],
                'file' => ['required', 'file', 'mimes:csv,txt,tsv', 'max:'.self::CSV_UPLOAD_MAX_KB],
                'first_item_row' => ['required', 'integer', 'min:1'],
                'last_item_row' => ['required', 'integer', 'min:1'],
                'data' => ['required', 'string', 'max:10'],
                'tipo_column' => ['required', 'string', 'max:4'],
                'codigo_column' => ['required', 'string', 'max:4'],
                'grupo_column' => ['nullable', 'string', 'max:4'],
                'descricao_column' => ['required', 'string', 'max:4'],
                'unidade_column' => ['required', 'string', 'max:4'],
                'preco_desonerado_column' => ['nullable', 'string', 'max:4'],
                'preco_nao_desonerado_column' => ['required', 'string', 'max:4'],
            ]);

            if ((int) $data['last_item_row'] < (int) $data['first_item_row']) {
                throw ValidationException::withMessages([
                    'last_item_row' => 'A linha do ultimo item deve ser maior ou igual a linha do primeiro item.',
                ]);
            }

            $result = $this->importOwnMappedCsv(
                $request->file('file')->getRealPath(),
                $tenant,
                $request->user()->id,
                $data,
            );

            return back()->with(
                'success',
                "Importacao de base propria concluida: {$result['created']} criado(s), {$result['skipped']} invalido(s) ignorado(s).",
            )->with('import_result', [
                'title' => 'Resumo da importacao de base propria',
                'scope' => 'tenant',
                'scope_label' => 'Base propria',
                'base' => 'PROPRIA',
                'read' => $result['read'],
                'created' => $result['created'],
                'updated' => $result['updated'],
                'duplicated' => $result['duplicated'],
                'skipped' => $result['skipped'],
            ]);
        }

        $data = $request->validate([
            'scope' => ['required', Rule::in(['global'])],
            'banco' => ['required', 'string', Rule::in(self::BANKS)],
            'file' => ['required', 'file', 'mimes:csv,txt,tsv', 'max:'.self::CSV_UPLOAD_MAX_KB],
            'first_item_row' => ['required', 'integer', 'min:1'],
            'last_item_row' => ['required', 'integer', 'min:1'],
            'codigo_insumo_column' => ['required', 'string', 'max:4'],
            'classificacao_column' => ['required', 'string', 'max:4'],
            'descricao_column' => ['required', 'string', 'max:4'],
            'unidade_column' => ['required', 'string', 'max:4'],
            'uf_column' => ['required', 'string', 'max:4'],
            'origem_preco_column' => ['nullable', 'string', 'max:4'],
            'preco_nao_desonerado_column' => ['required', 'string', 'max:4'],
            'preco_desonerado_column' => ['required', 'string', 'max:4'],
            'custo_improdutivo_nao_desonerado_column' => ['nullable', 'string', 'max:4'],
            'custo_improdutivo_desonerado_column' => ['nullable', 'string', 'max:4'],
            'data_column' => ['required', 'string', 'max:4'],
        ]);

        if ((int) $data['last_item_row'] < (int) $data['first_item_row']) {
            throw ValidationException::withMessages([
                'last_item_row' => 'A linha do ultimo item deve ser maior ou igual a linha do primeiro item.',
            ]);
        }

        $this->authorizeInsumoScope($request, $tenant, $data['scope']);

        $result = $this->importMappedGlobalInsumoCsv(
            $request->file('file')->getRealPath(),
            $data['scope'] === 'global' ? null : $tenant->id,
            $data['banco'],
            $request->user()->id,
            $data,
        );

        return back()->with(
            'success',
            "Importacao concluida: {$result['created']} criado(s), {$result['updated']} atualizado(s), {$result['duplicated']} duplicado(s) ignorado(s), {$result['skipped']} invalido(s) ignorado(s).",
        )->with('import_result', [
            'title' => 'Resumo da importacao de insumos',
            'scope' => $data['scope'],
            'scope_label' => $data['scope'] === 'global' ? 'Global' : 'Base propria',
            'base' => $data['banco'],
            'read' => $result['read'],
            'created' => $result['created'],
            'updated' => $result['updated'],
            'duplicated' => $result['duplicated'],
            'skipped' => $result['skipped'],
        ]);
    }

    public function importComposicoes(Request $request, Tenant $tenant): RedirectResponse
    {
        $scope = (string) $request->input('scope', 'tenant');

        if ($scope === 'tenant') {
            abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

            $data = $request->validate([
                'scope' => ['required', Rule::in(['tenant'])],
                'file' => ['required', 'file', 'mimes:csv,txt,tsv', 'max:'.self::CSV_UPLOAD_MAX_KB],
                'first_item_row' => ['required', 'integer', 'min:1'],
                'last_item_row' => ['required', 'integer', 'min:1'],
                'data' => ['required', 'string', 'max:10'],
                'fonte_column' => ['nullable', 'string', 'max:4'],
                'tipo_column' => ['nullable', 'string', 'max:4'],
                'codigo_column' => ['required', 'string', 'max:4'],
                'descricao_column' => ['required', 'string', 'max:4'],
                'unidade_column' => ['required', 'string', 'max:4'],
                'preco_unitario_column' => ['nullable', 'string', 'max:4'],
                'preco_desonerado_column' => ['nullable', 'string', 'max:4'],
                'preco_nao_desonerado_column' => ['nullable', 'string', 'max:4'],
            ]);

            if ((int) $data['last_item_row'] < (int) $data['first_item_row']) {
                throw ValidationException::withMessages([
                    'last_item_row' => 'A linha do ultimo item deve ser maior ou igual a linha do primeiro item.',
                ]);
            }

            $result = $this->importOwnMappedComposicaoCsv(
                $request->file('file')->getRealPath(),
                $tenant,
                $request->user()->id,
                $data,
            );

            return back()->with(
                'success',
                "Importacao de composicoes da base propria concluida: {$result['created']} criada(s), {$result['updated']} atualizada(s), {$result['duplicated']} duplicada(s) ignorada(s), {$result['skipped']} invalida(s) ignorada(s).",
            )->with('import_result', [
                'title' => 'Resumo da importacao de composicoes da base propria',
                'scope' => 'tenant',
                'scope_label' => 'Base propria',
                'base' => 'PROPRIA',
                'read' => $result['read'],
                'created' => $result['created'],
                'updated' => $result['updated'],
                'duplicated' => $result['duplicated'],
                'skipped' => $result['skipped'],
            ]);
        }

        $data = $request->validate([
            'scope' => ['required', Rule::in(['global'])],
            'modelo' => ['required', 'string', Rule::in(['SINAPI', 'SICRO3'])],
            'file' => ['required', 'file', 'mimes:csv,txt,tsv', 'max:'.self::CSV_UPLOAD_MAX_KB],
            'first_item_row' => ['required', 'integer', 'min:1'],
            'last_item_row' => ['required', 'integer', 'min:1'],
            'data_column' => ['required', 'string', 'max:4'],
            'fonte_column' => ['nullable', 'string', 'max:4'],
            'tipo_column' => ['nullable', 'string', 'max:4'],
            'codigo_column' => ['required', 'string', 'max:4'],
            'descricao_column' => ['required', 'string', 'max:4'],
            'unidade_column' => ['required', 'string', 'max:4'],
            'uf_column' => ['required', 'string', 'max:4'],
            'preco_unitario_column' => ['nullable', 'string', 'max:4'],
            'preco_desonerado_column' => ['nullable', 'string', 'max:4'],
            'preco_nao_desonerado_column' => ['nullable', 'string', 'max:4'],
        ]);

        if ((int) $data['last_item_row'] < (int) $data['first_item_row']) {
            throw ValidationException::withMessages([
                'last_item_row' => 'A linha do ultimo item deve ser maior ou igual a linha do primeiro item.',
            ]);
        }

        $this->authorizeInsumoScope($request, $tenant, $data['scope']);

        $result = $this->importMappedComposicaoCsv(
            $request->file('file')->getRealPath(),
            $tenant,
            $request->user()->id,
            $data,
            mb_strtoupper($data['modelo']),
            true,
        );

        return back()->with(
            'success',
            "Importacao de composicoes concluida: {$result['created']} criada(s), {$result['updated']} atualizada(s), {$result['duplicated']} duplicada(s) ignorada(s), {$result['skipped']} invalida(s) ignorada(s).",
        )->with('import_result', [
            'title' => 'Resumo da importacao de composicoes',
            'scope' => $data['scope'],
            'scope_label' => $data['scope'] === 'global' ? 'Global' : 'Base propria',
            'base' => $data['modelo'],
            'read' => $result['read'],
            'created' => $result['created'],
            'updated' => $result['updated'],
            'duplicated' => $result['duplicated'],
            'skipped' => $result['skipped'],
        ]);
    }

    public function importComposicoesAnalitico(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['tenant', 'global'])],
            'modelo' => ['required', 'string', Rule::in(self::BANKS)],
            'file' => ['required', 'file', 'mimes:csv,txt,tsv', 'max:'.self::CSV_UPLOAD_MAX_KB],
        ]);

        $this->authorizeInsumoScope($request, $tenant, $data['scope']);

        $result = $this->importComposicoesAnaliticoCsv(
            $request->file('file')->getRealPath(),
            $tenant,
            $data['modelo'],
            $data['scope'] === 'global',
            $request->user()->id,
        );

        return back()->with(
            'success',
            "Importacao analitica concluida: {$result['created']} vinculo(s) criado(s), {$result['updated']} atualizado(s), {$result['duplicated']} duplicado(s) ignorado(s), {$result['skipped']} invalido(s) ignorado(s).",
        )->with('import_result', [
            'title' => 'Resumo da importacao analitica',
            'scope' => $data['scope'],
            'scope_label' => $data['scope'] === 'global' ? 'Global' : 'Base propria',
            'base' => $data['modelo'],
            'read' => $result['read'],
            'created' => $result['created'],
            'updated' => $result['updated'],
            'duplicated' => $result['duplicated'],
            'skipped' => $result['skipped'],
        ]);
    }

    private function importComposicoesCsv(string $path, Tenant $tenant, string $model, bool $global, int $userId): array
    {
        $this->prepareLongRunningImport();

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Nao foi possivel abrir o arquivo CSV.']);
        }

        $firstLine = fgets($handle) ?: '';
        $delimiter = $this->detectCsvDelimiter($firstLine);
        rewind($handle);

        $headers = $this->readCsvHeaders($handle, $delimiter);

        $headerMap = $this->resolveCsvHeaderMap($headers, [
            'grupo' => ['grupo', 'grupo_composicao', 'tipo', 'tipo_composicao', 'classificacao', 'categoria'],
            'codigo' => ['codigo', 'cod', 'codigo_composicao', 'cod_composicao', 'codigo_servico', 'cod_servico'],
            'descricao' => ['descricao', 'desc', 'descricao_composicao', 'servico'],
            'unidade' => ['unidade', 'unid', 'un'],
            'uf' => ['uf', 'estado'],
            'preco_nao_desonerado' => ['preco_nao_desonerado', 'valor_nao_desonerado', 'preco_onerado', 'valor_onerado'],
            'preco_desonerado' => ['preco_desonerado', 'valor_desonerado'],
            'data' => ['data', 'data_referencia', 'referencia', 'mes', 'competencia'],
        ]);

        $required = ['grupo', 'codigo', 'descricao', 'unidade', 'uf', 'preco_nao_desonerado', 'preco_desonerado', 'data'];
        $missing = array_values(array_filter($required, fn (string $field): bool => ! array_key_exists($field, $headerMap)));

        if ($missing !== []) {
            fclose($handle);
            $detectedHeaders = collect($headers)
                ->map(fn ($header): string => $this->normalizeHeader((string) $header))
                ->filter()
                ->take(12)
                ->implode(', ');

            throw ValidationException::withMessages([
                'file' => 'Colunas ausentes no CSV: '.implode(', ', $missing).'. Colunas detectadas: '.($detectedHeaders ?: 'nenhuma').'.',
            ]);
        }

        $result = ['read' => 0, 'created' => 0, 'updated' => 0, 'duplicated' => 0, 'skipped' => 0];
        $batch = [];
        $seenImportKeys = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->isBlankCsvRow($row)) {
                continue;
            }

            $result['read']++;
            $payload = $this->payloadFromComposicaoCsvRow($row, $headerMap, $tenant, $model, $global, $userId);

            if ($payload === null) {
                $result['skipped']++;
                continue;
            }

            $importKey = $this->composicaoImportKey($payload, $tenant, $global);

            if (isset($seenImportKeys[$importKey])) {
                $result['duplicated']++;
                continue;
            }

            $seenImportKeys[$importKey] = true;
            $batch[] = $payload;

            if (count($batch) >= $this->csvImportBatchSize()) {
                $batchResult = $this->persistComposicaoImportBatch($batch, $tenant, $global, $userId);
                $result['created'] += $batchResult['created'];
                $result['updated'] += $batchResult['updated'];
                $batch = [];
            }
        }

        if ($batch !== []) {
            $batchResult = $this->persistComposicaoImportBatch($batch, $tenant, $global, $userId);
            $result['created'] += $batchResult['created'];
            $result['updated'] += $batchResult['updated'];
        }

        fclose($handle);

        return $result;
    }

    private function payloadFromComposicaoCsvRow(array $row, array $headerMap, Tenant $tenant, string $model, bool $global, int $userId): ?array
    {
        $value = fn (string $field): string => $this->normalizeCsvValue((string) ($row[$headerMap[$field]] ?? ''));
        $group = $value('grupo');
        $code = $value('codigo');
        $description = $value('descricao');
        $unit = mb_strtoupper($value('unidade'));
        $state = mb_strtoupper($value('uf'));
        $referenceDate = $this->parseReferenceDateOrNull($value('data'));

        if ($group === '' || $code === '' || $description === '' || $unit === '' || ! in_array($state, self::BRAZILIAN_STATES, true) || ! $referenceDate) {
            return null;
        }

        $model = mb_strtoupper($model);
        $baseReference = [
            'codigo' => "{$model}-{$state}-{$referenceDate->format('m/Y')}",
            'nome' => $model,
            'localidade' => $this->stateLabel($state).' - '.$state,
            'uf' => $state,
            'data' => $referenceDate->format('m/Y'),
        ];

        return [
            'codigo' => $code,
            'uf' => $state,
            'modelo' => $model,
            'identity' => [
                'tenant_id' => $tenant->id,
                'created_by_id' => $userId,
                'is_global' => $global,
                'codigo' => $code,
                'uf' => $state,
                'modelo' => $model,
            ],
            'attributes' => [
                'created_by_id' => $userId,
                'is_global' => $global,
                'descricao' => $description,
                'tipo_composicao' => Str::of($group)->squish()->limit(120, '')->toString(),
                'unidade' => $unit,
                'metodo_calculo' => 'truncate_2',
                'observacao' => 'Importada via CSV.',
                'base_references' => [$baseReference],
                'preco_onerado' => $this->parseDecimal($value('preco_nao_desonerado')) ?? '0.00',
                'preco_desonerado' => $this->parseDecimal($value('preco_desonerado')) ?? '0.00',
            ],
            'base_reference' => $baseReference,
        ];
    }

    private function importOwnMappedComposicaoCsv(string $path, Tenant $tenant, int $userId, array $data): array
    {
        return $this->importMappedComposicaoCsv($path, $tenant, $userId, $data, 'PROPRIA', false);
    }

    private function importMappedComposicaoCsv(string $path, Tenant $tenant, int $userId, array $data, string $model, bool $global): array
    {
        $this->prepareLongRunningImport();

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Nao foi possivel abrir o arquivo CSV.']);
        }

        $firstLine = fgets($handle) ?: '';
        $delimiter = $this->detectCsvDelimiter($firstLine);
        rewind($handle);

        $columns = $this->ownComposicaoImportColumnMap($data, $global, $global);
        $referenceDate = isset($data['data']) ? $this->parseReferenceDate($data['data']) : null;
        $firstRow = (int) $data['first_item_row'];
        $lastRow = (int) $data['last_item_row'];
        $result = ['read' => 0, 'created' => 0, 'updated' => 0, 'duplicated' => 0, 'skipped' => 0];
        $batch = [];
        $seenImportKeys = [];
        $lineNumber = 0;
        $columnsWereChecked = false;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNumber++;

            if ($lineNumber < $firstRow) {
                continue;
            }

            if ($lineNumber > $lastRow) {
                break;
            }

            if ($this->isBlankCsvRow($row)) {
                continue;
            }

            if (! $columnsWereChecked) {
                $this->assertMappedComposicaoColumnsExist($columns, $row, $data);
                $columnsWereChecked = true;
            }

            $result['read']++;
            $payload = $this->payloadFromOwnMappedComposicaoCsvRow(
                $row,
                $columns,
                $tenant,
                $userId,
                $referenceDate,
                $model,
                $global,
            );

            if ($payload === null) {
                $result['skipped']++;
                continue;
            }

            $importKey = $this->composicaoImportKey($payload, $tenant, $global);

            if (isset($seenImportKeys[$importKey])) {
                $result['duplicated']++;
                continue;
            }

            $seenImportKeys[$importKey] = true;
            $batch[] = $payload;

            if (count($batch) >= $this->csvImportBatchSize()) {
                $batchResult = $this->persistComposicaoImportBatch($batch, $tenant, $global, $userId);
                $result['created'] += $batchResult['created'];
                $result['updated'] += $batchResult['updated'];
                $batch = [];
            }
        }

        if ($batch !== []) {
            $batchResult = $this->persistComposicaoImportBatch($batch, $tenant, $global, $userId);
            $result['created'] += $batchResult['created'];
            $result['updated'] += $batchResult['updated'];
        }

        fclose($handle);

        return $result;
    }

    private function ownComposicaoImportColumnMap(array $data, bool $requireState = false, bool $requireDateColumn = false): array
    {
        $fields = [
            'fonte_column' => false,
            'tipo_column' => false,
            'codigo_column' => true,
            'descricao_column' => true,
            'unidade_column' => true,
            'uf_column' => $requireState,
            'data_column' => $requireDateColumn,
            'preco_unitario_column' => false,
            'preco_desonerado_column' => false,
            'preco_nao_desonerado_column' => false,
        ];
        $columns = [];

        foreach ($fields as $field => $required) {
            $value = mb_strtoupper(trim((string) ($data[$field] ?? '')));

            if ($value === '') {
                if ($required) {
                    throw ValidationException::withMessages([$field => 'Informe a letra da coluna.']);
                }

                continue;
            }

            $columns[$field] = $this->columnLetterToIndex($value);
        }

        return $columns;
    }

    private function assertMappedComposicaoColumnsExist(array $columns, array $row, array $data): void
    {
        $labels = [
            'fonte_column' => 'Fonte',
            'tipo_column' => 'Tipo',
            'codigo_column' => 'Codigo',
            'descricao_column' => 'Descricao',
            'unidade_column' => 'Unidade',
            'uf_column' => 'UF',
            'data_column' => 'Data de referencia',
            'preco_unitario_column' => 'Preco unitario',
            'preco_desonerado_column' => 'Preco unitario desonerado',
            'preco_nao_desonerado_column' => 'Preco unitario nao desonerado',
        ];
        $columnCount = count($row);

        foreach ($columns as $field => $index) {
            if (array_key_exists($index, $row)) {
                continue;
            }

            $letter = mb_strtoupper(trim((string) ($data[$field] ?? '')));
            $label = $labels[$field] ?? $field;

            throw ValidationException::withMessages([
                $field => "A coluna informada para {$label} ({$letter}) nao existe na primeira linha de dados. Informe a letra da coluna no Excel, como A, B, C ou E.",
                'file' => "A coluna informada para {$label} ({$letter}) nao existe na primeira linha de dados. Esta planilha possui {$columnCount} coluna(s).",
            ]);
        }
    }

    private function payloadFromOwnMappedComposicaoCsvRow(array $row, array $columns, Tenant $tenant, int $userId, ?CarbonImmutable $referenceDate, string $model = 'PROPRIA', bool $global = false): ?array
    {
        $value = function (string $field) use ($row, $columns): string {
            if (! array_key_exists($field, $columns)) {
                return '';
            }

            return $this->normalizeCsvValue((string) ($row[$columns[$field]] ?? ''));
        };

        $code = $value('codigo_column');
        $description = $value('descricao_column');
        $unit = mb_strtoupper($value('unidade_column'));
        $state = mb_strtoupper($value('uf_column'));

        if ($code === '' || $description === '' || $unit === '') {
            return null;
        }

        if ($global && ! in_array($state, self::BRAZILIAN_STATES, true)) {
            return null;
        }

        $referenceDate = $referenceDate ?: $this->parseReferenceDateOrNull($value('data_column'));

        if (! $referenceDate) {
            return null;
        }

        $model = mb_strtoupper($model);
        $source = $value('fonte_column');
        $type = $value('tipo_column') !== ''
            ? Str::of($value('tipo_column'))->squish()->limit(120, '')->toString()
            : ($global ? $model : 'Base propria');
        $unitPrice = $this->parseDecimal($value('preco_unitario_column'));
        $precoNaoDesonerado = $this->parseDecimal($value('preco_nao_desonerado_column')) ?? $unitPrice ?? '0.00';
        $precoDesonerado = $this->parseDecimal($value('preco_desonerado_column')) ?? $unitPrice ?? $precoNaoDesonerado;
        $baseName = $global ? $model : 'PROPRIA';
        $baseLabel = $global ? $model : 'Base propria';
        $baseState = $global ? $state : null;
        $baseReference = [
            'codigo' => $global
                ? "{$baseName}-{$state}-{$referenceDate->format('m/Y')}"
                : $baseName.'-'.$referenceDate->format('m/Y'),
            'nome' => $baseName,
            'localidade' => $global ? $this->stateLabel($state).' - '.$state : $baseLabel,
            'uf' => $baseState,
            'data' => $referenceDate->format('m/Y'),
        ];

        if ($source !== '') {
            $baseReference['fonte'] = Str::of($source)->squish()->limit(120, '')->toString();
        }

        return [
            'codigo' => $code,
            'uf' => $baseState,
            'modelo' => $model,
            'identity' => [
                'tenant_id' => $tenant->id,
                'created_by_id' => $userId,
                'is_global' => $global,
                'codigo' => $code,
                'uf' => $baseState,
                'modelo' => $model,
            ],
            'attributes' => [
                'created_by_id' => $userId,
                'is_global' => $global,
                'descricao' => $description,
                'tipo_composicao' => $type,
                'unidade' => $unit,
                'metodo_calculo' => $model === 'SICRO3' ? self::SICRO3_CALCULATION_METHOD : 'truncate_2',
                'observacao' => $source !== ''
                    ? 'Fonte: '.Str::of($source)->squish()->limit(180, '')->toString()
                    : ($global ? 'Importada como base global via CSV.' : 'Importada como base propria via CSV.'),
                'base_references' => [$baseReference],
                'preco_onerado' => $precoNaoDesonerado,
                'preco_desonerado' => $precoDesonerado,
                'producao_equipe' => null,
                'adicional_mao_obra' => null,
                'fator_influencia_chuvas' => null,
            ],
            'base_reference' => $baseReference,
        ];
    }

    private function persistComposicaoImportBatch(array $payloads, Tenant $tenant, bool $global, int $userId): array
    {
        if ($payloads === []) {
            return ['created' => 0, 'updated' => 0];
        }

        $existingByKey = $this->existingComposicoesForImportBatch($payloads, $tenant, $global);
        $now = now();
        $insertRows = [];
        $updated = 0;

        foreach ($payloads as $payload) {
            $key = $this->composicaoImportKey($payload, $tenant, $global);

            if (array_key_exists($key, $existingByKey)) {
                $composicao = OrcamentoComposicao::withTrashed()
                    ->whereKey($existingByKey[$key])
                    ->first();

                if ($composicao) {
                    $composicao->forceFill(array_merge($payload['attributes'], [
                        'created_by_id' => $userId,
                        'is_global' => $global,
                        'deleted_at' => null,
                    ]))->save();
                }

                $updated++;

                continue;
            }

            $insertRows[] = array_merge($payload['identity'], $payload['attributes'], [
                'base_references' => json_encode($payload['attributes']['base_references'], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
        }

        $this->insertComposicaoImportRows($insertRows);

        return [
            'created' => count($insertRows),
            'updated' => $updated,
        ];
    }

    private function existingComposicoesForImportBatch(array $payloads, Tenant $tenant, bool $global): array
    {
        if ($payloads === []) {
            return [];
        }

        $codes = array_values(array_unique(array_column($payloads, 'codigo')));
        $states = array_values(array_unique(array_filter(
            array_column($payloads, 'uf'),
            fn ($state): bool => $state !== null && $state !== '',
        )));
        $hasNullState = collect($payloads)->contains(fn (array $payload): bool => empty($payload['uf']));
        $models = array_values(array_unique(array_column($payloads, 'modelo')));

        $query = OrcamentoComposicao::withTrashed()
            ->select(['id', 'tenant_id', 'is_global', 'modelo', 'codigo', 'uf', 'base_references'])
            ->where('is_global', $global)
            ->whereIn('modelo', $models)
            ->whereIn('codigo', $codes)
            ->where(function (Builder $query) use ($states, $hasNullState): void {
                if ($states !== []) {
                    $query->whereIn('uf', $states);
                }

                if ($hasNullState) {
                    $states !== [] ? $query->orWhereNull('uf') : $query->whereNull('uf');
                }
            });

        if (! $global) {
            $query->where('tenant_id', $tenant->id);
        }

        $existing = [];

        foreach ($query->get() as $composicao) {
            foreach (($composicao->base_references ?: []) as $reference) {
                $referenceCode = (string) ($reference['codigo'] ?? '');

                if ($referenceCode === '') {
                    continue;
                }

                $key = $this->composicaoImportKeyFromParts(
                    (string) $composicao->modelo,
                    (string) $composicao->codigo,
                    (string) $composicao->uf,
                    $referenceCode,
                    $tenant,
                    $global,
                );

                $existing[$key] ??= $composicao->id;
            }
        }

        return $existing;
    }

    private function insertComposicaoImportRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $chunkSize = DB::connection()->getDriverName() === 'pgsql' ? 1000 : 50;

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            OrcamentoComposicao::insert($chunk);
        }
    }

    private function composicaoImportKey(array $payload, Tenant $tenant, bool $global): string
    {
        return $this->composicaoImportKeyFromParts(
            $payload['modelo'],
            $payload['codigo'],
            $payload['uf'],
            (string) ($payload['base_reference']['codigo'] ?? ''),
            $tenant,
            $global,
        );
    }

    private function composicaoImportKeyFromParts(string $model, string $code, ?string $state, string $referenceCode, Tenant $tenant, bool $global): string
    {
        return implode("\x1F", [
            $global ? 'global' : 'tenant:'.$tenant->id,
            mb_strtoupper($model),
            $code,
            mb_strtoupper((string) $state),
            $referenceCode,
        ]);
    }

    private function importComposicoesAnaliticoCsv(string $path, Tenant $tenant, string $model, bool $global, int $userId): array
    {
        $this->prepareLongRunningImport();

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Nao foi possivel abrir o arquivo CSV.']);
        }

        $firstLine = fgets($handle) ?: '';
        $delimiter = $this->detectCsvDelimiter($firstLine);
        rewind($handle);

        $headers = $this->readCsvHeaders($handle, $delimiter);

        $headerMap = $this->resolveCsvHeaderMap($headers, [
            'grupo' => ['grupo', 'grupo_composicao', 'categoria'],
            'codigo_composicao' => ['codigo_da', 'codigo_da_composicao', 'codigo_composicao', 'cod_composicao', 'composicao', 'codigo'],
            'tipo_item' => ['tipo_item', 'tipo_do_item', 'item_tipo', 'tipo'],
            'codigo_item' => ['codigo_do', 'codigo_do_item', 'codigo_item', 'cod_item', 'codigo_insumo', 'codigo_composicao_item'],
            'descricao' => ['descricao', 'descricao_item', 'desc'],
            'unidade' => ['unidade', 'unid', 'un'],
            'coeficiente' => ['coeficiente', 'coef'],
            'uf' => ['uf', 'estado'],
            'data' => ['data', 'data_referencia', 'referencia', 'mes', 'competencia'],
        ]);

        $required = ['codigo_composicao', 'tipo_item', 'codigo_item', 'coeficiente', 'data'];
        $missing = array_values(array_filter($required, fn (string $field): bool => ! array_key_exists($field, $headerMap)));

        if ($missing !== []) {
            fclose($handle);
            $detectedHeaders = collect($headers)
                ->map(fn ($header): string => $this->normalizeHeader((string) $header))
                ->filter()
                ->take(12)
                ->implode(', ');

            throw ValidationException::withMessages([
                'file' => 'Colunas ausentes no CSV analitico: '.implode(', ', $missing).'. Colunas detectadas: '.($detectedHeaders ?: 'nenhuma').'.',
            ]);
        }

        $result = ['read' => 0, 'created' => 0, 'updated' => 0, 'duplicated' => 0, 'skipped' => 0];
        $seenImportKeys = [];
        $batch = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->isBlankCsvRow($row)) {
                continue;
            }

            $result['read']++;
            $payload = $this->payloadFromComposicaoAnaliticoCsvRow($row, $headerMap, $model);

            if ($payload === []) {
                continue;
            }

            if ($payload === null) {
                $result['skipped']++;
                continue;
            }

            $importKey = $this->composicaoAnaliticoImportKey($payload, $tenant, $global);

            if (isset($seenImportKeys[$importKey])) {
                $result['duplicated']++;
                continue;
            }

            $seenImportKeys[$importKey] = true;
            $batch[] = $payload;

            if (count($batch) >= $this->csvImportBatchSize()) {
                $batchResult = $this->persistComposicaoAnaliticoImportBatch($batch, $tenant, $global, $userId);
                $result['created'] += $batchResult['created'];
                $result['updated'] += $batchResult['updated'];
                $batch = [];
            }
        }

        if ($batch !== []) {
            $batchResult = $this->persistComposicaoAnaliticoImportBatch($batch, $tenant, $global, $userId);
            $result['created'] += $batchResult['created'];
            $result['updated'] += $batchResult['updated'];
        }

        fclose($handle);

        return $result;
    }

    private function payloadFromComposicaoAnaliticoCsvRow(array $row, array $headerMap, string $model): ?array
    {
        $value = fn (string $field): string => array_key_exists($field, $headerMap)
            ? $this->normalizeCsvValue((string) ($row[$headerMap[$field]] ?? ''))
            : '';

        $codigoComposicao = $value('codigo_composicao');
        $rawTipoItem = $value('tipo_item');
        $tipoItem = $this->normalizeAnaliticoItemType($rawTipoItem);
        $codigoItem = $value('codigo_item');

        if ($codigoItem === '' && $rawTipoItem === '') {
            return [];
        }

        if ($codigoComposicao === '' || $codigoItem === '' || $tipoItem === null) {
            return null;
        }

        $state = mb_strtoupper($value('uf'));
        $referenceDate = $this->parseReferenceDateOrNull($value('data'));

        if (! $referenceDate) {
            return null;
        }

        return [
            'modelo' => mb_strtoupper($model),
            'grupo' => $value('grupo') !== '' ? Str::of($value('grupo'))->squish()->limit(120, '')->toString() : null,
            'codigo_composicao' => $codigoComposicao,
            'tipo_item' => $tipoItem,
            'codigo_item' => $codigoItem,
            'descricao_item' => $value('descricao') !== '' ? $value('descricao') : null,
            'unidade' => $value('unidade') !== '' ? mb_strtoupper($value('unidade')) : null,
            'uf' => in_array($state, self::BRAZILIAN_STATES, true) ? $state : null,
            'data_referencia' => $referenceDate?->toDateString(),
            'coeficiente' => $this->parseCoefficient($value('coeficiente')),
            'raw_payload' => collect($headerMap)
                ->mapWithKeys(fn (int $index, string $field): array => [$field => $this->normalizeCsvValue((string) ($row[$index] ?? ''))])
                ->all(),
        ];
    }

    private function normalizeAnaliticoItemType(string $value): ?string
    {
        $key = (string) Str::of($value)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '');

        return match ($key) {
            'I', 'INS', 'INSUMO' => 'insumo',
            'C', 'COMP', 'COMPOSICAO' => 'composicao',
            default => null,
        };
    }

    private function composicaoAnaliticoImportKey(array $payload, Tenant $tenant, bool $global): string
    {
        return $this->composicaoAnaliticoImportKeyFromParts(
            $payload['modelo'] ?? '',
            $payload['codigo_composicao'] ?? '',
            $payload['tipo_item'] ?? '',
            $payload['codigo_item'] ?? '',
            $payload['uf'] ?? null,
            $payload['data_referencia'] ?? null,
            $tenant,
            $global,
        );
    }

    private function composicaoAnaliticoImportKeyFromParts(
        string $model,
        string $compositionCode,
        string $itemType,
        string $itemCode,
        ?string $state,
        ?string $referenceDate,
        Tenant $tenant,
        bool $global,
    ): string {
        return implode("\x1F", [
            $global ? 'global' : 'tenant:'.$tenant->id,
            mb_strtoupper($model),
            $this->codeKey($compositionCode),
            $itemType,
            $this->codeKey($itemCode),
            $state ? mb_strtoupper($state) : '__NULL__',
            $referenceDate ? CarbonImmutable::parse($referenceDate)->toDateString() : '__NULL__',
        ]);
    }

    private function persistComposicaoAnaliticoImportBatch(array $payloads, Tenant $tenant, bool $global, int $userId): array
    {
        if ($payloads === []) {
            return ['created' => 0, 'updated' => 0];
        }

        $tenantId = $global ? null : $tenant->id;
        $existingByKey = $this->existingComposicaoAnaliticoItemsForImportBatch($payloads, $tenant, $global);
        $now = now();
        $insertRows = [];
        $updated = 0;

        foreach ($payloads as $payload) {
            $key = $this->composicaoAnaliticoImportKey($payload, $tenant, $global);

            if (array_key_exists($key, $existingByKey)) {
                $record = OrcamentoComposicaoAnaliticoItem::withTrashed()
                    ->whereKey($existingByKey[$key])
                    ->first();

                if ($record) {
                    if ($record->trashed()) {
                        $record->restore();
                    }

                    $record->forceFill(array_merge($payload, [
                        'tenant_id' => $tenantId,
                        'created_by_id' => $record->created_by_id ?: $userId,
                        'is_global' => $global,
                    ]))->save();
                }

                $updated++;

                continue;
            }

            $insertPayload = array_merge($payload, [
                'data_referencia' => CarbonImmutable::parse($payload['data_referencia'])->startOfDay()->toDateTimeString(),
                'tenant_id' => $tenantId,
                'created_by_id' => $userId,
                'is_global' => $global,
                'raw_payload' => json_encode($payload['raw_payload'] ?? [], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);

            $insertRows[] = $insertPayload;
        }

        $this->insertComposicaoAnaliticoImportRows($insertRows);

        return [
            'created' => count($insertRows),
            'updated' => $updated,
        ];
    }

    private function existingComposicaoAnaliticoItemsForImportBatch(array $payloads, Tenant $tenant, bool $global): array
    {
        if ($payloads === []) {
            return [];
        }

        $models = array_values(array_unique(array_column($payloads, 'modelo')));
        $compositionCodes = array_values(array_unique(array_column($payloads, 'codigo_composicao')));
        $itemTypes = array_values(array_unique(array_column($payloads, 'tipo_item')));
        $itemCodes = array_values(array_unique(array_column($payloads, 'codigo_item')));
        $dates = array_values(array_unique(array_filter(array_column($payloads, 'data_referencia'))));
        $states = array_values(array_unique(array_filter(array_column($payloads, 'uf'))));
        $hasNullState = collect($payloads)->contains(fn (array $payload): bool => empty($payload['uf']));

        $query = OrcamentoComposicaoAnaliticoItem::withTrashed()
            ->select(['id', 'tenant_id', 'is_global', 'modelo', 'codigo_composicao', 'tipo_item', 'codigo_item', 'uf', 'data_referencia'])
            ->where('is_global', $global)
            ->whereIn('modelo', $models)
            ->whereIn('tipo_item', $itemTypes);

        $this->whereNullable($query, 'tenant_id', $global ? null : $tenant->id);
        $this->whereCodeInMatches($query, 'codigo_composicao', $compositionCodes);
        $this->whereCodeInMatches($query, 'codigo_item', $itemCodes);
        $this->whereDateIn($query, 'data_referencia', $dates);

        if ($states !== [] || $hasNullState) {
            $query->where(function (Builder $query) use ($states, $hasNullState): void {
                if ($states !== []) {
                    $query->whereIn('uf', $states);
                }

                if ($hasNullState) {
                    $states === [] ? $query->whereNull('uf') : $query->orWhereNull('uf');
                }
            });
        }

        $existing = [];

        foreach ($query->get() as $record) {
            $date = $record->data_referencia?->toDateString() ?? (string) $record->getRawOriginal('data_referencia');
            $key = $this->composicaoAnaliticoImportKeyFromParts(
                (string) $record->modelo,
                (string) $record->codigo_composicao,
                (string) $record->tipo_item,
                (string) $record->codigo_item,
                $record->uf ? (string) $record->uf : null,
                $date ?: null,
                $tenant,
                $global,
            );

            $existing[$key] ??= $record->id;
        }

        return $existing;
    }

    private function insertComposicaoAnaliticoImportRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $chunkSize = DB::connection()->getDriverName() === 'pgsql' ? 1000 : 50;

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            OrcamentoComposicaoAnaliticoItem::insert($chunk);
        }
    }

    private function syncAnaliticoPayloadsToComposicaoItems(array $payloads, Tenant $tenant, bool $global, int $userId): array
    {
        if ($payloads === []) {
            return [];
        }

        $parentPayloadPairs = $this->parentPayloadPairsForAnaliticoBatch($payloads, $tenant, $global);

        if ($parentPayloadPairs === []) {
            return [];
        }

        $insumoSources = $this->insumoSourcesForAnaliticoPairs($parentPayloadPairs, $tenant, $global);
        $compositionSources = $this->compositionSourcesForAnaliticoPairs($parentPayloadPairs, $tenant, $global);
        $itemRows = [];

        foreach ($parentPayloadPairs as $pair) {
            /** @var OrcamentoComposicao $parent */
            $parent = $pair['parent'];
            $payload = $pair['payload'];
            $state = $payload['uf'] ?: $parent->uf;
            $sourceKey = $this->analiticoSourceKey(
                $payload['modelo'],
                $payload['codigo_item'],
                $state,
                $payload['data_referencia'],
            );
            $source = $payload['tipo_item'] === 'insumo'
                ? ($insumoSources[$sourceKey] ?? null)
                : ($compositionSources[$sourceKey] ?? null);

            if (! $source || ($payload['tipo_item'] === 'composicao' && $source->id === $parent->id)) {
                continue;
            }

            $itemRows[] = $payload['tipo_item'] === 'insumo'
                ? $this->itemDataFromInsumoForUser($parent->tenant_id, $parent, $source, (float) $payload['coeficiente'], $userId)
                : $this->itemDataFromChildComposicaoForUser($parent->tenant_id, $parent, $source, (float) $payload['coeficiente'], $userId);
        }

        return $this->persistComposicaoItemRowsFromAnalitico($itemRows);
    }

    private function parentPayloadPairsForAnaliticoBatch(array $payloads, Tenant $tenant, bool $global): array
    {
        $models = array_values(array_unique(array_column($payloads, 'modelo')));
        $compositionCodes = array_values(array_unique(array_column($payloads, 'codigo_composicao')));
        $states = array_values(array_unique(array_filter(array_column($payloads, 'uf'))));
        $hasStateAgnosticPayload = collect($payloads)->contains(fn (array $payload): bool => empty($payload['uf']));

        $query = OrcamentoComposicao::query()
            ->where('is_global', $global)
            ->whereIn('modelo', $models)
            ->when(! $global, fn (Builder $query): Builder => $query->where('tenant_id', $tenant->id));

        $this->whereCodeInMatches($query, 'codigo', $compositionCodes);

        if (! $hasStateAgnosticPayload && $states !== []) {
            $query->whereIn('uf', $states);
        }

        $parents = $query->get();
        $payloadGroups = [];

        foreach ($payloads as $payload) {
            $key = implode("\x1F", [
                mb_strtoupper($payload['modelo']),
                $this->codeKey($payload['codigo_composicao']),
                CarbonImmutable::parse($payload['data_referencia'])->toDateString(),
            ]);
            $state = $payload['uf'] ? mb_strtoupper($payload['uf']) : '__ALL__';
            $payloadGroups[$key][$state][] = $payload;
        }

        $pairs = [];

        foreach ($parents as $parent) {
            foreach ($this->compositionReferenceDateStrings($parent) as $referenceDate) {
                $key = implode("\x1F", [
                    mb_strtoupper((string) $parent->modelo),
                    $this->codeKey((string) $parent->codigo),
                    $referenceDate,
                ]);
                $group = $payloadGroups[$key] ?? null;

                if (! $group) {
                    continue;
                }

                foreach ($group['__ALL__'] ?? [] as $payload) {
                    $pairs[] = ['parent' => $parent, 'payload' => $payload];
                }

                foreach ($group[mb_strtoupper((string) $parent->uf)] ?? [] as $payload) {
                    $pairs[] = ['parent' => $parent, 'payload' => $payload];
                }
            }
        }

        return $pairs;
    }

    private function insumoSourcesForAnaliticoPairs(array $pairs, Tenant $tenant, bool $global): array
    {
        $requirements = [];

        foreach ($pairs as $pair) {
            $payload = $pair['payload'];

            if ($payload['tipo_item'] !== 'insumo') {
                continue;
            }

            /** @var OrcamentoComposicao $parent */
            $parent = $pair['parent'];
            $requirements[] = [
                'model' => $payload['modelo'],
                'code' => $payload['codigo_item'],
                'state' => $payload['uf'] ?: $parent->uf,
                'date' => CarbonImmutable::parse($payload['data_referencia'])->toDateString(),
            ];
        }

        if ($requirements === []) {
            return [];
        }

        $query = OrcamentoInsumo::query()
            ->whereIn('banco', array_values(array_unique(array_column($requirements, 'model'))))
            ->whereIn('uf', array_values(array_unique(array_column($requirements, 'state'))));

        $this->whereCodeInMatches($query, 'codigo_insumo', array_values(array_unique(array_column($requirements, 'code'))));
        $this->whereDateIn($query, 'data_referencia', array_values(array_unique(array_column($requirements, 'date'))));

        if ($global) {
            $query->whereNull('tenant_id');
        } else {
            $query->where(function (Builder $query) use ($tenant): void {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id);
            });
        }

        $sources = [];

        foreach ($query->get() as $insumo) {
            $key = $this->analiticoSourceKey(
                (string) $insumo->banco,
                (string) $insumo->codigo_insumo,
                (string) $insumo->uf,
                $insumo->data_referencia?->toDateString() ?? (string) $insumo->getRawOriginal('data_referencia'),
            );

            if (! array_key_exists($key, $sources) || (! $global && $insumo->tenant_id === $tenant->id)) {
                $sources[$key] = $insumo;
            }
        }

        return $sources;
    }

    private function compositionSourcesForAnaliticoPairs(array $pairs, Tenant $tenant, bool $global): array
    {
        $requirements = [];

        foreach ($pairs as $pair) {
            $payload = $pair['payload'];

            if ($payload['tipo_item'] !== 'composicao') {
                continue;
            }

            /** @var OrcamentoComposicao $parent */
            $parent = $pair['parent'];
            $requirements[] = [
                'model' => $payload['modelo'],
                'code' => $payload['codigo_item'],
                'state' => $payload['uf'] ?: $parent->uf,
                'date' => CarbonImmutable::parse($payload['data_referencia'])->toDateString(),
            ];
        }

        if ($requirements === []) {
            return [];
        }

        $query = OrcamentoComposicao::query()
            ->whereIn('modelo', array_values(array_unique(array_column($requirements, 'model'))))
            ->whereIn('uf', array_values(array_unique(array_column($requirements, 'state'))));

        $this->whereCodeInMatches($query, 'codigo', array_values(array_unique(array_column($requirements, 'code'))));

        if ($global) {
            $query->where('is_global', true);
        } else {
            $query->where(function (Builder $query) use ($tenant): void {
                $query->where('tenant_id', $tenant->id)->orWhere('is_global', true);
            });
        }

        $requiredDates = array_fill_keys(array_values(array_unique(array_column($requirements, 'date'))), true);
        $sources = [];

        foreach ($query->orderByDesc('is_global')->get() as $composicao) {
            foreach ($this->compositionReferenceDateStrings($composicao) as $referenceDate) {
                if (! isset($requiredDates[$referenceDate])) {
                    continue;
                }

                $key = $this->analiticoSourceKey(
                    (string) $composicao->modelo,
                    (string) $composicao->codigo,
                    (string) $composicao->uf,
                    $referenceDate,
                );

                $sources[$key] ??= $composicao;
            }
        }

        return $sources;
    }

    private function persistComposicaoItemRowsFromAnalitico(array $itemRows): array
    {
        if ($itemRows === []) {
            return [];
        }

        $parentIds = array_values(array_unique(array_column($itemRows, 'orcamento_composicao_id')));
        $insumoIds = array_values(array_unique(array_filter(array_column($itemRows, 'orcamento_insumo_id'))));
        $childComposicaoIds = array_values(array_unique(array_filter(array_column($itemRows, 'child_composicao_id'))));
        $existingItems = [];

        $query = OrcamentoComposicaoItem::withTrashed()
            ->whereIn('orcamento_composicao_id', $parentIds)
            ->where(function (Builder $query) use ($insumoIds, $childComposicaoIds): void {
                if ($insumoIds !== []) {
                    $query->orWhere(function (Builder $query) use ($insumoIds): void {
                        $query->where('item_type', 'insumo')->whereIn('orcamento_insumo_id', $insumoIds);
                    });
                }

                if ($childComposicaoIds !== []) {
                    $query->orWhere(function (Builder $query) use ($childComposicaoIds): void {
                        $query->where('item_type', 'composicao')->whereIn('child_composicao_id', $childComposicaoIds);
                    });
                }
            });

        foreach ($query->get() as $item) {
            $existingItems[$this->composicaoItemSyncKey($item)] = $item;
        }

        $now = now();
        $insertRows = [];
        $touchedParentIds = [];

        foreach ($itemRows as $row) {
            $key = $this->composicaoItemSyncKeyFromRow($row);
            $item = $existingItems[$key] ?? null;
            $touchedParentIds[$row['orcamento_composicao_id']] = true;

            if ($item) {
                if ($item->trashed()) {
                    $item->restore();
                }

                $item->forceFill($row)->save();
                continue;
            }

            $insertRows[] = array_merge($row, [
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
        }

        if ($insertRows !== []) {
            $chunkSize = DB::connection()->getDriverName() === 'pgsql' ? 1000 : 50;

            foreach (array_chunk($insertRows, $chunkSize) as $chunk) {
                OrcamentoComposicaoItem::insert($chunk);
            }
        }

        return array_keys($touchedParentIds);
    }

    private function analiticoSourceKey(string $model, string $code, string $state, string $referenceDate): string
    {
        return implode("\x1F", [
            mb_strtoupper($model),
            $this->codeKey($code),
            mb_strtoupper($state),
            CarbonImmutable::parse($referenceDate)->toDateString(),
        ]);
    }

    private function composicaoItemSyncKey(OrcamentoComposicaoItem $item): string
    {
        return implode("\x1F", [
            $item->orcamento_composicao_id,
            $item->item_type,
            $item->item_type === 'insumo' ? $item->orcamento_insumo_id : $item->child_composicao_id,
        ]);
    }

    private function composicaoItemSyncKeyFromRow(array $row): string
    {
        return implode("\x1F", [
            $row['orcamento_composicao_id'],
            $row['item_type'],
            $row['item_type'] === 'insumo' ? $row['orcamento_insumo_id'] : $row['child_composicao_id'],
        ]);
    }

    private function upsertComposicaoAnaliticoItem(array $payload, Tenant $tenant, bool $global, int $userId): OrcamentoComposicaoAnaliticoItem
    {
        $tenantId = $global ? null : $tenant->id;
        $query = OrcamentoComposicaoAnaliticoItem::withTrashed()
            ->where('is_global', $global)
            ->where('modelo', $payload['modelo'])
            ->where('codigo_composicao', $payload['codigo_composicao'])
            ->where('tipo_item', $payload['tipo_item'])
            ->where('codigo_item', $payload['codigo_item']);

        $this->whereNullable($query, 'tenant_id', $tenantId);
        $this->whereNullable($query, 'uf', $payload['uf']);
        $this->whereNullable($query, 'data_referencia', $payload['data_referencia']);

        $record = $query->first();

        if (! $record) {
            return OrcamentoComposicaoAnaliticoItem::create(array_merge($payload, [
                'tenant_id' => $tenantId,
                'created_by_id' => $userId,
                'is_global' => $global,
            ]));
        }

        if ($record->trashed()) {
            $record->restore();
        }

        $record->forceFill(array_merge($payload, [
            'tenant_id' => $tenantId,
            'created_by_id' => $record->created_by_id ?: $userId,
            'is_global' => $global,
        ]))->save();

        return $record;
    }

    private function syncAnaliticoPayloadToComposicaoItems(array $payload, Tenant $tenant, bool $global, int $userId): array
    {
        $parents = OrcamentoComposicao::query()
            ->where('is_global', $global)
            ->where('modelo', $payload['modelo'])
            ->when(! $global, fn (Builder $query): Builder => $query->where('tenant_id', $tenant->id));

        $this->whereCodeMatches($parents, 'codigo', $payload['codigo_composicao']);

        if ($payload['uf']) {
            $parents->where('uf', $payload['uf']);
        }

        $touchedParentIds = [];

        foreach ($parents->get() as $parent) {
            $source = $payload['tipo_item'] === 'insumo'
                ? $this->findAnaliticoInsumoSource($payload, $tenant, $parent, $global)
                : $this->findAnaliticoComposicaoSource($payload, $tenant, $parent, $global);

            if (! $source) {
                continue;
            }

            $itemData = $payload['tipo_item'] === 'insumo'
                ? $this->itemDataFromInsumoForUser($parent->tenant_id, $parent, $source, (float) $payload['coeficiente'], $userId)
                : $this->itemDataFromChildComposicaoForUser($parent->tenant_id, $parent, $source, (float) $payload['coeficiente'], $userId);

            $itemQuery = OrcamentoComposicaoItem::withTrashed()
                ->where('orcamento_composicao_id', $parent->id)
                ->where('item_type', $payload['tipo_item']);

            if ($payload['tipo_item'] === 'insumo') {
                $itemQuery->where('orcamento_insumo_id', $source->id);
            } else {
                $itemQuery->where('child_composicao_id', $source->id);
            }

            $item = $itemQuery->first();

            if ($item) {
                if ($item->trashed()) {
                    $item->restore();
                }

                $item->forceFill($itemData)->save();
            } else {
                OrcamentoComposicaoItem::create($itemData);
            }

            $touchedParentIds[] = $parent->id;
        }

        return $touchedParentIds;
    }

    private function findAnaliticoInsumoSource(array $payload, Tenant $tenant, OrcamentoComposicao $parent, bool $global): ?OrcamentoInsumo
    {
        $query = OrcamentoInsumo::query()
            ->where('banco', $payload['modelo'])
            ->where('uf', $payload['uf'] ?: $parent->uf);

        $this->whereCodeMatches($query, 'codigo_insumo', $payload['codigo_item']);

        if ($global) {
            $query->whereNull('tenant_id');
        } else {
            $query->where(function (Builder $query) use ($tenant): void {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id);
            });
        }

        $referenceDate = $payload['data_referencia'] ?: $this->firstReferenceDateForComposicao($parent);

        if ($referenceDate) {
            $query->whereDate('data_referencia', $referenceDate);
        }

        if (! $global) {
            $query->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenant->id]);
        }

        return $query->orderByDesc('data_referencia')->first();
    }

    private function findAnaliticoComposicaoSource(array $payload, Tenant $tenant, OrcamentoComposicao $parent, bool $global): ?OrcamentoComposicao
    {
        $query = OrcamentoComposicao::query()
            ->where('modelo', $payload['modelo'])
            ->where('uf', $payload['uf'] ?: $parent->uf)
            ->where('id', '!=', $parent->id);

        $this->whereCodeMatches($query, 'codigo', $payload['codigo_item']);

        if ($global) {
            $query->where('is_global', true);
        } else {
            $query->where(function (Builder $query) use ($tenant): void {
                $query->where('tenant_id', $tenant->id)->orWhere('is_global', true);
            });
        }

        $referenceDate = $payload['data_referencia'] ?: $this->firstReferenceDateForComposicao($parent);
        $candidates = $query->orderByDesc('is_global')->get();

        if (! $referenceDate) {
            return $candidates->first();
        }

        return $candidates->first(
            fn (OrcamentoComposicao $composicao): bool => $this->composicaoHasReferenceDate($composicao, $referenceDate),
        );
    }

    private function composicaoHasReferenceDate(OrcamentoComposicao $composicao, string $referenceDate): bool
    {
        $targetDate = CarbonImmutable::parse($referenceDate)->toDateString();

        return in_array($targetDate, $this->compositionReferenceDateStrings($composicao), true);
    }

    private function compositionReferenceDateStrings(OrcamentoComposicao $composicao): array
    {
        if (isset($this->compositionReferenceDateStringsCache[$composicao->id])) {
            return $this->compositionReferenceDateStringsCache[$composicao->id];
        }

        return $this->compositionReferenceDateStringsCache[$composicao->id] = collect($this->normalizedCompositionReferences($composicao))
            ->map(fn (array $reference): ?string => $reference['date']?->toDateString())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function firstReferenceDateForComposicao(OrcamentoComposicao $composicao): ?string
    {
        if (array_key_exists($composicao->id, $this->firstReferenceDateCache)) {
            return $this->firstReferenceDateCache[$composicao->id];
        }

        $references = $this->normalizedCompositionReferences($composicao);
        $reference = collect($references)->first(fn (array $reference): bool => $reference['base'] === $composicao->modelo && $reference['uf'] === $composicao->uf)
            ?? $references[0]
            ?? null;

        return $this->firstReferenceDateCache[$composicao->id] = $reference && $reference['date'] ? $reference['date']->toDateString() : null;
    }

    private function whereNullable(Builder $query, string $column, mixed $value): void
    {
        $value === null ? $query->whereNull($column) : $query->where($column, $value);
    }

    private function whereDateIn(Builder $query, string $column, array $dates): void
    {
        $dates = array_values(array_unique(array_filter(array_map(
            fn ($date): ?string => $date ? CarbonImmutable::parse($date)->toDateString() : null,
            $dates,
        ))));

        if ($dates === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $query) use ($column, $dates): void {
            foreach ($dates as $index => $date) {
                $index === 0
                    ? $query->whereDate($column, $date)
                    : $query->orWhereDate($column, $date);
            }
        });
    }

    private function whereCodeMatches(Builder $query, string $column, string $code): void
    {
        $normalizedCode = $this->codeKey($code);

        $query->where(function (Builder $query) use ($column, $code, $normalizedCode): void {
            $query
                ->where($column, $code)
                ->orWhereRaw("ltrim({$column}, '0') = ?", [$normalizedCode]);
        });
    }

    private function whereCodeInMatches(Builder $query, string $column, array $codes): void
    {
        $codes = array_values(array_unique(array_filter(
            array_map(fn ($code): string => trim((string) $code), $codes),
            fn (string $code): bool => $code !== '',
        )));

        if ($codes === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $normalizedCodes = array_values(array_unique(array_map(fn (string $code): string => $this->codeKey($code), $codes)));
        $placeholders = implode(', ', array_fill(0, count($normalizedCodes), '?'));

        $query->where(function (Builder $query) use ($column, $codes, $normalizedCodes, $placeholders): void {
            $query
                ->whereIn($column, $codes)
                ->orWhereRaw("ltrim({$column}, '0') in ({$placeholders})", $normalizedCodes);
        });
    }

    private function codeKey(string $code): string
    {
        return ltrim(trim($code), '0') ?: '0';
    }

    private function importOwnMappedCsv(string $path, Tenant $tenant, int $userId, array $data): array
    {
        $this->prepareLongRunningImport();

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Nao foi possivel abrir o arquivo CSV.']);
        }

        $firstLine = fgets($handle) ?: '';
        $delimiter = $this->detectCsvDelimiter($firstLine);
        rewind($handle);

        $columns = $this->ownImportColumnMap($data);
        $referenceDate = $this->parseReferenceDate($data['data']);
        $firstRow = (int) $data['first_item_row'];
        $lastRow = (int) $data['last_item_row'];
        $groupMap = $this->ownImportGroupMap($tenant);
        $result = ['read' => 0, 'created' => 0, 'updated' => 0, 'duplicated' => 0, 'skipped' => 0];
        $batch = [];
        $lineNumber = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNumber++;

            if ($lineNumber < $firstRow) {
                continue;
            }

            if ($lastRow !== null && $lineNumber > $lastRow) {
                break;
            }

            if ($this->isBlankCsvRow($row)) {
                continue;
            }

            $result['read']++;
            $payload = $this->payloadFromOwnMappedCsvRow(
                $row,
                $columns,
                $tenant,
                $userId,
                $referenceDate->toDateString(),
                $groupMap,
            );

            if ($payload === null) {
                $result['skipped']++;
                continue;
            }

            $batch[] = $payload;

            if (count($batch) >= $this->csvImportBatchSize()) {
                $result['created'] += $this->persistOwnInsumoImportBatch($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $result['created'] += $this->persistOwnInsumoImportBatch($batch);
        }

        fclose($handle);

        return $result;
    }

    private function persistOwnInsumoImportBatch(array $payloads): int
    {
        if ($payloads === []) {
            return 0;
        }

        $now = now();
        $rows = array_map(
            fn (array $payload): array => array_merge($payload, [
                'data_referencia' => CarbonImmutable::parse($payload['data_referencia'])->startOfDay()->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            $payloads,
        );

        $this->insertInsumoImportRows($rows);

        return count($rows);
    }

    private function ownImportColumnMap(array $data): array
    {
        $fields = [
            'tipo_column' => true,
            'codigo_column' => true,
            'grupo_column' => false,
            'descricao_column' => true,
            'unidade_column' => true,
            'preco_desonerado_column' => false,
            'preco_nao_desonerado_column' => true,
        ];
        $columns = [];

        foreach ($fields as $field => $required) {
            $value = mb_strtoupper(trim((string) ($data[$field] ?? '')));

            if ($value === '') {
                if ($required) {
                    throw ValidationException::withMessages([$field => 'Informe a letra da coluna.']);
                }

                continue;
            }

            $columns[$field] = $this->columnLetterToIndex($value);
        }

        return $columns;
    }

    private function columnLetterToIndex(string $letter): int
    {
        $letter = mb_strtoupper(trim($letter));

        if (! preg_match('/^[A-Z]{1,4}$/', $letter)) {
            throw ValidationException::withMessages(['file' => "Coluna {$letter} invalida. Use letras como A, B ou AA."]);
        }

        $index = 0;

        foreach (str_split($letter) as $char) {
            $index = ($index * 26) + (ord($char) - 64);
        }

        return $index - 1;
    }

    private function ownImportGroupMap(Tenant $tenant): array
    {
        return OrcamentoInsumoGrupo::query()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->flatMap(fn (OrcamentoInsumoGrupo $grupo): array => [
                $this->groupLookupKey((string) $grupo->id) => $grupo->id,
                $this->groupLookupKey($grupo->nome) => $grupo->id,
            ])
            ->all();
    }

    private function payloadFromOwnMappedCsvRow(array $row, array $columns, Tenant $tenant, int $userId, string $referenceDate, array $groupMap): ?array
    {
        $value = function (string $field) use ($row, $columns): string {
            if (! array_key_exists($field, $columns)) {
                return '';
            }

            return $this->normalizeCsvValue((string) ($row[$columns[$field]] ?? ''));
        };

        $codigo = $value('codigo_column');
        $descricao = $value('descricao_column');
        $unidade = mb_strtoupper($value('unidade_column'));
        $groupValue = $value('grupo_column');
        $groupId = null;

        if ($groupValue !== '') {
            $groupId = $groupMap[$this->groupLookupKey($groupValue)] ?? null;

            if ($groupId === null) {
                return null;
            }
        }

        if ($codigo === '' || $descricao === '' || $unidade === '') {
            return null;
        }

        [$tipo, $classificacao] = $this->ownImportTypeFromValue($value('tipo_column'));

        if ($tipo === null || $classificacao === null) {
            return null;
        }

        $precoNaoDesonerado = $this->parseDecimal($value('preco_nao_desonerado_column'));
        $precoDesonerado = $this->parseDecimal($value('preco_desonerado_column'));

        if ($precoNaoDesonerado === null && $precoDesonerado === null) {
            return null;
        }

        return [
            'tenant_id' => $tenant->id,
            'created_by_id' => $userId,
            'grupo_id' => $groupId,
            'banco' => 'PROPRIA',
            'tipo' => $tipo,
            'classificacao' => $classificacao,
            'codigo_insumo' => $codigo,
            'descricao' => $descricao,
            'unidade' => $unidade,
            'uf' => null,
            'origem_preco' => 'PR',
            'preco_nao_desonerado' => $precoNaoDesonerado,
            'preco_desonerado' => $precoDesonerado,
            'data_referencia' => $referenceDate,
        ];
    }

    private function ownImportTypeFromValue(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [null, null];
        }

        $normalized = (string) Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->squish();

        return match ($normalized) {
            '1', 'equipamento', 'equipamentos' => ['equipment', 'Equipamento'],
            '2', 'equipamentodeaquisicaopermanente' => ['equipment', 'Equipamento de aquisicao permanente'],
            '3', 'maodeobra', 'maoobra' => ['labor', 'Mao de obra'],
            '4', 'material', 'materiais' => ['material', 'Material'],
            '5', 'servico', 'servicos' => ['service', 'Servicos'],
            '6', 'taxa', 'taxas' => ['service', 'Taxas'],
            '7', 'outro', 'outros' => ['service', 'Outros'],
            '9', 'administracao' => ['service', 'Administracao'],
            '10', 'aluguel', 'alugueis' => ['equipment', 'Aluguel'],
            '11', 'verba', 'verbas' => ['service', 'Verba'],
            default => [null, null],
        };
    }

    private function groupLookupKey(string $value): string
    {
        return $this->normalizeHeader($value);
    }

    private function importMappedGlobalInsumoCsv(string $path, ?int $tenantId, string $bank, int $userId, array $data): array
    {
        $this->prepareLongRunningImport();

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Nao foi possivel abrir o arquivo CSV.']);
        }

        $firstLine = fgets($handle) ?: '';
        $delimiter = $this->detectCsvDelimiter($firstLine);
        rewind($handle);

        $columns = $this->globalInsumoImportColumnMap($data);
        $firstRow = (int) $data['first_item_row'];
        $lastRow = (int) $data['last_item_row'];
        $result = ['read' => 0, 'created' => 0, 'updated' => 0, 'duplicated' => 0, 'skipped' => 0];
        $batch = [];
        $seenImportKeys = [];
        $lineNumber = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNumber++;

            if ($lineNumber < $firstRow) {
                continue;
            }

            if ($lineNumber > $lastRow) {
                break;
            }

            if ($this->isBlankCsvRow($row)) {
                continue;
            }

            $result['read']++;
            $payload = $this->payloadFromMappedGlobalInsumoCsvRow($row, $columns, $tenantId, $bank, $userId);

            if ($payload === null) {
                $result['skipped']++;
                continue;
            }

            $importKey = $this->insumoImportKey($payload);

            if (isset($seenImportKeys[$importKey])) {
                $result['duplicated']++;
                continue;
            }

            $seenImportKeys[$importKey] = true;
            $batch[] = $payload;

            if (count($batch) >= $this->csvImportBatchSize()) {
                $batchResult = $this->persistInsumoImportBatch($batch, $userId, false);
                $result['created'] += $batchResult['created'];
                $result['updated'] += $batchResult['updated'];
                $result['duplicated'] += $batchResult['duplicated'];
                $batch = [];
            }
        }

        if ($batch !== []) {
            $batchResult = $this->persistInsumoImportBatch($batch, $userId, false);
            $result['created'] += $batchResult['created'];
            $result['updated'] += $batchResult['updated'];
            $result['duplicated'] += $batchResult['duplicated'];
        }

        fclose($handle);

        return $result;
    }

    private function globalInsumoImportColumnMap(array $data): array
    {
        $fields = [
            'codigo_insumo_column' => true,
            'classificacao_column' => true,
            'descricao_column' => true,
            'unidade_column' => true,
            'uf_column' => true,
            'origem_preco_column' => false,
            'preco_nao_desonerado_column' => true,
            'preco_desonerado_column' => true,
            'custo_improdutivo_nao_desonerado_column' => false,
            'custo_improdutivo_desonerado_column' => false,
            'data_column' => true,
        ];
        $columns = [];

        foreach ($fields as $field => $required) {
            $value = mb_strtoupper(trim((string) ($data[$field] ?? '')));

            if ($value === '') {
                if ($required) {
                    throw ValidationException::withMessages([$field => 'Informe a letra da coluna.']);
                }

                continue;
            }

            $columns[$field] = $this->columnLetterToIndex($value);
        }

        return $columns;
    }

    private function payloadFromMappedGlobalInsumoCsvRow(array $row, array $columns, ?int $tenantId, string $bank, int $userId): ?array
    {
        $value = function (string $field) use ($row, $columns): string {
            if (! array_key_exists($field, $columns)) {
                return '';
            }

            return $this->normalizeCsvValue((string) ($row[$columns[$field]] ?? ''));
        };

        $codigo = $value('codigo_insumo_column');
        $descricao = $value('descricao_column');
        $unidade = mb_strtoupper($value('unidade_column'));
        $uf = mb_strtoupper($value('uf_column'));
        $origemPreco = mb_strtoupper($value('origem_preco_column'));
        $classificacao = $this->normalizeClassification($value('classificacao_column'));
        $referenceDate = $this->parseReferenceDateOrNull($value('data_column'));
        $precoNaoDesonerado = $this->parseDecimal($value('preco_nao_desonerado_column'));
        $precoDesonerado = $this->parseDecimal($value('preco_desonerado_column'));
        $custoImprodutivoNaoDesonerado = $bank === 'SICRO3'
            ? $this->parseDecimal($value('custo_improdutivo_nao_desonerado_column'))
            : null;
        $custoImprodutivoDesonerado = $bank === 'SICRO3'
            ? $this->parseDecimal($value('custo_improdutivo_desonerado_column'))
            : null;

        if ($codigo === '' || $descricao === '' || $unidade === '' || ! in_array($uf, self::BRAZILIAN_STATES, true) || $classificacao === null || ! $referenceDate) {
            return null;
        }

        if ($precoNaoDesonerado === null && $precoDesonerado === null) {
            return null;
        }

        return [
            'tenant_id' => $tenantId,
            'created_by_id' => $userId,
            'banco' => $bank,
            'tipo' => $this->typeFromClassification($classificacao),
            'classificacao' => $classificacao,
            'codigo_insumo' => $codigo,
            'descricao' => $descricao,
            'unidade' => $unidade,
            'uf' => $uf,
            'origem_preco' => $origemPreco ?: null,
            'preco_nao_desonerado' => $precoNaoDesonerado,
            'preco_desonerado' => $precoDesonerado,
            'custo_improdutivo_nao_desonerado' => $custoImprodutivoNaoDesonerado,
            'custo_improdutivo_desonerado' => $custoImprodutivoDesonerado,
            'data_referencia' => $referenceDate->toDateString(),
        ];
    }

    private function importCsv(string $path, ?int $tenantId, string $bank, int $userId): array
    {
        $this->prepareLongRunningImport();

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Não foi possível abrir o arquivo CSV.']);
        }

        $firstLine = fgets($handle) ?: '';
        $delimiter = $this->detectCsvDelimiter($firstLine);
        rewind($handle);

        $headers = $this->readCsvHeaders($handle, $delimiter);

        $headerMap = collect($headers)
            ->mapWithKeys(fn ($header, $index): array => [$this->normalizeHeader((string) $header) => $index])
            ->all();

        $required = ['codigo_insumo', 'descricao', 'unidade', 'uf', 'origem_preco', 'preco_nao_desonerado', 'preco_desonerado', 'data'];
        $missing = array_values(array_filter($required, fn (string $field): bool => ! array_key_exists($field, $headerMap)));

        if ($missing !== []) {
            fclose($handle);
            throw ValidationException::withMessages([
                'file' => 'Colunas ausentes no CSV: '.implode(', ', $missing).'.',
            ]);
        }

        $result = ['read' => 0, 'created' => 0, 'updated' => 0, 'duplicated' => 0, 'skipped' => 0];
        $batch = [];
        $seenImportKeys = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->isBlankCsvRow($row)) {
                continue;
            }

            $result['read']++;
            $payload = $this->payloadFromCsvRow($row, $headerMap, $tenantId, $bank, $userId);

            if ($payload === null) {
                $result['skipped']++;
                continue;
            }

            $importKey = $this->insumoImportKey($payload);

            if (isset($seenImportKeys[$importKey])) {
                $result['duplicated']++;
                continue;
            }

            $seenImportKeys[$importKey] = true;
            $batch[] = $payload;

            if (count($batch) >= $this->csvImportBatchSize()) {
                $batchResult = $this->persistInsumoImportBatch($batch, $userId);
                $result['created'] += $batchResult['created'];
                $result['updated'] += $batchResult['updated'];
                $batch = [];
            }
        }

        if ($batch !== []) {
            $batchResult = $this->persistInsumoImportBatch($batch, $userId);
            $result['created'] += $batchResult['created'];
            $result['updated'] += $batchResult['updated'];
        }

        fclose($handle);

        return $result;
    }

    private function payloadFromCsvRow(array $row, array $headerMap, ?int $tenantId, string $bank, int $userId): ?array
    {
        $value = fn (string $field): string => $this->normalizeCsvValue((string) ($row[$headerMap[$field]] ?? ''));
        $codigo = $value('codigo_insumo');
        $descricao = $value('descricao');
        $unidade = mb_strtoupper($value('unidade'));
        $uf = mb_strtoupper($value('uf'));
        $origemPreco = mb_strtoupper($value('origem_preco'));
        $classificacao = array_key_exists('classificacao', $headerMap)
            ? $this->normalizeClassification($value('classificacao'))
            : null;
        $referenceDate = $this->parseReferenceDateOrNull($value('data'));

        if ($codigo === '' || $descricao === '' || $unidade === '' || ! in_array($uf, self::BRAZILIAN_STATES, true) || ! $referenceDate) {
            return null;
        }

        return [
            'tenant_id' => $tenantId,
            'created_by_id' => $userId,
            'banco' => $bank,
            'tipo' => $this->typeFromClassification($classificacao),
            'classificacao' => $classificacao,
            'codigo_insumo' => $codigo,
            'descricao' => $descricao,
            'unidade' => $unidade,
            'uf' => $uf,
            'origem_preco' => $origemPreco ?: null,
            'preco_nao_desonerado' => $this->parseDecimal($value('preco_nao_desonerado')),
            'preco_desonerado' => $this->parseDecimal($value('preco_desonerado')),
            'data_referencia' => $referenceDate->toDateString(),
        ];
    }

    private function persistInsumoImportBatch(array $payloads, int $userId, bool $updateExisting = true): array
    {
        $payloadsByKey = [];

        foreach ($payloads as $payload) {
            $payloadsByKey[$this->insumoImportKey($payload)] = $payload;
        }

        $payloads = array_values($payloadsByKey);
        $existingByKey = $this->existingInsumosForImportBatch($payloads);
        $now = now();
        $insertRows = [];
        $updated = 0;
        $duplicated = 0;

        foreach ($payloads as $payload) {
            $key = $this->insumoImportKey($payload);
            $attributes = [
                'created_by_id' => $userId,
                'tipo' => $payload['tipo'],
                'classificacao' => $payload['classificacao'],
                'descricao' => $payload['descricao'],
                'unidade' => $payload['unidade'],
                'preco_nao_desonerado' => $payload['preco_nao_desonerado'],
                'preco_desonerado' => $payload['preco_desonerado'],
                'updated_at' => $now,
                'deleted_at' => null,
            ];

            if (array_key_exists('grupo_id', $payload)) {
                $attributes['grupo_id'] = $payload['grupo_id'];
            }

            if (array_key_exists($key, $existingByKey)) {
                if (! $updateExisting) {
                    $duplicated++;

                    continue;
                }

                OrcamentoInsumo::withTrashed()
                    ->whereKey($existingByKey[$key])
                    ->update($attributes);
                $updated++;

                continue;
            }

            $insertRows[] = array_merge($payload, [
                'data_referencia' => CarbonImmutable::parse($payload['data_referencia'])->startOfDay()->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->insertInsumoImportRows($insertRows);

        return [
            'created' => count($insertRows),
            'updated' => $updated,
            'duplicated' => $duplicated,
        ];
    }

    private function existingInsumosForImportBatch(array $payloads): array
    {
        if ($payloads === []) {
            return [];
        }

        $first = $payloads[0];
        $codes = array_values(array_unique(array_column($payloads, 'codigo_insumo')));
        $states = array_values(array_unique(array_filter(
            array_column($payloads, 'uf'),
            fn ($state): bool => $state !== null && $state !== '',
        )));
        $hasNullState = collect($payloads)->contains(fn (array $payload): bool => empty($payload['uf']));
        $dates = array_values(array_unique(array_column($payloads, 'data_referencia')));
        $origins = array_values(array_unique(array_map(fn (array $payload): string => $payload['origem_preco'] ?? '__NULL__', $payloads)));
        $originValues = array_values(array_filter($origins, fn (string $origin): bool => $origin !== '__NULL__'));
        $hasNullOrigin = in_array('__NULL__', $origins, true);

        $query = OrcamentoInsumo::withTrashed()
            ->select(['id', 'tenant_id', 'banco', 'codigo_insumo', 'uf', 'origem_preco', 'data_referencia'])
            ->where('banco', $first['banco'])
            ->whereIn('codigo_insumo', $codes)
            ->where(function (Builder $query) use ($states, $hasNullState): void {
                if ($states !== []) {
                    $query->whereIn('uf', $states);
                }

                if ($hasNullState) {
                    $states === [] ? $query->whereNull('uf') : $query->orWhereNull('uf');
                }
            })
            ->where(function (Builder $query) use ($dates): void {
                foreach ($dates as $date) {
                    $query->orWhereDate('data_referencia', $date);
                }
            })
            ->where(function (Builder $query) use ($originValues, $hasNullOrigin): void {
                if ($hasNullOrigin) {
                    $query->orWhereNull('origem_preco');
                }

                if ($originValues !== []) {
                    $query->orWhereIn('origem_preco', $originValues);
                }
            });

        $this->whereNullable($query, 'tenant_id', $first['tenant_id']);

        return $query
            ->get()
            ->mapWithKeys(fn (OrcamentoInsumo $insumo): array => [
                $this->insumoImportKey([
                    'tenant_id' => $insumo->tenant_id,
                    'banco' => $insumo->banco,
                    'codigo_insumo' => $insumo->codigo_insumo,
                    'uf' => $insumo->uf,
                    'origem_preco' => $insumo->origem_preco,
                    'data_referencia' => $insumo->data_referencia?->format('Y-m-d') ?? (string) $insumo->getRawOriginal('data_referencia'),
                ]) => $insumo->id,
            ])
            ->all();
    }

    private function insertInsumoImportRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $chunkSize = DB::connection()->getDriverName() === 'pgsql' ? 2000 : 50;

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            OrcamentoInsumo::insert($chunk);
        }
    }

    private function insumoImportKey(array $payload): string
    {
        return implode("\x1F", [
            $payload['tenant_id'] ?? '__NULL__',
            $payload['banco'] ?? '',
            $payload['codigo_insumo'] ?? '',
            $payload['uf'] ?? '',
            $payload['origem_preco'] ?? '__NULL__',
            CarbonImmutable::parse($payload['data_referencia'])->toDateString(),
        ]);
    }

    private function prepareLongRunningImport(): void
    {
        @ini_set('max_execution_time', '0');
        @ini_set('max_input_time', '0');
        @ini_set('memory_limit', '512M');
        @set_time_limit(0);
        DB::connection()->disableQueryLog();
    }

    private function csvImportBatchSize(): int
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? self::POSTGRES_CSV_IMPORT_BATCH_SIZE
            : self::CSV_IMPORT_BATCH_SIZE;
    }

    private function detectCsvDelimiter(string $line): string
    {
        $line = $this->normalizeCsvValue($line);
        $separatorDirective = Str::of($line)->lower()->trim();

        if ($separatorDirective->startsWith('sep=')) {
            $separator = $separatorDirective->after('sep=')->substr(0, 1)->toString();

            return $separator === '\t' ? "\t" : ($separator ?: ',');
        }

        $scores = collect([';', "\t", ',', '|'])
            ->mapWithKeys(function (string $delimiter) use ($line): array {
                $columns = str_getcsv($line, $delimiter);
                $filledColumns = collect($columns)
                    ->filter(fn ($column): bool => $this->normalizeHeader((string) $column) !== '')
                    ->count();

                return [$delimiter => (count($columns) * 10) + $filledColumns];
            });

        $delimiter = (string) $scores->sortDesc()->keys()->first();

        if (($scores[$delimiter] ?? 0) > 11) {
            return $delimiter;
        }

        return ',';
    }

    private function readCsvHeaders(mixed $handle, string $delimiter): array
    {
        while (($headers = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (! is_array($headers)) {
                continue;
            }

            $headers = array_map(fn ($header): string => $this->normalizeCsvValue((string) $header), $headers);

            if (count($headers) === 1 && Str::of($headers[0])->lower()->trim()->startsWith('sep=')) {
                continue;
            }

            $hasHeaderValue = collect($headers)
                ->contains(fn ($header): bool => $this->normalizeHeader((string) $header) !== '');

            if ($hasHeaderValue) {
                return $headers;
            }
        }

        throw ValidationException::withMessages(['file' => 'O arquivo CSV esta vazio ou nao possui cabecalho valido.']);
    }

    private function isBlankCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->normalizeCsvValue((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeCsvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            $value = substr($value, 3);
        }

        if (str_starts_with($value, "\xFF\xFE")) {
            $value = (string) @mb_convert_encoding(substr($value, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($value, "\xFE\xFF")) {
            $value = (string) @mb_convert_encoding(substr($value, 2), 'UTF-8', 'UTF-16BE');
        } elseif (str_contains($value, "\0")) {
            $evenNulls = 0;
            $oddNulls = 0;
            $length = strlen($value);

            for ($index = 0; $index < $length; $index++) {
                if ($value[$index] !== "\0") {
                    continue;
                }

                $index % 2 === 0 ? $evenNulls++ : $oddNulls++;
            }

            $encoding = $oddNulls >= $evenNulls ? 'UTF-16LE' : 'UTF-16BE';
            $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);

            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $converted = $this->convertLegacyCsvEncoding($value);

            if ($converted !== null) {
                $value = $converted;
            }
        }

        $value = $this->repairLegacyDecodedCsvValue($value);
        $value = str_replace("\xC2\xA0", ' ', $value);

        return trim($value);
    }

    private function repairLegacyDecodedCsvValue(string $value): string
    {
        if ($value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        if (! preg_match('/(?:Ã.|Â.|�|[╀-╿]|ß|Ð|þ|Þ)/u', $value)) {
            return $value;
        }

        $candidateBytes = @mb_convert_encoding($value, 'CP850', 'UTF-8');

        if (! is_string($candidateBytes) || $candidateBytes === '') {
            return $value;
        }

        $candidate = @mb_convert_encoding($candidateBytes, 'UTF-8', 'Windows-1252');

        if (! is_string($candidate) || ! mb_check_encoding($candidate, 'UTF-8')) {
            return $value;
        }

        $candidate = str_replace("\xC2\xA0", ' ', $candidate);

        return $this->scoreCsvEncodingCandidate($candidate) > $this->scoreCsvEncodingCandidate($value)
            ? $candidate
            : $value;
    }

    private function convertLegacyCsvEncoding(string $value): ?string
    {
        $bestValue = null;
        $bestScore = PHP_INT_MIN;

        foreach (['Windows-1252', 'ISO-8859-1', 'CP850'] as $encoding) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);

            if (! is_string($converted) || ! mb_check_encoding($converted, 'UTF-8')) {
                continue;
            }

            $score = $this->scoreCsvEncodingCandidate($converted);

            if ($score > $bestScore) {
                $bestValue = $converted;
                $bestScore = $score;
            }
        }

        return $bestValue;
    }

    private function scoreCsvEncodingCandidate(string $value): int
    {
        $score = 0;

        $score += preg_match_all('/[\x{00C0}-\x{00C3}\x{00C7}\x{00C9}\x{00CA}\x{00CD}\x{00D3}-\x{00D5}\x{00DA}\x{00DC}\x{00E0}-\x{00E3}\x{00E7}\x{00E9}\x{00EA}\x{00ED}\x{00F3}-\x{00F5}\x{00FA}\x{00FC}]/u', $value) * 8;
        $score -= preg_match_all('/[\x{FFFD}\x{0080}-\x{009F}\x{00DF}\x{00DE}\x{00FE}\x{00D0}\x{00F0}\x{00FF}\x{2500}-\x{259F}]/u', $value) * 12;
        $score -= preg_match_all('/(?<=[A-Z0-9*])\x{00E1}(?=[A-Z0-9*])/u', $value) * 12;
        $score -= preg_match_all('/\x{00C3}[\x{2500}-\x{259F}]/u', $value) * 12;
        $score -= substr_count($value, "\xC2\xA0") * 3;
        $score -= substr_count($value, html_entity_decode('&euro;', ENT_QUOTES, 'UTF-8')) * 6;

        return $score;
    }

    private function normalizeClassification(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $normalizedValue = str_replace(html_entity_decode('&euro;', ENT_QUOTES, 'UTF-8'), 'C', $value);
        $key = (string) Str::of($normalizedValue)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', ' ')
            ->squish();
        $compactKey = str_replace(' ', '', $key);

        return match ($compactKey) {
            'SERVICO', 'SERVICOS' => 'Servicos',
            'MATERIAL', 'MATERIAIS' => 'Material',
            'MAODEOBRA', 'MAOOBRA' => 'Mao de obra',
            'EQUIPAMENTO', 'EQUIPAMENTOS' => 'Equipamento',
            default => Str::of($normalizedValue)->squish()->limit(80, '')->toString(),
        };
    }

    private function typeFromClassification(?string $classificacao): ?string
    {
        if (! $classificacao) {
            return null;
        }

        $key = (string) Str::of($classificacao)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '');

        return match ($key) {
            'servico', 'servicos' => 'service',
            'material', 'materiais' => 'material',
            'maodeobra', 'maoobra' => 'labor',
            'equipamento', 'equipamentos' => 'equipment',
            default => null,
        };
    }

    private function resolveCsvHeaderMap(array $headers, array $aliases): array
    {
        $normalizedHeaders = [];

        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader((string) $header);

            if ($normalized === '') {
                continue;
            }

            $normalizedHeaders[$normalized] ??= $index;
        }

        $resolved = [];

        foreach ($aliases as $field => $fieldAliases) {
            foreach ($fieldAliases as $alias) {
                $normalizedAlias = $this->normalizeHeader((string) $alias);

                if (array_key_exists($normalizedAlias, $normalizedHeaders)) {
                    $resolved[$field] = $normalizedHeaders[$normalizedAlias];
                    break;
                }
            }
        }

        return $resolved;
    }

    private function normalizeHeader(string $header): string
    {
        $header = $this->normalizeCsvValue($header);
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = preg_replace('/^(\x{FEFF}|ï»¿)/u', '', $header) ?? $header;
        $header = Str::of($header)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');

        return (string) $header;
    }

    private function parseReferenceDate(string $value): CarbonImmutable
    {
        $date = $this->parseReferenceDateOrNull($value);

        if (! $date) {
            throw ValidationException::withMessages(['data' => 'Informe a data no formato MM/AAAA, MM/AA, MMM/AA ou AAAA-MM-DD.']);
        }

        return $date;
    }

    private function parseReferenceDateOrNull(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $normalizedValue = (string) Str::of($value)
            ->ascii()
            ->lower()
            ->replace('.', '')
            ->replaceMatches('/\s+/', '');

        if (preg_match('/^([a-z]{3,9})[\/-](\d{2}|\d{4})$/', $normalizedValue, $matches)) {
            $months = [
                'jan' => 1,
                'janeiro' => 1,
                'fev' => 2,
                'fevereiro' => 2,
                'mar' => 3,
                'marco' => 3,
                'abr' => 4,
                'abril' => 4,
                'mai' => 5,
                'maio' => 5,
                'jun' => 6,
                'junho' => 6,
                'jul' => 7,
                'julho' => 7,
                'ago' => 8,
                'agosto' => 8,
                'set' => 9,
                'setembro' => 9,
                'out' => 10,
                'outubro' => 10,
                'nov' => 11,
                'novembro' => 11,
                'dez' => 12,
                'dezembro' => 12,
            ];
            $month = $months[$matches[1]] ?? null;
            $year = (int) $matches[2];
            $year = $year < 100 ? 2000 + $year : $year;

            if ($month) {
                return CarbonImmutable::create($year, $month, 1)->startOfMonth();
            }
        }

        if (preg_match('/^(\d{1,2})\/(\d{2}|\d{4})$/', $value, $matches)) {
            $month = (int) $matches[1];
            $year = (int) $matches[2];
            $year = $year < 100 ? 2000 + $year : $year;

            if ($month >= 1 && $month <= 12) {
                return CarbonImmutable::create($year, $month, 1)->startOfMonth();
            }
        }

        if (preg_match('/^(\d{4})-(\d{2})(?:-\d{2})?$/', $value, $matches)) {
            return CarbonImmutable::create((int) $matches[1], (int) $matches[2], 1)->startOfMonth();
        }

        return null;
    }

    private function parseDecimal(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = str_replace(['R$', ' '], '', $value);

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? $this->normalizeDecimalString($value) : null;
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeDecimalString(string $value, int $maxDecimals = 6): string
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');

        if ($negative) {
            $value = ltrim($value, '-');
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim(preg_replace('/\D/', '', $integer) ?? '0', '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = preg_replace('/\D/', '', $fraction) ?? '';
        $fraction = rtrim(substr($fraction, 0, $maxDecimals), '0');

        return ($negative ? '-' : '').$integer.($fraction !== '' ? '.'.$fraction : '.0');
    }

    private function serializeInsumo(OrcamentoInsumo $insumo): array
    {
        return [
            'id' => $insumo->id,
            'scope' => $insumo->tenant_id ? 'tenant' : 'global',
            'scope_label' => $insumo->tenant_id ? 'Base propria' : 'Global',
            'grupo_id' => $insumo->grupo_id,
            'grupo' => $insumo->grupo ? $this->serializeInsumoGrupo($insumo->grupo) : null,
            'banco' => $insumo->banco,
            'tipo' => $insumo->tipo,
            'classificacao' => $insumo->classificacao,
            'codigo_insumo' => $insumo->codigo_insumo,
            'descricao' => $insumo->descricao,
            'unidade' => $insumo->unidade,
            'uf' => $insumo->uf,
            'origem_preco' => $insumo->origem_preco,
            'preco_nao_desonerado' => $this->calculateMoney($insumo->preco_nao_desonerado),
            'preco_desonerado' => $this->calculateMoney($insumo->preco_desonerado),
            'custo_improdutivo_nao_desonerado' => $this->calculateMoney($insumo->custo_improdutivo_nao_desonerado),
            'custo_improdutivo_desonerado' => $this->calculateMoney($insumo->custo_improdutivo_desonerado),
            'raw_preco_nao_desonerado' => $insumo->preco_nao_desonerado,
            'raw_preco_desonerado' => $insumo->preco_desonerado,
            'raw_custo_improdutivo_nao_desonerado' => $insumo->custo_improdutivo_nao_desonerado,
            'raw_custo_improdutivo_desonerado' => $insumo->custo_improdutivo_desonerado,
            'data' => $insumo->data_referencia?->format('m/Y'),
            'observacao' => $insumo->observacao,
        ];
    }

    private function serializeInsumoGrupo(OrcamentoInsumoGrupo $grupo): array
    {
        return [
            'id' => $grupo->id,
            'nome' => $grupo->nome,
            'descricao' => $grupo->descricao,
            'created_at' => $grupo->created_at?->format('d/m/Y H:i'),
        ];
    }

    private function insumoGrupoOptions(Tenant $tenant): array
    {
        return OrcamentoInsumoGrupo::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('nome')
            ->get()
            ->map(fn (OrcamentoInsumoGrupo $grupo): array => [
                'value' => $grupo->id,
                'label' => $grupo->nome,
            ])
            ->values()
            ->all();
    }

    private function insumoGruposForPage(Tenant $tenant): array
    {
        return OrcamentoInsumoGrupo::query()
            ->where('tenant_id', $tenant->id)
            ->withCount('insumos')
            ->orderBy('nome')
            ->get()
            ->map(fn (OrcamentoInsumoGrupo $grupo): array => array_merge(
                $this->serializeInsumoGrupo($grupo),
                ['insumos_count' => (int) $grupo->insumos_count],
            ))
            ->values()
            ->all();
    }

    private function serializeComposicaoItem(OrcamentoComposicaoItem $item, ?OrcamentoComposicao $composicao = null): array
    {
        $calculationMethod = $composicao ? $this->calculationMethodForComposicao($composicao) : 'truncate_2';
        $sicro3Section = $item->sicro3_section ?: $this->sicro3SectionFromInsumoItem($item);
        $sicro3SectionMeta = $this->sicro3SectionMeta($sicro3Section);

        return [
            'id' => $item->id,
            'item_type' => $item->item_type,
            'sicro3_section' => $sicro3Section,
            'sicro3_section_code' => $sicro3SectionMeta['code'] ?? null,
            'sicro3_section_label' => $sicro3SectionMeta['label'] ?? null,
            'base' => $item->base,
            'codigo' => $item->codigo,
            'child_composicao_id' => $item->child_composicao_id,
            'descricao' => $item->descricao,
            'tipo' => $item->tipo,
            'unidade' => $item->unidade,
            'preco_unitario_onerado' => $this->calculateIntermediateMoney($item->preco_unitario_onerado, $calculationMethod),
            'preco_unitario_desonerado' => $this->calculateIntermediateMoney($item->preco_unitario_desonerado, $calculationMethod),
            'custo_improdutivo_onerado' => $this->calculateIntermediateMoney($item->custo_improdutivo_onerado, $calculationMethod),
            'custo_improdutivo_desonerado' => $this->calculateIntermediateMoney($item->custo_improdutivo_desonerado, $calculationMethod),
            'coeficiente' => $item->coeficiente,
            'sicro3_utilizacao_operativa' => $item->sicro3_utilizacao_operativa,
            'sicro3_utilizacao_improdutiva' => $item->sicro3_utilizacao_improdutiva,
            'sicro3_referenced_item_id' => $item->sicro3_referenced_item_id,
            'sicro3_referenced_item_code' => $item->sicro3_referenced_item_code,
            'sicro3_referenced_item_description' => $item->sicro3_referenced_item_description,
            'sicro3_transport_ln_code' => $item->sicro3_transport_ln_code,
            'sicro3_transport_rp_code' => $item->sicro3_transport_rp_code,
            'sicro3_transport_p_code' => $item->sicro3_transport_p_code,
            'sicro3_transport_fe_code' => $item->sicro3_transport_fe_code,
            'preco_onerado' => $this->calculateIntermediateMoney($item->preco_onerado, $calculationMethod),
            'preco_desonerado' => $this->calculateIntermediateMoney($item->preco_desonerado, $calculationMethod),
            'raw_preco_onerado' => $item->preco_onerado,
            'raw_preco_desonerado' => $item->preco_desonerado,
            'observacao' => $item->observacao,
        ];
    }

    private function isSicro3Composicao(OrcamentoComposicao $composicao): bool
    {
        return mb_strtoupper((string) $composicao->modelo) === 'SICRO3';
    }

    private function sicro3SectionMeta(?string $section): ?array
    {
        if (! $section) {
            return null;
        }

        return self::SICRO3_INSUMO_ITEM_SECTIONS[$section]
            ?? self::SICRO3_COMPOSICAO_ITEM_SECTIONS[$section]
            ?? null;
    }

    private function sicro3SectionFromInsumoItem(OrcamentoComposicaoItem $item): ?string
    {
        if ($item->item_type !== 'insumo') {
            return null;
        }

        return $this->sicro3SectionFromType($item->tipo);
    }

    private function sicro3SectionFromType(?string $type): ?string
    {
        $type = Str::of((string) $type)
            ->ascii()
            ->lower()
            ->toString();
        $type = str_replace('-', ' ', $type);

        if (Str::contains($type, ['equipamento', 'equipment'])) {
            return 'equipamentos';
        }

        if (Str::contains($type, ['mao de obra', 'mão de obra', 'labor'])) {
            return 'mao_de_obra';
        }

        if (Str::contains($type, ['material'])) {
            return 'material';
        }

        return null;
    }

    private function serializeInsumoOption(OrcamentoInsumo $insumo): array
    {
        return [
            'id' => $insumo->id,
            'base' => $insumo->banco,
            'base_label' => trim($insumo->banco.' - '.$insumo->uf.' - '.$insumo->data_referencia?->format('m/Y')),
            'codigo' => $insumo->codigo_insumo,
            'descricao' => $insumo->descricao,
            'tipo' => $insumo->classificacao ?: $this->typeLabel($insumo->tipo),
            'unidade' => $insumo->unidade,
            'preco_unitario_onerado' => $this->calculateMoney($insumo->preco_nao_desonerado),
            'preco_unitario_desonerado' => $this->calculateMoney($insumo->preco_desonerado),
            'custo_improdutivo_onerado' => $this->calculateMoney($insumo->custo_improdutivo_nao_desonerado),
            'custo_improdutivo_desonerado' => $this->calculateMoney($insumo->custo_improdutivo_desonerado),
            'data' => $insumo->data_referencia?->format('m/Y'),
        ];
    }

    private function serializeComposicaoOption(OrcamentoComposicao $composicao): array
    {
        $calculationMethod = $this->calculationMethodForComposicao($composicao);

        return [
            'id' => $composicao->id,
            'base' => $composicao->modelo,
            'base_label' => $composicao->modelo,
            'modelo' => $composicao->modelo,
            'codigo' => $composicao->codigo,
            'descricao' => $composicao->descricao,
            'tipo' => $composicao->tipo_composicao,
            'unidade' => $composicao->unidade,
            'preco_unitario_onerado' => $this->calculateMoney($composicao->preco_onerado, $calculationMethod),
            'preco_unitario_desonerado' => $this->calculateMoney($composicao->preco_desonerado, $calculationMethod),
            'data' => null,
        ];
    }

    private function serializeComposicao(OrcamentoComposicao $composicao, ?Tenant $tenant = null, ?array $summary = null): array
    {
        $summary ??= $tenant
            ? $this->composicaoPriceSummary($tenant, $composicao)
            : $this->rawComposicaoPriceSummary($composicao);
        $calculationMethod = $this->calculationMethodForComposicao($composicao);
        $sicro3Summary = request()->routeIs('tenant.orcamentos.composicoes.show') && $this->isSicro3Composicao($composicao)
            ? $this->sicro3ManualCalculation($composicao)
            : null;

        return [
            'id' => $composicao->id,
            'scope' => $composicao->is_global ? 'global' : 'tenant',
            'scope_label' => $composicao->is_global ? 'Global' : 'Base propria',
            'base' => $composicao->is_global ? $composicao->modelo : 'PROPRIA',
            'base_label' => $composicao->is_global ? $composicao->modelo : 'Base propria',
            'codigo' => $composicao->codigo,
            'descricao' => $composicao->descricao,
            'tipo_composicao' => $composicao->tipo_composicao,
            'unidade' => $composicao->unidade,
            'uf' => $composicao->uf,
            'estado_label' => $this->stateLabel($composicao->uf),
            'modelo' => $composicao->modelo,
            'metodo_calculo' => $composicao->metodo_calculo,
            'metodo_calculo_label' => $this->calculationMethodLabel($calculationMethod),
            'producao_equipe' => $composicao->producao_equipe,
            'adicional_mao_obra' => $composicao->adicional_mao_obra,
            'fator_influencia_chuvas' => $composicao->fator_influencia_chuvas,
            'observacao' => $composicao->observacao,
            'base_references' => $composicao->base_references ?? [],
            'preco_onerado' => $this->calculateMoney($composicao->preco_onerado, $calculationMethod),
            'preco_desonerado' => $this->calculateMoney($composicao->preco_desonerado, $calculationMethod),
            'raw_preco_onerado' => $composicao->preco_onerado,
            'raw_preco_desonerado' => $composicao->preco_desonerado,
            'effective_preco_onerado' => $this->calculateMoney($summary['effective_preco_onerado'], $calculationMethod),
            'effective_preco_desonerado' => $this->calculateMoney($summary['effective_preco_desonerado'], $calculationMethod),
            'computed_preco_onerado' => $this->calculateMoney($summary['computed_preco_onerado'], $calculationMethod),
            'computed_preco_desonerado' => $this->calculateMoney($summary['computed_preco_desonerado'], $calculationMethod),
            'sicro3_summary' => $sicro3Summary,
            'price_source' => $summary['price_source'],
            'missing_price_items_count' => $summary['missing_price_items_count'],
            'items_count' => max((int) ($composicao->items_count ?? 0), (int) ($summary['analytic_items_count'] ?? 0)),
            'created_at' => $composicao->created_at?->format('d/m/Y H:i'),
        ];
    }

    private function composicaoAnaliticoDetail(Tenant $tenant, OrcamentoComposicao $composicao): array
    {
        $referenceDate = $this->firstReferenceDateForComposicao($composicao);
        $model = mb_strtoupper($composicao->modelo);
        $stateComposicoes = $this->sameComposicoesByState($tenant, $composicao, $referenceDate);
        $states = $stateComposicoes->pluck('uf')->unique()->values()->all();
        $analyticItems = $this->analyticItemsForComposicao($tenant, $composicao, $referenceDate);
        $sources = $this->analyticSourcesByState($tenant, $model, $states, $analyticItems, $referenceDate);

        return [
            'codigo' => $composicao->codigo,
            'descricao' => $composicao->descricao,
            'tipo' => $composicao->tipo_composicao,
            'unidade' => $composicao->unidade,
            'modelo' => $model,
            'data' => $referenceDate ? CarbonImmutable::parse($referenceDate)->format('m/Y') : null,
            'states' => $stateComposicoes
                ->map(fn (OrcamentoComposicao $stateComposicao): array => $this->serializeAnaliticoState(
                    $stateComposicao,
                    $analyticItems,
                    $sources,
                    $tenant,
                    [$composicao->id => true],
                ))
                ->values()
                ->all(),
        ];
    }

    private function composicaoSummaryFromDetailState(OrcamentoComposicao $composicao, array $detail): ?array
    {
        foreach (($detail['states'] ?? []) as $state) {
            if ((int) ($state['composicao_id'] ?? 0) !== (int) $composicao->id) {
                continue;
            }

            return [
                'preco_onerado' => (float) ($state['raw_preco_onerado'] ?? $state['preco_onerado'] ?? 0),
                'preco_desonerado' => (float) ($state['raw_preco_desonerado'] ?? $state['preco_desonerado'] ?? 0),
                'computed_preco_onerado' => (float) ($state['computed_preco_onerado'] ?? 0),
                'computed_preco_desonerado' => (float) ($state['computed_preco_desonerado'] ?? 0),
                'effective_preco_onerado' => (float) ($state['effective_preco_onerado'] ?? 0),
                'effective_preco_desonerado' => (float) ($state['effective_preco_desonerado'] ?? 0),
                'price_source' => (string) ($state['price_source'] ?? 'empty'),
                'missing_price_items_count' => (int) ($state['missing_price_items_count'] ?? 0),
                'analytic_items_count' => (int) ($state['items_count'] ?? 0),
            ];
        }

        return null;
    }

    private function sameComposicoesByState(Tenant $tenant, OrcamentoComposicao $composicao, ?string $referenceDate): \Illuminate\Support\Collection
    {
        $candidates = $this->composicoesAvailableForTenant($tenant)
            ->where('modelo', mb_strtoupper($composicao->modelo))
            ->where(function (Builder $query) use ($composicao): void {
                $this->whereCodeMatches($query, 'codigo', $composicao->codigo);
            })
            ->get()
            ->filter(fn (OrcamentoComposicao $candidate): bool => ! $referenceDate || $this->composicaoHasReferenceDate($candidate, $referenceDate))
            ->sortBy(function (OrcamentoComposicao $candidate): int {
                $index = array_search($candidate->uf, self::BRAZILIAN_STATES, true);

                return $index === false ? 999 : $index;
            })
            ->values();

        return $candidates->isNotEmpty() ? $candidates : collect([$composicao]);
    }

    private function analyticItemsForComposicao(Tenant $tenant, OrcamentoComposicao $composicao, ?string $referenceDate): \Illuminate\Support\Collection
    {
        $cacheKey = implode("\x1F", [
            $tenant->id,
            $composicao->id,
            $referenceDate ?: '__NULL__',
        ]);

        if (isset($this->analyticItemsCache[$cacheKey])) {
            return $this->analyticItemsCache[$cacheKey];
        }

        $query = OrcamentoComposicaoAnaliticoItem::query()
            ->where('modelo', mb_strtoupper($composicao->modelo))
            ->where(function (Builder $query) use ($tenant): void {
                $query->where('tenant_id', $tenant->id)->orWhere('is_global', true);
            })
            ->where(function (Builder $query) use ($composicao): void {
                $this->whereCodeMatches($query, 'codigo_composicao', $composicao->codigo);
            });

        if ($referenceDate) {
            $query->whereDate('data_referencia', CarbonImmutable::parse($referenceDate)->toDateString());
        }

        return $this->analyticItemsCache[$cacheKey] = $query
            ->orderBy('id')
            ->get()
            ->unique(fn (OrcamentoComposicaoAnaliticoItem $item): string => implode('|', [
                $item->tipo_item,
                $this->codeKey($item->codigo_item),
                $item->data_referencia?->toDateString() ?? '',
                (string) $item->coeficiente,
            ]))
            ->values();
    }

    private function analyticSourcesByState(Tenant $tenant, string $model, array $states, \Illuminate\Support\Collection $analyticItems, ?string $referenceDate): array
    {
        $insumoCodes = $analyticItems
            ->filter(fn (OrcamentoComposicaoAnaliticoItem $item): bool => $item->tipo_item === 'insumo')
            ->pluck('codigo_item')
            ->filter()
            ->values()
            ->all();
        $composicaoCodes = $analyticItems
            ->filter(fn (OrcamentoComposicaoAnaliticoItem $item): bool => $item->tipo_item === 'composicao')
            ->pluck('codigo_item')
            ->filter()
            ->values()
            ->all();

        return [
            'insumos' => $this->analyticInsumoSourcesByState($tenant, $model, $states, $insumoCodes, $referenceDate),
            'composicoes' => $this->analyticComposicaoSourcesByState($tenant, $model, $states, $composicaoCodes, $referenceDate),
        ];
    }

    private function analyticInsumoSourcesByState(Tenant $tenant, string $model, array $states, array $codes, ?string $referenceDate): \Illuminate\Support\Collection
    {
        if ($states === [] || $codes === []) {
            return collect();
        }

        $query = $this->insumosAvailableForTenant($tenant)
            ->where('banco', $model)
            ->whereIn('uf', $states);

        $this->whereCodeInMatches($query, 'codigo_insumo', $codes);

        if ($referenceDate) {
            $query->whereDate('data_referencia', CarbonImmutable::parse($referenceDate)->toDateString());
        }

        return $query
            ->get()
            ->groupBy(fn (OrcamentoInsumo $insumo): string => $insumo->uf.'|'.$this->codeKey($insumo->codigo_insumo))
            ->map(fn ($group): OrcamentoInsumo => $group
                ->sortBy(fn (OrcamentoInsumo $insumo): int => $insumo->tenant_id === $tenant->id ? 0 : 1)
                ->first());
    }

    private function analyticComposicaoSourcesByState(Tenant $tenant, string $model, array $states, array $codes, ?string $referenceDate): \Illuminate\Support\Collection
    {
        if ($states === [] || $codes === []) {
            return collect();
        }

        $query = $this->composicoesAvailableForTenant($tenant)
            ->withCount('items')
            ->withSum('items as items_preco_onerado_sum', 'preco_onerado')
            ->withSum('items as items_preco_desonerado_sum', 'preco_desonerado')
            ->where('modelo', $model)
            ->whereIn('uf', $states);

        $this->whereCodeInMatches($query, 'codigo', $codes);

        return $query
            ->get()
            ->filter(fn (OrcamentoComposicao $candidate): bool => ! $referenceDate || $this->composicaoHasReferenceDate($candidate, $referenceDate))
            ->groupBy(fn (OrcamentoComposicao $candidate): string => $candidate->uf.'|'.$this->codeKey($candidate->codigo))
            ->map(fn ($group): OrcamentoComposicao => $group
                ->sortBy(fn (OrcamentoComposicao $candidate): int => $candidate->tenant_id === $tenant->id ? 0 : 1)
                ->first());
    }

    private function serializeAnaliticoState(OrcamentoComposicao $stateComposicao, \Illuminate\Support\Collection $analyticItems, array $sources, ?Tenant $tenant = null, array $visited = []): array
    {
        $calculationMethod = $this->calculationMethodForComposicao($stateComposicao);
        $items = $analyticItems
            ->map(fn (OrcamentoComposicaoAnaliticoItem $item): array => $this->serializeAnaliticoItemForState($item, $stateComposicao->uf, $sources, $tenant, $visited, $calculationMethod))
            ->values();
        $computedOnerado = $this->calculateMoney($items->sum('raw_preco_onerado'), $calculationMethod);
        $computedDesonerado = $this->calculateMoney($items->sum('raw_preco_desonerado'), $calculationMethod);
        $rawOnerado = (float) ($stateComposicao->preco_onerado ?? 0);
        $rawDesonerado = (float) ($stateComposicao->preco_desonerado ?? 0);
        $usesAnalyticPrice = ($rawOnerado <= 0 && $computedOnerado > 0) || ($rawDesonerado <= 0 && $computedDesonerado > 0);
        $missingPriceItems = (int) $items->sum('missing_price_items_count');

        return [
            'uf' => $stateComposicao->uf,
            'estado_label' => $this->stateLabel($stateComposicao->uf),
            'composicao_id' => $stateComposicao->id,
            'preco_onerado' => $this->calculateMoney($stateComposicao->preco_onerado, $calculationMethod),
            'preco_desonerado' => $this->calculateMoney($stateComposicao->preco_desonerado, $calculationMethod),
            'raw_preco_onerado' => $stateComposicao->preco_onerado,
            'raw_preco_desonerado' => $stateComposicao->preco_desonerado,
            'computed_preco_onerado' => $computedOnerado,
            'computed_preco_desonerado' => $computedDesonerado,
            'effective_preco_onerado' => $this->calculateMoney($rawOnerado > 0 ? $rawOnerado : $computedOnerado, $calculationMethod),
            'effective_preco_desonerado' => $this->calculateMoney($rawDesonerado > 0 ? $rawDesonerado : $computedDesonerado, $calculationMethod),
            'price_source' => $usesAnalyticPrice ? 'analytic' : ($rawOnerado > 0 || $rawDesonerado > 0 ? 'stored' : 'empty'),
            'missing_price_items_count' => $missingPriceItems,
            'items_count' => $items->count(),
            'items' => $items->all(),
        ];
    }

    private function serializeAnaliticoItemForState(OrcamentoComposicaoAnaliticoItem $item, string $state, array $sources, ?Tenant $tenant = null, array $visited = [], ?string $calculationMethod = 'truncate_2'): array
    {
        $type = $item->tipo_item === 'composicao' ? 'composicao' : 'insumo';
        $source = $sources[$type === 'insumo' ? 'insumos' : 'composicoes']->get($state.'|'.$this->codeKey($item->codigo_item));
        $coefficient = (float) ($item->coeficiente ?? 0);
        $missingPriceItems = $source ? 0 : 1;

        if ($source instanceof OrcamentoInsumo) {
            $unitOnerado = (float) ($source->preco_nao_desonerado ?? 0);
            $unitDesonerado = (float) ($source->preco_desonerado ?? 0);
        } elseif ($source instanceof OrcamentoComposicao) {
            $summary = $tenant
                ? $this->fastComposicaoPriceSummary($source)
                : $this->rawComposicaoPriceSummary($source);

            if ($tenant && (float) $summary['effective_preco_onerado'] <= 0 && (float) $summary['effective_preco_desonerado'] <= 0) {
                $summary = $this->composicaoPriceSummary($tenant, $source, $visited);
            }

            $sourceCalculationMethod = $this->calculationMethodForComposicao($source);
            $unitOnerado = $this->calculateMoney($summary['effective_preco_onerado'], $sourceCalculationMethod);
            $unitDesonerado = $this->calculateMoney($summary['effective_preco_desonerado'], $sourceCalculationMethod);
            $missingPriceItems = (int) $summary['missing_price_items_count'];
        } else {
            $unitOnerado = 0;
            $unitDesonerado = 0;
        }

        return [
            'id' => $state.'-'.$item->id,
            'marker' => $type === 'composicao' ? 'C' : 'I',
            'item_type' => $type,
            'item_type_label' => $type === 'composicao' ? 'Composicao' : 'Insumo',
            'composicao_id' => $source instanceof OrcamentoComposicao ? $source->id : null,
            'codigo' => $source instanceof OrcamentoInsumo ? $source->codigo_insumo : ($source instanceof OrcamentoComposicao ? $source->codigo : $item->codigo_item),
            'descricao' => $source instanceof OrcamentoInsumo ? $source->descricao : ($source instanceof OrcamentoComposicao ? $source->descricao : $item->descricao_item),
            'tipo' => $source instanceof OrcamentoInsumo
                ? ($source->classificacao ?: $this->typeLabel($source->tipo))
                : ($source instanceof OrcamentoComposicao ? $source->tipo_composicao : null),
            'unidade' => $source?->unidade ?? $item->unidade,
            'coeficiente' => $coefficient,
            'preco_unitario_onerado' => $this->calculateIntermediateMoney($unitOnerado, $calculationMethod),
            'preco_unitario_desonerado' => $this->calculateIntermediateMoney($unitDesonerado, $calculationMethod),
            'preco_onerado' => $this->calculateIntermediateMoney($unitOnerado * $coefficient, $calculationMethod),
            'preco_desonerado' => $this->calculateIntermediateMoney($unitDesonerado * $coefficient, $calculationMethod),
            'raw_preco_unitario_onerado' => $this->storeMoney($unitOnerado),
            'raw_preco_unitario_desonerado' => $this->storeMoney($unitDesonerado),
            'raw_preco_onerado' => $this->storeIntermediateMoney($unitOnerado * $coefficient, $calculationMethod),
            'raw_preco_desonerado' => $this->storeIntermediateMoney($unitDesonerado * $coefficient, $calculationMethod),
            'source_found' => (bool) $source,
            'missing_price_items_count' => $missingPriceItems,
        ];
    }

    private function fastComposicaoPriceSummary(OrcamentoComposicao $composicao): array
    {
        $rawSummary = $this->rawComposicaoPriceSummary($composicao);
        $storedItemsSummary = $this->sicro3StoredItemsPriceSummary($composicao, $rawSummary);

        if ($storedItemsSummary) {
            return $storedItemsSummary;
        }

        $itemsOnerado = (float) ($composicao->items_preco_onerado_sum ?? 0);
        $itemsDesonerado = (float) ($composicao->items_preco_desonerado_sum ?? 0);
        $rawOnerado = (float) $rawSummary['preco_onerado'];
        $rawDesonerado = (float) $rawSummary['preco_desonerado'];
        $calculationMethod = $this->calculationMethodForComposicao($composicao);
        $computedOnerado = $this->calculateMoney($itemsOnerado, $calculationMethod);
        $computedDesonerado = $this->calculateMoney($itemsDesonerado, $calculationMethod);
        $usesItemsPrice = ($rawOnerado <= 0 && $itemsOnerado > 0) || ($rawDesonerado <= 0 && $itemsDesonerado > 0);

        return array_merge($rawSummary, [
            'computed_preco_onerado' => $computedOnerado,
            'computed_preco_desonerado' => $computedDesonerado,
            'effective_preco_onerado' => $this->calculateMoney($rawOnerado > 0 ? $rawOnerado : $computedOnerado, $calculationMethod),
            'effective_preco_desonerado' => $this->calculateMoney($rawDesonerado > 0 ? $rawDesonerado : $computedDesonerado, $calculationMethod),
            'price_source' => $usesItemsPrice ? 'items' : ($rawOnerado > 0 || $rawDesonerado > 0 ? 'stored' : 'empty'),
            'missing_price_items_count' => 0,
            'analytic_items_count' => (int) ($composicao->items_count ?? 0),
        ]);
    }

    private function sicro3StoredItemsPriceSummary(OrcamentoComposicao $composicao, ?array $rawSummary = null): ?array
    {
        $calculationMethod = $this->calculationMethodForComposicao($composicao);

        if ($calculationMethod !== self::SICRO3_CALCULATION_METHOD) {
            return null;
        }

        $itemsCount = $composicao->relationLoaded('items')
            ? $composicao->items->count()
            : (int) ($composicao->items_count ?? 0);

        if ($itemsCount <= 0) {
            return null;
        }

        $items = $composicao->relationLoaded('items')
            ? $composicao->items
            : $composicao->items()->get();

        if ($items->isEmpty()) {
            return null;
        }

        $rawSummary ??= $this->rawComposicaoPriceSummary($composicao);
        $manualSummary = $this->sicro3ManualCalculation($composicao, $items);
        $computedOnerado = $manualSummary['preco_onerado'];
        $computedDesonerado = $manualSummary['preco_desonerado'];

        return array_merge($rawSummary, [
            'computed_preco_onerado' => $computedOnerado,
            'computed_preco_desonerado' => $computedDesonerado,
            'effective_preco_onerado' => $computedOnerado,
            'effective_preco_desonerado' => $computedDesonerado,
            'price_source' => 'items',
            'missing_price_items_count' => 0,
            'analytic_items_count' => $items->count(),
        ]);
    }

    private function composicaoListPriceSummaries(Tenant $tenant, \Illuminate\Support\Collection $composicoes): array
    {
        $summaries = [];
        $needsAnalyticFallback = collect();

        foreach ($composicoes as $composicao) {
            $summary = $this->fastComposicaoPriceSummary($composicao);
            $summaries[$composicao->id] = $summary;

            if ((float) $summary['effective_preco_onerado'] <= 0 && (float) $summary['effective_preco_desonerado'] <= 0) {
                $needsAnalyticFallback->push($composicao);
            }
        }

        if ($needsAnalyticFallback->isEmpty()) {
            return $summaries;
        }

        foreach ($this->composicaoListAnalyticSummaries($tenant, $needsAnalyticFallback) as $id => $summary) {
            $summaries[$id] = $summary;
        }

        return $summaries;
    }

    private function composicaoListAnalyticSummaries(Tenant $tenant, \Illuminate\Support\Collection $composicoes): array
    {
        $references = [];
        $models = [];
        $codes = [];
        $dates = [];

        foreach ($composicoes as $composicao) {
            $referenceDate = $this->firstReferenceDateForComposicao($composicao);

            if (! $referenceDate) {
                continue;
            }

            $references[$composicao->id] = $referenceDate;
            $models[] = mb_strtoupper((string) $composicao->modelo);
            $codes[] = (string) $composicao->codigo;
            $dates[] = $referenceDate;
        }

        if ($references === []) {
            return [];
        }

        $query = OrcamentoComposicaoAnaliticoItem::query()
            ->where(function (Builder $query) use ($tenant): void {
                $query->where('tenant_id', $tenant->id)->orWhere('is_global', true);
            })
            ->whereIn('modelo', array_values(array_unique($models)));

        $this->whereCodeInMatches($query, 'codigo_composicao', $codes);
        $this->whereDateIn($query, 'data_referencia', array_values(array_unique($dates)));

        $analyticItems = $query
            ->orderBy('id')
            ->get()
            ->groupBy(fn (OrcamentoComposicaoAnaliticoItem $item): string => implode("\x1F", [
                mb_strtoupper((string) $item->modelo),
                $this->codeKey((string) $item->codigo_composicao),
                $item->data_referencia?->toDateString() ?? (string) $item->getRawOriginal('data_referencia'),
            ]))
            ->map(fn ($group) => $group
                ->unique(fn (OrcamentoComposicaoAnaliticoItem $item): string => implode('|', [
                    $item->tipo_item,
                    $this->codeKey((string) $item->codigo_item),
                    $item->data_referencia?->toDateString() ?? '',
                    (string) $item->coeficiente,
                ]))
                ->values());

        $states = $composicoes->pluck('uf')->filter()->unique()->values()->all();
        $allAnalyticItems = $analyticItems->reduce(
            fn (\Illuminate\Support\Collection $carry, \Illuminate\Support\Collection $group): \Illuminate\Support\Collection => $carry->merge($group),
            collect(),
        );
        $sourcesByDate = [];

        foreach (array_values(array_unique($dates)) as $referenceDate) {
            foreach (array_values(array_unique($models)) as $model) {
                $sourceKey = $model."\x1F".$referenceDate;
                $dateItems = $allAnalyticItems
                    ->filter(fn (OrcamentoComposicaoAnaliticoItem $item): bool => (
                        mb_strtoupper((string) $item->modelo) === $model
                        && ($item->data_referencia?->toDateString() ?? '') === $referenceDate
                    ))
                    ->values();

                $sourcesByDate[$sourceKey] = [
                    'insumos' => $this->analyticInsumoSourcesByState(
                        $tenant,
                        $model,
                        $states,
                        $dateItems
                            ->where('tipo_item', 'insumo')
                            ->pluck('codigo_item')
                            ->filter()
                            ->values()
                            ->all(),
                        $referenceDate,
                    ),
                    'composicoes' => $this->analyticComposicaoSourcesByState(
                        $tenant,
                        $model,
                        $states,
                        $dateItems
                            ->where('tipo_item', 'composicao')
                            ->pluck('codigo_item')
                            ->filter()
                            ->values()
                            ->all(),
                        $referenceDate,
                    ),
                ];
            }
        }

        $summaries = [];

        foreach ($composicoes as $composicao) {
            $referenceDate = $references[$composicao->id] ?? null;

            if (! $referenceDate) {
                continue;
            }

            $groupKey = implode("\x1F", [
                mb_strtoupper((string) $composicao->modelo),
                $this->codeKey((string) $composicao->codigo),
                $referenceDate,
            ]);
            $items = $analyticItems->get($groupKey, collect());

            if ($items->isEmpty()) {
                continue;
            }

            $sourceKey = mb_strtoupper((string) $composicao->modelo)."\x1F".$referenceDate;
            $sources = $sourcesByDate[$sourceKey] ?? ['insumos' => collect(), 'composicoes' => collect()];
            $computedOnerado = 0.0;
            $computedDesonerado = 0.0;
            $missingPriceItems = 0;
            $calculationMethod = $this->calculationMethodForComposicao($composicao);

            foreach ($items as $item) {
                $type = $item->tipo_item === 'composicao' ? 'composicoes' : 'insumos';
                $source = $sources[$type]->get($composicao->uf.'|'.$this->codeKey((string) $item->codigo_item));
                $coefficient = (float) ($item->coeficiente ?? 0);

                if ($source instanceof OrcamentoInsumo) {
                    $computedOnerado += $this->calculateIntermediateMoney((float) ($source->preco_nao_desonerado ?? 0) * $coefficient, $calculationMethod);
                    $computedDesonerado += $this->calculateIntermediateMoney((float) ($source->preco_desonerado ?? 0) * $coefficient, $calculationMethod);

                    continue;
                }

                if ($source instanceof OrcamentoComposicao) {
                    $sourceCalculationMethod = $this->calculationMethodForComposicao($source);
                    $computedOnerado += $this->calculateIntermediateMoney($this->calculateMoney($source->preco_onerado, $sourceCalculationMethod) * $coefficient, $calculationMethod);
                    $computedDesonerado += $this->calculateIntermediateMoney($this->calculateMoney($source->preco_desonerado, $sourceCalculationMethod) * $coefficient, $calculationMethod);

                    continue;
                }

                $missingPriceItems++;
            }

            $rawSummary = $this->rawComposicaoPriceSummary($composicao);
            $rawOnerado = (float) $rawSummary['preco_onerado'];
            $rawDesonerado = (float) $rawSummary['preco_desonerado'];
            $computedOnerado = $this->calculateMoney($computedOnerado, $calculationMethod);
            $computedDesonerado = $this->calculateMoney($computedDesonerado, $calculationMethod);
            $usesAnalyticPrice = ($rawOnerado <= 0 && $computedOnerado > 0) || ($rawDesonerado <= 0 && $computedDesonerado > 0);

            $summaries[$composicao->id] = array_merge($rawSummary, [
                'computed_preco_onerado' => $computedOnerado,
                'computed_preco_desonerado' => $computedDesonerado,
                'effective_preco_onerado' => $this->calculateMoney($rawOnerado > 0 ? $rawOnerado : $computedOnerado, $calculationMethod),
                'effective_preco_desonerado' => $this->calculateMoney($rawDesonerado > 0 ? $rawDesonerado : $computedDesonerado, $calculationMethod),
                'price_source' => $usesAnalyticPrice ? 'analytic' : ($rawOnerado > 0 || $rawDesonerado > 0 ? 'stored' : 'empty'),
                'missing_price_items_count' => $missingPriceItems,
                'analytic_items_count' => $items->count(),
            ]);
        }

        return $summaries;
    }

    private function composicaoPriceSummary(Tenant $tenant, OrcamentoComposicao $composicao, array $visited = []): array
    {
        $rawSummary = $this->rawComposicaoPriceSummary($composicao);

        if (isset($visited[$composicao->id])) {
            return $rawSummary;
        }

        $cacheKey = $tenant->id."\x1F".$composicao->id;

        if (isset($this->composicaoPriceSummaryCache[$cacheKey])) {
            return $this->composicaoPriceSummaryCache[$cacheKey];
        }

        $visited[$composicao->id] = true;
        $referenceDate = $this->firstReferenceDateForComposicao($composicao);
        $analyticItems = $this->analyticItemsForComposicao($tenant, $composicao, $referenceDate);

        if ($analyticItems->isEmpty()) {
            return $this->composicaoPriceSummaryCache[$cacheKey] = $this->sicro3StoredItemsPriceSummary($composicao, $rawSummary) ?? $rawSummary;
        }

        $sources = $this->analyticSourcesByState(
            $tenant,
            mb_strtoupper((string) $composicao->modelo),
            [$composicao->uf],
            $analyticItems,
            $referenceDate,
        );
        $calculationMethod = $this->calculationMethodForComposicao($composicao);
        $items = $analyticItems
            ->map(fn (OrcamentoComposicaoAnaliticoItem $item): array => $this->serializeAnaliticoItemForState($item, $composicao->uf, $sources, $tenant, $visited, $calculationMethod))
            ->values();
        $computedOnerado = $this->calculateMoney($items->sum('raw_preco_onerado'), $calculationMethod);
        $computedDesonerado = $this->calculateMoney($items->sum('raw_preco_desonerado'), $calculationMethod);
        $rawOnerado = (float) $rawSummary['preco_onerado'];
        $rawDesonerado = (float) $rawSummary['preco_desonerado'];
        $usesAnalyticPrice = ($rawOnerado <= 0 && $computedOnerado > 0) || ($rawDesonerado <= 0 && $computedDesonerado > 0);

        return $this->composicaoPriceSummaryCache[$cacheKey] = array_merge($rawSummary, [
            'computed_preco_onerado' => $computedOnerado,
            'computed_preco_desonerado' => $computedDesonerado,
            'effective_preco_onerado' => $this->calculateMoney($rawOnerado > 0 ? $rawOnerado : $computedOnerado, $calculationMethod),
            'effective_preco_desonerado' => $this->calculateMoney($rawDesonerado > 0 ? $rawDesonerado : $computedDesonerado, $calculationMethod),
            'price_source' => $usesAnalyticPrice ? 'analytic' : ($rawOnerado > 0 || $rawDesonerado > 0 ? 'stored' : 'empty'),
            'missing_price_items_count' => (int) $items->sum('missing_price_items_count'),
            'analytic_items_count' => $items->count(),
        ]);
    }

    private function rawComposicaoPriceSummary(OrcamentoComposicao $composicao): array
    {
        $rawOnerado = (float) ($composicao->preco_onerado ?? 0);
        $rawDesonerado = (float) ($composicao->preco_desonerado ?? 0);

        return [
            'preco_onerado' => $rawOnerado,
            'preco_desonerado' => $rawDesonerado,
            'computed_preco_onerado' => 0.0,
            'computed_preco_desonerado' => 0.0,
            'effective_preco_onerado' => $rawOnerado,
            'effective_preco_desonerado' => $rawDesonerado,
            'price_source' => $rawOnerado > 0 || $rawDesonerado > 0 ? 'stored' : 'empty',
            'missing_price_items_count' => 0,
            'analytic_items_count' => 0,
        ];
    }

    private function itemDataFromInsumo(Request $request, Tenant $tenant, OrcamentoComposicao $composicao, OrcamentoInsumo $insumo, float $coefficient, array $sicro3Context = []): array
    {
        return $this->itemDataFromInsumoForUser($tenant->id, $composicao, $insumo, $coefficient, $request->user()->id, $sicro3Context);
    }

    private function sicro3ItemContextFromPayload(array $data, OrcamentoComposicao $composicao): array
    {
        $referencedItem = null;

        if (! empty($data['sicro3_referenced_item_id'])) {
            $referencedItem = $composicao->items()
                ->whereKey((int) $data['sicro3_referenced_item_id'])
                ->first();

            if (! $referencedItem instanceof OrcamentoComposicaoItem) {
                throw ValidationException::withMessages([
                    'sicro3_referenced_item_id' => 'Selecione um item valido desta composicao.',
                ]);
            }
        }

        return [
            'utilizacao_operativa' => $this->parseDecimal($data['sicro3_utilizacao_operativa'] ?? null),
            'utilizacao_improdutiva' => $this->parseDecimal($data['sicro3_utilizacao_improdutiva'] ?? null),
            'referenced_item_id' => $referencedItem?->id,
            'referenced_item_code' => $referencedItem?->codigo,
            'referenced_item_description' => $referencedItem?->descricao,
            'transport_ln_code' => $this->blankToNull($data['sicro3_transport_ln_code'] ?? null),
            'transport_rp_code' => $this->blankToNull($data['sicro3_transport_rp_code'] ?? null),
            'transport_p_code' => $this->blankToNull($data['sicro3_transport_p_code'] ?? null),
            'transport_fe_code' => $this->blankToNull($data['sicro3_transport_fe_code'] ?? null),
        ];
    }

    private function withSelectedSicro3TransportComposition(array $context, string $transportType, OrcamentoComposicao $transportComposition): array
    {
        $context['transport_ln_code'] = null;
        $context['transport_rp_code'] = null;
        $context['transport_p_code'] = null;
        $context['transport_fe_code'] = null;

        $transportCodeKey = match ($transportType) {
            'ln' => 'transport_ln_code',
            'rp' => 'transport_rp_code',
            'p' => 'transport_p_code',
            'fe' => 'transport_fe_code',
            default => null,
        };

        if ($transportCodeKey !== null) {
            $context[$transportCodeKey] = $transportComposition->codigo;
        }

        return $context;
    }

    private function itemDataFromInsumoForUser(int $tenantId, OrcamentoComposicao $composicao, OrcamentoInsumo $insumo, float $coefficient, int $userId, array $sicro3Context = []): array
    {
        $unitOnerado = (float) ($insumo->preco_nao_desonerado ?? 0);
        $unitDesonerado = (float) ($insumo->preco_desonerado ?? 0);
        $isSicro3Parent = $this->isSicro3Composicao($composicao);
        $sicro3Section = $isSicro3Parent
            ? $this->sicro3SectionFromType($insumo->classificacao ?: $this->typeLabel($insumo->tipo))
            : null;
        $custoImprodutivoOnerado = $sicro3Section === 'equipamentos'
            ? (float) ($insumo->custo_improdutivo_nao_desonerado ?? $unitOnerado)
            : null;
        $custoImprodutivoDesonerado = $sicro3Section === 'equipamentos'
            ? (float) ($insumo->custo_improdutivo_desonerado ?? $custoImprodutivoOnerado ?? $unitDesonerado)
            : null;
        $utilizacaoOperativa = $sicro3Section === 'equipamentos'
            ? (float) ($sicro3Context['utilizacao_operativa'] ?? 1)
            : null;
        $utilizacaoImprodutiva = $sicro3Section === 'equipamentos'
            ? (float) ($sicro3Context['utilizacao_improdutiva'] ?? 0)
            : null;
        $lineTotals = $isSicro3Parent
            ? $this->sicro3ItemLineTotals(
                $sicro3Section,
                $coefficient,
                $unitOnerado,
                $unitDesonerado,
                $custoImprodutivoOnerado,
                $custoImprodutivoDesonerado,
                $utilizacaoOperativa,
                $utilizacaoImprodutiva,
            )
            : [
                'onerado' => $unitOnerado * $coefficient,
                'desonerado' => $unitDesonerado * $coefficient,
            ];

        return [
            'tenant_id' => $tenantId,
            'orcamento_composicao_id' => $composicao->id,
            'created_by_id' => $userId,
            'item_type' => 'insumo',
            'sicro3_section' => $sicro3Section,
            'orcamento_insumo_id' => $insumo->id,
            'child_composicao_id' => null,
            'base' => $insumo->banco,
            'codigo' => $insumo->codigo_insumo,
            'descricao' => $insumo->descricao,
            'tipo' => $insumo->classificacao ?: $this->typeLabel($insumo->tipo),
            'unidade' => $insumo->unidade,
            'preco_unitario_onerado' => $unitOnerado,
            'preco_unitario_desonerado' => $unitDesonerado,
            'custo_improdutivo_onerado' => $custoImprodutivoOnerado,
            'custo_improdutivo_desonerado' => $custoImprodutivoDesonerado,
            'coeficiente' => $coefficient,
            'sicro3_utilizacao_operativa' => $utilizacaoOperativa,
            'sicro3_utilizacao_improdutiva' => $utilizacaoImprodutiva,
            'sicro3_referenced_item_id' => $sicro3Context['referenced_item_id'] ?? null,
            'sicro3_referenced_item_code' => $sicro3Context['referenced_item_code'] ?? null,
            'sicro3_referenced_item_description' => $sicro3Context['referenced_item_description'] ?? null,
            'sicro3_transport_ln_code' => $sicro3Context['transport_ln_code'] ?? null,
            'sicro3_transport_rp_code' => $sicro3Context['transport_rp_code'] ?? null,
            'sicro3_transport_p_code' => $sicro3Context['transport_p_code'] ?? null,
            'sicro3_transport_fe_code' => $sicro3Context['transport_fe_code'] ?? null,
            'preco_onerado' => $this->storeMoney($lineTotals['onerado']),
            'preco_desonerado' => $this->storeMoney($lineTotals['desonerado']),
        ];
    }

    private function itemDataFromChildComposicao(Request $request, Tenant $tenant, OrcamentoComposicao $parent, OrcamentoComposicao $child, float $coefficient, ?string $sicro3Section = null, array $sicro3Context = []): array
    {
        return $this->itemDataFromChildComposicaoForUser($tenant->id, $parent, $child, $coefficient, $request->user()->id, $sicro3Section, $sicro3Context);
    }

    private function itemDataFromChildComposicaoForUser(int $tenantId, OrcamentoComposicao $parent, OrcamentoComposicao $child, float $coefficient, int $userId, ?string $sicro3Section = null, array $sicro3Context = []): array
    {
        $unitOnerado = (float) ($child->preco_onerado ?? 0);
        $unitDesonerado = (float) ($child->preco_desonerado ?? 0);
        $lineTotals = $this->isSicro3Composicao($parent)
            ? $this->sicro3ItemLineTotals($sicro3Section, $coefficient, $unitOnerado, $unitDesonerado)
            : [
                'onerado' => $unitOnerado * $coefficient,
                'desonerado' => $unitDesonerado * $coefficient,
            ];

        return [
            'tenant_id' => $tenantId,
            'orcamento_composicao_id' => $parent->id,
            'created_by_id' => $userId,
            'item_type' => 'composicao',
            'sicro3_section' => $sicro3Section,
            'orcamento_insumo_id' => null,
            'child_composicao_id' => $child->id,
            'base' => $child->modelo,
            'codigo' => $child->codigo,
            'descricao' => $child->descricao,
            'tipo' => $child->tipo_composicao,
            'unidade' => $child->unidade,
            'preco_unitario_onerado' => $unitOnerado,
            'preco_unitario_desonerado' => $unitDesonerado,
            'coeficiente' => $coefficient,
            'sicro3_referenced_item_id' => $sicro3Context['referenced_item_id'] ?? null,
            'sicro3_referenced_item_code' => $sicro3Context['referenced_item_code'] ?? null,
            'sicro3_referenced_item_description' => $sicro3Context['referenced_item_description'] ?? null,
            'sicro3_transport_ln_code' => $sicro3Context['transport_ln_code'] ?? null,
            'sicro3_transport_rp_code' => $sicro3Context['transport_rp_code'] ?? null,
            'sicro3_transport_p_code' => $sicro3Context['transport_p_code'] ?? null,
            'sicro3_transport_fe_code' => $sicro3Context['transport_fe_code'] ?? null,
            'preco_onerado' => $this->storeMoney($lineTotals['onerado']),
            'preco_desonerado' => $this->storeMoney($lineTotals['desonerado']),
        ];
    }

    private function recalculateComposicaoTotals(OrcamentoComposicao $composicao): void
    {
        if ($this->isSicro3Composicao($composicao)) {
            $summary = $this->sicro3ManualCalculation($composicao);

            $composicao->forceFill([
                'preco_onerado' => $this->storeMoney($summary['preco_onerado']),
                'preco_desonerado' => $this->storeMoney($summary['preco_desonerado']),
            ])->save();

            return;
        }

        $totals = $composicao->items()
            ->selectRaw('COALESCE(SUM(preco_onerado), 0) as preco_onerado_total, COALESCE(SUM(preco_desonerado), 0) as preco_desonerado_total')
            ->first();

        $composicao->forceFill([
            'preco_onerado' => $this->storeMoney($totals?->preco_onerado_total ?? 0),
            'preco_desonerado' => $this->storeMoney($totals?->preco_desonerado_total ?? 0),
        ])->save();
    }

    private function sicro3ManualCalculation(OrcamentoComposicao $composicao, ?\Illuminate\Support\Collection $items = null): array
    {
        $calculationMethod = $this->calculationMethodForComposicao($composicao);
        $items ??= $composicao->relationLoaded('items')
            ? $composicao->items
            : $composicao->items()->get();

        $sections = [
            'equipamentos' => ['onerado' => 0.0, 'desonerado' => 0.0],
            'mao_de_obra' => ['onerado' => 0.0, 'desonerado' => 0.0],
            'material' => ['onerado' => 0.0, 'desonerado' => 0.0],
            'atividades_auxiliares' => ['onerado' => 0.0, 'desonerado' => 0.0],
            'tempo_fixo' => ['onerado' => 0.0, 'desonerado' => 0.0],
            'momento_transporte' => ['onerado' => 0.0, 'desonerado' => 0.0],
        ];
        $ficEquipmentBaseOnerado = 0.0;
        $ficEquipmentBaseDesonerado = 0.0;

        foreach ($items as $item) {
            if (! $item instanceof OrcamentoComposicaoItem) {
                continue;
            }

            $section = $item->sicro3_section ?: $this->sicro3SectionFromInsumoItem($item);
            $line = $this->sicro3ItemLineTotals(
                $section,
                (float) ($item->coeficiente ?? 0),
                (float) ($item->preco_unitario_onerado ?? 0),
                (float) ($item->preco_unitario_desonerado ?? 0),
                $item->custo_improdutivo_onerado !== null ? (float) $item->custo_improdutivo_onerado : null,
                $item->custo_improdutivo_desonerado !== null ? (float) $item->custo_improdutivo_desonerado : null,
                $item->sicro3_utilizacao_operativa !== null ? (float) $item->sicro3_utilizacao_operativa : null,
                $item->sicro3_utilizacao_improdutiva !== null ? (float) $item->sicro3_utilizacao_improdutiva : null,
            );

            if (isset($sections[$section])) {
                $sections[$section]['onerado'] += $line['onerado'];
                $sections[$section]['desonerado'] += $line['desonerado'];
            }

            if ($section === 'equipamentos') {
                $ficEquipmentBaseOnerado += $line['fic_base_onerado'];
                $ficEquipmentBaseDesonerado += $line['fic_base_desonerado'];
            }
        }

        $production = max((float) ($composicao->producao_equipe ?? 1), 0.000001);
        $fic = max((float) ($composicao->fator_influencia_chuvas ?? 0), 0);
        $additionalLabor = (float) ($composicao->adicional_mao_obra ?? 0);

        $executionHourlyOnerado = $sections['equipamentos']['onerado'] + $sections['mao_de_obra']['onerado'] + $additionalLabor;
        $executionHourlyDesonerado = $sections['equipamentos']['desonerado'] + $sections['mao_de_obra']['desonerado'] + $additionalLabor;
        $executionUnitOnerado = $this->calculateIntermediateMoney($executionHourlyOnerado / $production, $calculationMethod);
        $executionUnitDesonerado = $this->calculateIntermediateMoney($executionHourlyDesonerado / $production, $calculationMethod);

        $ficBaseOnerado = $ficEquipmentBaseOnerado + $sections['mao_de_obra']['onerado'] + $additionalLabor;
        $ficBaseDesonerado = $ficEquipmentBaseDesonerado + $sections['mao_de_obra']['desonerado'] + $additionalLabor;
        $ficCostOnerado = $this->calculateIntermediateMoney(($ficBaseOnerado / $production) * $fic, $calculationMethod);
        $ficCostDesonerado = $this->calculateIntermediateMoney(($ficBaseDesonerado / $production) * $fic, $calculationMethod);

        $directSectionsOnerado = $sections['material']['onerado']
            + $sections['atividades_auxiliares']['onerado']
            + $sections['tempo_fixo']['onerado']
            + $sections['momento_transporte']['onerado'];
        $directSectionsDesonerado = $sections['material']['desonerado']
            + $sections['atividades_auxiliares']['desonerado']
            + $sections['tempo_fixo']['desonerado']
            + $sections['momento_transporte']['desonerado'];

        return [
            'sections' => collect($sections)->map(fn (array $totals): array => [
                'onerado' => $this->calculateIntermediateMoney($totals['onerado'], $calculationMethod),
                'desonerado' => $this->calculateIntermediateMoney($totals['desonerado'], $calculationMethod),
            ])->all(),
            'producao_equipe' => $production,
            'fic' => $fic,
            'custo_horario_execucao_onerado' => $this->calculateIntermediateMoney($executionHourlyOnerado, $calculationMethod),
            'custo_horario_execucao_desonerado' => $this->calculateIntermediateMoney($executionHourlyDesonerado, $calculationMethod),
            'custo_unitario_execucao_onerado' => $executionUnitOnerado,
            'custo_unitario_execucao_desonerado' => $executionUnitDesonerado,
            'custo_fic_onerado' => $ficCostOnerado,
            'custo_fic_desonerado' => $ficCostDesonerado,
            'preco_onerado' => $this->calculateMoney($executionUnitOnerado + $ficCostOnerado + $directSectionsOnerado, $calculationMethod),
            'preco_desonerado' => $this->calculateMoney($executionUnitDesonerado + $ficCostDesonerado + $directSectionsDesonerado, $calculationMethod),
        ];
    }

    private function sicro3ItemLineTotals(
        ?string $section,
        float $coefficient,
        float $unitOnerado,
        float $unitDesonerado,
        ?float $custoImprodutivoOnerado = null,
        ?float $custoImprodutivoDesonerado = null,
        ?float $utilizacaoOperativa = null,
        ?float $utilizacaoImprodutiva = null,
    ): array {
        if ($section === 'equipamentos') {
            $unitDesonerado = $unitDesonerado > 0 ? $unitDesonerado : $unitOnerado;
            $custoImprodutivoOnerado ??= $unitOnerado;
            $custoImprodutivoDesonerado ??= $custoImprodutivoOnerado;
            $utilizacaoOperativa ??= 1.0;
            $utilizacaoImprodutiva ??= 0.0;

            return [
                'onerado' => $coefficient * (($unitOnerado * $utilizacaoOperativa) + ($custoImprodutivoOnerado * $utilizacaoImprodutiva)),
                'desonerado' => $coefficient * (($unitDesonerado * $utilizacaoOperativa) + ($custoImprodutivoDesonerado * $utilizacaoImprodutiva)),
                'fic_base_onerado' => $coefficient * $custoImprodutivoOnerado,
                'fic_base_desonerado' => $coefficient * $custoImprodutivoDesonerado,
            ];
        }

        $lineOnerado = $unitOnerado * $coefficient;
        $lineDesonerado = ($unitDesonerado > 0 ? $unitDesonerado : $unitOnerado) * $coefficient;

        return [
            'onerado' => $lineOnerado,
            'desonerado' => $lineDesonerado,
            'fic_base_onerado' => $section === 'mao_de_obra' ? $lineOnerado : 0.0,
            'fic_base_desonerado' => $section === 'mao_de_obra' ? $lineDesonerado : 0.0,
        ];
    }

    private function calculationMethodForComposicao(OrcamentoComposicao $composicao): string
    {
        return mb_strtoupper((string) $composicao->modelo) === 'SICRO3'
            ? self::SICRO3_CALCULATION_METHOD
            : ($composicao->metodo_calculo ?: 'truncate_2');
    }

    private function storeIntermediateMoney(float|int|string|null $value, ?string $method = 'truncate_2'): float
    {
        return ($method ?: 'truncate_2') === self::SICRO3_CALCULATION_METHOD
            ? $this->storeMoney($this->calculateIntermediateMoney($value, $method))
            : $this->storeMoney($value);
    }

    private function storeMoney(float|int|string|null $value): float
    {
        return round((float) ($value ?? 0), 6);
    }

    private function calculateIntermediateMoney(float|int|string|null $value, ?string $method = 'truncate_2'): float
    {
        $value = (float) ($value ?? 0);

        return ($method ?: 'truncate_2') === self::SICRO3_CALCULATION_METHOD
            ? round($value, 4)
            : $this->calculateMoney($value, $method);
    }

    private function calculateMoney(float|int|string|null $value, ?string $method = 'truncate_2'): float
    {
        $value = (float) ($value ?? 0);

        return match ($method ?: 'truncate_2') {
            self::SICRO3_CALCULATION_METHOD => round($value, 2),
            'round_2' => round($value, 2),
            'none' => $value,
            default => $this->truncateMoney($value),
        };
    }

    private function truncateMoney(float|int|string|null $value, int $decimals = 2): float
    {
        $value = (float) ($value ?? 0);
        $factor = 10 ** $decimals;
        $scaled = $value * $factor;
        $epsilon = 1e-9;

        return ($value < 0 ? ceil($scaled - $epsilon) : floor($scaled + $epsilon)) / $factor;
    }

    private function parseCoefficient(?string $value): float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 1.0;
        }

        $value = str_replace([' ', 'R$'], '', $value);

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        if (! is_numeric($value) || (float) $value <= 0) {
            return 1.0;
        }

        return round((float) $value, 6);
    }

    private function compositionFormOptions(Tenant $tenant): array
    {
        return [
            'states' => collect(self::BRAZILIAN_STATES)
                ->map(fn (string $uf): array => ['value' => $uf, 'label' => $this->stateLabel($uf)])
                ->values()
                ->all(),
            'types' => [
                ['value' => 'ASTU - ASSENTAMENTO DE TUBOS E PECAS', 'label' => 'ASTU - Assentamento de tubos e pecas'],
                ['value' => 'CANT - CANTEIRO DE OBRAS', 'label' => 'CANT - Canteiro de obras'],
                ['value' => 'COBE - COBERTURA', 'label' => 'COBE - Cobertura'],
                ['value' => 'CHOR - CUSTOS HORARIOS DE MAQUINAS E EQUIPAMENTOS', 'label' => 'CHOR - Custos horarios de maquinas e equipamentos'],
                ['value' => 'DROP - DRENAGEM/OBRAS DE CONTENCAO / POCOS DE VISITA E CAIXAS', 'label' => 'DROP - Drenagem/obras de contencao / pocos de visita e caixas'],
                ['value' => 'ESCO - ESCORAMENTO', 'label' => 'ESCO - Escoramento'],
                ['value' => 'ESQV - ESQUADRIAS/FERRAGENS/VIDROS', 'label' => 'ESQV - Esquadrias/ferragens/vidros'],
                ['value' => 'FOMA - FORNECIMENTO DE MATERIAIS E EQUIPAMENTOS', 'label' => 'FOMA - Fornecimento de materiais e equipamentos'],
                ['value' => 'FUES - FUNDACOES E ESTRUTURAS', 'label' => 'FUES - Fundacoes e estruturas'],
                ['value' => 'IMPE - IMPERMEABILIZACOES E PROTECOES DIVERSAS', 'label' => 'IMPE - Impermeabilizacoes e protecoes diversas'],
                ['value' => 'INEL - INSTALACAO ELETRICA/ELETRIFICACAO E ILUMINACAO EXTERNA', 'label' => 'INEL - Instalacao eletrica/eletrificacao e iluminacao externa'],
                ['value' => 'INPR - INSTALACOES DE PRODUCAO', 'label' => 'INPR - Instalacoes de producao'],
                ['value' => 'INES - INSTALACOES ESPECIAIS', 'label' => 'INES - Instalacoes especiais'],
                ['value' => 'INHI - INSTALACOES HIDROS SANITARIAS', 'label' => 'INHI - Instalacoes hidros sanitarias'],
                ['value' => 'LIPR - LIGACOES PREDIAIS AGUA/ESGOTO/ENERGIA/TELEFONE', 'label' => 'LIPR - Ligacoes prediais agua/esgoto/energia/telefone'],
                ['value' => 'MOVT - MOVIMENTO DE TERRA', 'label' => 'MOVT - Movimento de terra'],
                ['value' => 'PARE - PAREDES/PAINEIS', 'label' => 'PARE - Paredes/paineis'],
                ['value' => 'PAVI - PAVIMENTACAO', 'label' => 'PAVI - Pavimentacao'],
                ['value' => 'PINT - PINTURAS', 'label' => 'PINT - Pinturas'],
                ['value' => 'PISO - PISOS', 'label' => 'PISO - Pisos'],
                ['value' => 'REVE - REVESTIMENTO E TRATAMENTO DE SUPERFICIES', 'label' => 'REVE - Revestimento e tratamento de superficies'],
                ['value' => 'SEDI - SERVICOS DIVERSOS', 'label' => 'SEDI - Servicos diversos'],
                ['value' => 'SEEM - SERVICOS EMPREITADOS', 'label' => 'SEEM - Servicos empreitados'],
                ['value' => 'SEES - SERVICOS ESPECIAIS', 'label' => 'SEES - Servicos especiais'],
                ['value' => 'SEOP - SERVICOS OPERACIONAIS', 'label' => 'SEOP - Servicos operacionais'],
                ['value' => 'SERP - SERVICOS PRELIMINARES', 'label' => 'SERP - Servicos preliminares'],
                ['value' => 'SERT - SERVICOS TECNICOS', 'label' => 'SERT - Servicos tecnicos'],
                ['value' => 'TRAN - TRANSPORTES, CARGAS E DESCARGAS', 'label' => 'TRAN - Transportes, cargas e descargas'],
                ['value' => 'URBA - URBANIZACAO', 'label' => 'URBA - Urbanizacao'],
            ],
            'calculationMethods' => [
                ['value' => 'truncate_2', 'label' => 'Truncar em 2 casas decimais', 'badge' => 'Padrao TCU'],
                ['value' => 'round_2', 'label' => 'Arredondar em 2 casas decimais', 'badge' => null],
                ['value' => 'none', 'label' => 'Nao arredondar', 'badge' => null],
            ],
            'baseReferences' => $this->compositionBaseReferences($tenant),
        ];
    }

    private function compositionBaseReferences(Tenant $tenant): array
    {
        return Cache::remember('orcamento:composition-base-references:v2', now()->addMinute(), function (): array {
            return $this->freshCompositionBaseReferences();
        });
    }

    private function freshCompositionBaseReferences(): array
    {
        $insumoReferences = OrcamentoInsumo::query()
            ->whereNull('tenant_id')
            ->whereIn('banco', ['SINAPI', 'SICRO3'])
            ->whereNotNull('uf')
            ->whereNotNull('data_referencia')
            ->selectRaw('banco, uf, data_referencia, COUNT(*) as total')
            ->groupBy('banco', 'uf', 'data_referencia')
            ->orderBy('banco')
            ->orderBy('uf')
            ->orderByDesc('data_referencia')
            ->get()
            ->map(fn (OrcamentoInsumo $insumo): array => [
                'codigo' => sprintf(
                    '%s-%s-%s',
                    $insumo->banco,
                    $insumo->uf,
                    $insumo->data_referencia?->format('m/Y') ?? ''
                ),
                'nome' => $insumo->banco,
                'localidade' => $this->stateLabel($insumo->uf).' - '.$insumo->uf,
                'uf' => $insumo->uf,
                'data' => $insumo->data_referencia?->format('m/Y') ?? '',
                'total' => (int) $insumo->total,
            ])
            ->filter(fn (array $reference): bool => $reference['data'] !== '')
            ->values()
            ->all();

        $composicaoReferences = [];

        DB::table('orcamento_composicoes')
            ->where('is_global', true)
            ->whereIn('modelo', ['SINAPI', 'SICRO3'])
            ->whereNotNull('uf')
            ->whereNotNull('base_references')
            ->select(['modelo', 'uf', 'base_references'])
            ->orderBy('id')
            ->cursor()
            ->each(function (object $composicao) use (&$composicaoReferences): void {
                $references = json_decode((string) $composicao->base_references, true);

                if (! is_array($references)) {
                    return;
                }

                foreach ($references as $reference) {
                    if (! is_array($reference)) {
                        continue;
                    }

                    $base = mb_strtoupper(trim((string) ($reference['nome'] ?? $composicao->modelo)));
                    $uf = mb_strtoupper(trim((string) ($reference['uf'] ?? $composicao->uf)));
                    $date = trim((string) ($reference['data'] ?? ''));

                    if (! in_array($base, ['SINAPI', 'SICRO3'], true) || $uf === '' || $date === '') {
                        continue;
                    }

                    $codigo = sprintf('%s-%s-%s', $base, $uf, $date);

                    $composicaoReferences[$codigo] = [
                        'codigo' => $codigo,
                        'nome' => $base,
                        'localidade' => $this->stateLabel($uf).' - '.$uf,
                        'uf' => $uf,
                        'data' => $date,
                        'total' => null,
                    ];
                }
            });

        $references = collect([...$insumoReferences, ...array_values($composicaoReferences)])
            ->unique('codigo')
            ->sortBy([
                ['nome', 'asc'],
                ['uf', 'asc'],
                ['data', 'desc'],
            ])
            ->values()
            ->all();

        if ($references !== []) {
            return [
                [
                    'region' => 'Bases oficiais',
                    'items' => $references,
                ],
            ];
        }

        return [
            [
                'region' => 'Bases oficiais',
                'items' => [
                    ['codigo' => 'SINAPI-PA-04/2026', 'nome' => 'SINAPI', 'localidade' => 'Para - PA', 'uf' => 'PA', 'data' => '04/2026'],
                    ['codigo' => 'SICRO3-PA-04/2026', 'nome' => 'SICRO3', 'localidade' => 'Para - PA', 'uf' => 'PA', 'data' => '04/2026'],
                ],
            ],
        ];
    }

    private function insumoOptionsForComposicao(
        Tenant $tenant,
        OrcamentoComposicao $composicao,
        ?string $base = null,
        ?string $codigo = null,
        ?string $descricao = null,
    ): array
    {
        $references = $this->normalizedCompositionReferences($composicao);
        $query = $this->insumosAvailableForTenant($tenant);

        if ($references !== []) {
            $query->where(function (Builder $query) use ($references): void {
                foreach ($references as $reference) {
                    $query->orWhere(function (Builder $query) use ($reference): void {
                        $query->where('banco', $reference['base']);

                        if ($reference['uf']) {
                            $query->where('uf', $reference['uf']);
                        }

                        if ($reference['date']) {
                            $query->whereDate('data_referencia', $reference['date']->toDateString());
                        }
                    });
                }
            });
        } else {
            $query->where('banco', mb_strtoupper((string) $composicao->modelo));
        }

        if ($base) {
            $query->where('banco', $base);
        }

        $this->applyInsensitiveLike($query, 'codigo_insumo', $codigo);
        $this->applyInsensitiveLike($query, 'descricao', $descricao);

        return $query
            ->orderBy('descricao')
            ->limit(120)
            ->get()
            ->map(fn (OrcamentoInsumo $insumo): array => $this->serializeInsumoOption($insumo))
            ->values()
            ->all();
    }

    private function composicaoOptionsForComposicao(
        Tenant $tenant,
        OrcamentoComposicao $composicao,
        ?string $base = null,
        ?string $codigo = null,
        ?string $descricao = null,
    ): array
    {
        $references = $this->normalizedCompositionReferences($composicao);
        $query = OrcamentoComposicao::query()->where('id', '!=', $composicao->id);

        if ($references !== []) {
            $query->where('is_global', true);
            $query->where(function (Builder $query) use ($references): void {
                foreach ($references as $reference) {
                    $query->orWhere(function (Builder $query) use ($reference): void {
                        $query->where('modelo', $reference['base']);

                        if ($reference['uf']) {
                            $query->where('uf', $reference['uf']);
                        }
                    });
                }
            });
        } else {
            $query->where(function (Builder $query) use ($tenant): void {
                $query->where('tenant_id', $tenant->id)->orWhere('is_global', true);
            });
        }

        if ($base) {
            $query->where('modelo', $base);
        }

        $this->applyInsensitiveLike($query, 'codigo', $codigo);
        $this->applyInsensitiveLike($query, 'descricao', $descricao);

        return $query
            ->orderBy('descricao')
            ->limit(500)
            ->get()
            ->filter(fn (OrcamentoComposicao $candidate): bool => $references === [] || $this->composicaoMatchesAnyReference($candidate, $references))
            ->take(120)
            ->map(fn (OrcamentoComposicao $composicao): array => $this->serializeComposicaoOption($composicao))
            ->values()
            ->all();
    }

    private function applyInsensitiveLike(Builder $query, string $column, ?string $value): void
    {
        if (! filled($value)) {
            return;
        }

        $query->whereRaw("LOWER({$column}) LIKE ?", ['%'.mb_strtolower($value).'%']);
    }

    private function normalizedCompositionReferences(OrcamentoComposicao $composicao): array
    {
        if (isset($this->normalizedCompositionReferencesCache[$composicao->id])) {
            return $this->normalizedCompositionReferencesCache[$composicao->id];
        }

        return $this->normalizedCompositionReferencesCache[$composicao->id] = collect($composicao->base_references ?? [])
            ->map(function (array $reference): array {
                $base = mb_strtoupper(trim((string) ($reference['nome'] ?? 'SINAPI')));

                return [
                    'base' => $base,
                    'uf' => isset($reference['uf']) ? mb_strtoupper(trim((string) $reference['uf'])) : null,
                    'date' => isset($reference['data']) ? $this->parseReferenceDateOrNull((string) $reference['data']) : null,
                ];
            })
            ->filter(fn (array $reference): bool => $reference['base'] !== '')
            ->values()
            ->all();
    }

    private function composicaoMatchesAnyReference(OrcamentoComposicao $composicao, array $references): bool
    {
        foreach ($references as $reference) {
            if ($composicao->modelo !== $reference['base']) {
                continue;
            }

            if ($reference['uf'] && $composicao->uf !== $reference['uf']) {
                continue;
            }

            if ($reference['date'] && ! $this->composicaoHasReferenceDate($composicao, $reference['date']->toDateString())) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function insumosAvailableForTenant(Tenant $tenant): Builder
    {
        return OrcamentoInsumo::query()
            ->where(function (Builder $query) use ($tenant): void {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id);
            });
    }

    private function composicoesAvailableForTenant(Tenant $tenant): Builder
    {
        return OrcamentoComposicao::query()
            ->where(function (Builder $query) use ($tenant): void {
                $query->where('tenant_id', $tenant->id)->orWhere('is_global', true);
            });
    }

    private function insumoTypeOptions(Tenant $tenant, string $bank = 'TODOS'): array
    {
        $query = $this->insumosAvailableForTenant($tenant);

        if ($bank !== 'TODOS') {
            $query->where('banco', $bank);
        }

        return $query
            ->select('classificacao', 'tipo')
            ->distinct()
            ->get()
            ->map(function (OrcamentoInsumo $insumo): ?array {
                $value = $insumo->classificacao ?: $insumo->tipo;

                if (! $value) {
                    return null;
                }

                return [
                    'value' => $value,
                    'label' => $insumo->classificacao ?: $this->typeLabel($insumo->tipo),
                ];
            })
            ->filter()
            ->unique('value')
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    private function insumoTypeOptionsByBank(Tenant $tenant): array
    {
        return collect(['TODOS', ...self::BANKS])
            ->mapWithKeys(fn (string $bank): array => [$bank => $this->insumoTypeOptions($tenant, $bank)])
            ->all();
    }

    private function insumoDateOptions(Tenant $tenant): array
    {
        return $this->insumosAvailableForTenant($tenant)
            ->whereNotNull('data_referencia')
            ->select('data_referencia')
            ->distinct()
            ->orderByDesc('data_referencia')
            ->get()
            ->map(fn (OrcamentoInsumo $insumo): array => [
                'value' => $insumo->data_referencia?->format('m/Y'),
                'label' => $insumo->data_referencia?->format('m/Y'),
            ])
            ->filter(fn (array $date): bool => filled($date['value']))
            ->values()
            ->all();
    }

    private function composicaoTypeOptions(Tenant $tenant): array
    {
        return $this->composicoesAvailableForTenant($tenant)
            ->whereNotNull('tipo_composicao')
            ->select('tipo_composicao')
            ->distinct()
            ->orderBy('tipo_composicao')
            ->pluck('tipo_composicao')
            ->filter()
            ->map(fn (string $type): array => [
                'value' => $type,
                'label' => $type,
            ])
            ->values()
            ->all();
    }

    private function typeLabel(?string $type): string
    {
        return match ($type) {
            'material' => 'Material',
            'labor' => 'Mao de obra',
            'equipment' => 'Equipamento',
            'service' => 'Servicos',
            default => 'Nao classificado',
        };
    }

    private function calculationMethodLabel(?string $method): string
    {
        return match ($method) {
            self::SICRO3_CALCULATION_METHOD => 'SICRO3: arredondar intermediarios em 4 casas e total em 2',
            'truncate_2' => 'Truncar em 2 casas decimais',
            'round_2' => 'Arredondar em 2 casas decimais',
            'none' => 'Nao arredondar',
            default => 'Nao informado',
        };
    }

    private function stateLabel(?string $uf): string
    {
        if (! $uf) {
            return 'Base propria';
        }

        return [
            'AC' => 'Acre',
            'AL' => 'Alagoas',
            'AP' => 'Amapa',
            'AM' => 'Amazonas',
            'BA' => 'Bahia',
            'CE' => 'Ceara',
            'DF' => 'Distrito Federal',
            'ES' => 'Espirito Santo',
            'GO' => 'Goias',
            'MA' => 'Maranhao',
            'MT' => 'Mato Grosso',
            'MS' => 'Mato Grosso do Sul',
            'MG' => 'Minas Gerais',
            'PA' => 'Para',
            'PB' => 'Paraiba',
            'PR' => 'Parana',
            'PE' => 'Pernambuco',
            'PI' => 'Piaui',
            'RJ' => 'Rio de Janeiro',
            'RN' => 'Rio Grande do Norte',
            'RS' => 'Rio Grande do Sul',
            'RO' => 'Rondonia',
            'RR' => 'Roraima',
            'SC' => 'Santa Catarina',
            'SP' => 'Sao Paulo',
            'SE' => 'Sergipe',
            'TO' => 'Tocantins',
        ][$uf] ?? $uf;
    }

    private function authorizeInsumoScope(Request $request, Tenant $tenant, string $scope): void
    {
        abort_unless(
            $scope === 'global'
                ? $this->canManageGlobalInsumos($request)
                : $this->canManageTenantInsumos($request, $tenant),
            403,
        );
    }

    private function canManageTenantInsumos(Request $request, Tenant $tenant): bool
    {
        return $request->user()->is_platform_admin
            || in_array($request->user()->tenantRole($tenant), ['tenant_owner', 'tenant_admin'], true);
    }

    private function canManageGlobalInsumos(Request $request): bool
    {
        return (bool) $request->user()->is_platform_admin;
    }
}

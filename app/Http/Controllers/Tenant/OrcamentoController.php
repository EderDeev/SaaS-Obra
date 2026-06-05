<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\OrcamentoComposicao;
use App\Models\OrcamentoComposicaoAnaliticoItem;
use App\Models\OrcamentoComposicaoItem;
use App\Models\OrcamentoInsumo;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrcamentoController extends Controller
{
    private const BANKS = ['SINAPI', 'SICRO', 'PROPRIA'];

    private const TYPES = ['material', 'labor', 'equipment', 'service'];

    private const CALCULATION_METHODS = ['truncate_2', 'round_2', 'none'];

    private const CSV_IMPORT_BATCH_SIZE = 500;

    private const POSTGRES_CSV_IMPORT_BATCH_SIZE = 2000;

    private const CSV_UPLOAD_MAX_KB = 102400;

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
                ->where('uf', $filters['state']);

            if ($filters['baseScope'] === 'own') {
                $query->where('modelo', 'PROPRIA');
            } else {
                $query->where('modelo', $filters['base']);
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
                ->withQueryString()
                ->through(fn (OrcamentoComposicao $composicao): array => $this->serializeComposicao($composicao));
        }

        return Inertia::render('Tenant/Orcamentos/Composicoes', [
            'tenant' => $tenant,
            'filters' => $filters,
            'hasSearched' => $hasSearched,
            'composicoes' => $composicoes,
            'totalComposicoes' => $this->composicoesAvailableForTenant($tenant)->count(),
            'canManageTenantComposicoes' => $this->canManageTenantInsumos($request, $tenant),
            'canManageGlobalComposicoes' => $this->canManageGlobalInsumos($request),
            'typeOptions' => $this->composicaoTypeOptions($tenant),
        ]);
    }

    public function createComposicao(Request $request, Tenant $tenant): Response
    {
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        return Inertia::render('Tenant/Orcamentos/Composicoes/Create', [
            'tenant' => $tenant,
            'options' => $this->compositionFormOptions(),
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
            'modelo' => ['required', 'string', Rule::in(['SINAPI'])],
            'metodo_calculo' => ['required', 'string', Rule::in(self::CALCULATION_METHODS)],
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

        $composicao = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $request->user()->id,
            'codigo' => trim($data['codigo']),
            'descricao' => trim($data['descricao']),
            'tipo_composicao' => trim($data['tipo_composicao']),
            'unidade' => mb_strtoupper(trim($data['unidade'])),
            'uf' => mb_strtoupper($data['uf']),
            'modelo' => mb_strtoupper($data['modelo']),
            'metodo_calculo' => $data['metodo_calculo'],
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
        $detail = $this->composicaoAnaliticoDetail($tenant, $composicao);

        return Inertia::render('Tenant/Orcamentos/Composicoes/Show', [
            'tenant' => $tenant,
            'composicao' => $this->serializeComposicao($composicao),
            'detail' => $detail,
            'items' => $composicao->items->map(fn (OrcamentoComposicaoItem $item): array => $this->serializeComposicaoItem($item))->values(),
            'insumoOptions' => $this->insumoOptionsForComposicao($tenant, $composicao),
            'composicaoOptions' => $this->composicaoOptionsForComposicao($tenant, $composicao),
            'insumoFormOptions' => [
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

        $data = $request->validate([
            'item_type' => ['required', Rule::in(['insumo', 'composicao'])],
            'source_id' => ['required', 'integer'],
            'coeficiente' => ['nullable', 'string', 'max:30'],
        ]);

        $coefficient = $this->parseCoefficient($data['coeficiente'] ?? null);

        if ($data['item_type'] === 'insumo') {
            $insumo = $this->insumosAvailableForTenant($tenant)->findOrFail($data['source_id']);
            $item = $this->itemDataFromInsumo($request, $tenant, $composicao, $insumo, $coefficient);
        } else {
            $child = OrcamentoComposicao::query()
                ->where(function (Builder $query) use ($tenant): void {
                    $query->where('tenant_id', $tenant->id)->orWhere('is_global', true);
                })
                ->whereKey($data['source_id'])
                ->where('id', '!=', $composicao->id)
                ->firstOrFail();
            $item = $this->itemDataFromChildComposicao($request, $tenant, $composicao, $child, $coefficient);
        }

        OrcamentoComposicaoItem::create($item);
        $this->recalculateComposicaoTotals($composicao);

        return back()->with('success', 'Item adicionado a composicao.');
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
            'uf' => ['required', 'string', Rule::in(self::BRAZILIAN_STATES)],
            'origem_preco' => ['nullable', 'string', 'max:30'],
            'preco_nao_desonerado' => ['nullable', 'string', 'max:30'],
            'preco_desonerado' => ['nullable', 'string', 'max:30'],
            'data' => ['required', 'string', 'max:10'],
            'coeficiente' => ['nullable', 'string', 'max:30'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);

        $referenceDate = $this->parseReferenceDate($data['data']);
        $classificacao = $this->normalizeClassification($data['classificacao'] ?? null);
        $insumo = OrcamentoInsumo::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'banco' => 'PROPRIA',
                'codigo_insumo' => trim($data['codigo_insumo']),
                'uf' => mb_strtoupper($data['uf']),
                'origem_preco' => $data['origem_preco'] ? mb_strtoupper(trim($data['origem_preco'])) : null,
                'data_referencia' => $referenceDate->toDateString(),
            ],
            [
                'created_by_id' => $request->user()->id,
                'tipo' => $data['tipo'] ?: $this->typeFromClassification($classificacao),
                'classificacao' => $classificacao,
                'descricao' => trim($data['descricao']),
                'unidade' => mb_strtoupper(trim($data['unidade'])),
                'preco_nao_desonerado' => $this->parseDecimal($data['preco_nao_desonerado'] ?? null),
                'preco_desonerado' => $this->parseDecimal($data['preco_desonerado'] ?? null),
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

        $item->forceFill([
            'coeficiente' => $coefficient,
            'preco_onerado' => round((float) ($item->preco_unitario_onerado ?? 0) * $coefficient, 2),
            'preco_desonerado' => round((float) ($item->preco_unitario_desonerado ?? 0) * $coefficient, 2),
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

            $query = $this->insumosAvailableForTenant($tenant);

            if ($filters['bank'] !== 'TODOS') {
                $query->where('banco', $filters['bank']);
            }

            $query->where('uf', $filters['state']);

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
            'typeOptions' => $this->insumoTypeOptions($tenant),
            'dateOptions' => $this->insumoDateOptions($tenant),
            'canManageTenantInsumos' => $this->canManageTenantInsumos($request, $tenant),
            'canManageGlobalInsumos' => $this->canManageGlobalInsumos($request),
        ]);
    }

    public function storeInsumo(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['tenant', 'global'])],
            'banco' => ['required', 'string', Rule::in(self::BANKS)],
            'tipo' => ['nullable', 'string', Rule::in(self::TYPES)],
            'classificacao' => ['nullable', 'string', 'max:80'],
            'codigo_insumo' => ['required', 'string', 'max:50'],
            'descricao' => ['required', 'string', 'max:1000'],
            'unidade' => ['required', 'string', 'max:20'],
            'uf' => ['required', 'string', Rule::in(self::BRAZILIAN_STATES)],
            'origem_preco' => ['nullable', 'string', 'max:30'],
            'preco_nao_desonerado' => ['nullable', 'string', 'max:30'],
            'preco_desonerado' => ['nullable', 'string', 'max:30'],
            'data' => ['required', 'string', 'max:10'],
        ]);

        $this->authorizeInsumoScope($request, $tenant, $data['scope']);

        $tenantId = $data['scope'] === 'global' ? null : $tenant->id;
        $referenceDate = $this->parseReferenceDate($data['data']);
        $classificacao = $this->normalizeClassification($data['classificacao'] ?? null);

        OrcamentoInsumo::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'banco' => $data['banco'],
                'codigo_insumo' => trim($data['codigo_insumo']),
                'uf' => mb_strtoupper($data['uf']),
                'origem_preco' => $data['origem_preco'] ? mb_strtoupper(trim($data['origem_preco'])) : null,
                'data_referencia' => $referenceDate->toDateString(),
            ],
            [
                'created_by_id' => $request->user()->id,
                'tipo' => $data['tipo'] ?: $this->typeFromClassification($classificacao),
                'classificacao' => $classificacao,
                'descricao' => trim($data['descricao']),
                'unidade' => mb_strtoupper(trim($data['unidade'])),
                'preco_nao_desonerado' => $this->parseDecimal($data['preco_nao_desonerado'] ?? null),
                'preco_desonerado' => $this->parseDecimal($data['preco_desonerado'] ?? null),
            ],
        );

        return back()->with('success', 'Insumo salvo.');
    }

    public function importInsumos(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['tenant', 'global'])],
            'banco' => ['required', 'string', Rule::in(self::BANKS)],
            'file' => ['required', 'file', 'mimes:csv,txt,tsv', 'max:'.self::CSV_UPLOAD_MAX_KB],
        ]);

        $this->authorizeInsumoScope($request, $tenant, $data['scope']);

        $result = $this->importCsv(
            $request->file('file')->getRealPath(),
            $data['scope'] === 'global' ? null : $tenant->id,
            $data['banco'],
            $request->user()->id,
        );

        return back()->with(
            'success',
            "Importacao concluida: {$result['created']} criado(s), {$result['updated']} atualizado(s), {$result['duplicated']} duplicado(s) ignorado(s), {$result['skipped']} invalido(s) ignorado(s).",
        )->with('import_result', [
            'title' => 'Resumo da importacao de insumos',
            'scope' => $data['scope'],
            'scope_label' => $data['scope'] === 'global' ? 'Global' : 'Tenant',
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
        $data = $request->validate([
            'scope' => ['required', Rule::in(['tenant', 'global'])],
            'modelo' => ['required', 'string', Rule::in(self::BANKS)],
            'file' => ['required', 'file', 'mimes:csv,txt,tsv', 'max:'.self::CSV_UPLOAD_MAX_KB],
        ]);

        $this->authorizeInsumoScope($request, $tenant, $data['scope']);

        $result = $this->importComposicoesCsv(
            $request->file('file')->getRealPath(),
            $tenant,
            $data['modelo'],
            $data['scope'] === 'global',
            $request->user()->id,
        );

        return back()->with(
            'success',
            "Importacao de composicoes concluida: {$result['created']} criada(s), {$result['updated']} atualizada(s), {$result['duplicated']} duplicada(s) ignorada(s), {$result['skipped']} invalida(s) ignorada(s).",
        )->with('import_result', [
            'title' => 'Resumo da importacao de composicoes',
            'scope' => $data['scope'],
            'scope_label' => $data['scope'] === 'global' ? 'Global' : 'Tenant',
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
            'scope_label' => $data['scope'] === 'global' ? 'Global' : 'Tenant',
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
        $states = array_values(array_unique(array_column($payloads, 'uf')));
        $models = array_values(array_unique(array_column($payloads, 'modelo')));

        $query = OrcamentoComposicao::withTrashed()
            ->select(['id', 'tenant_id', 'is_global', 'modelo', 'codigo', 'uf', 'base_references'])
            ->where('is_global', $global)
            ->whereIn('modelo', $models)
            ->whereIn('codigo', $codes)
            ->whereIn('uf', $states);

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

    private function composicaoImportKeyFromParts(string $model, string $code, string $state, string $referenceCode, Tenant $tenant, bool $global): string
    {
        return implode("\x1F", [
            $global ? 'global' : 'tenant:'.$tenant->id,
            mb_strtoupper($model),
            $code,
            mb_strtoupper($state),
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
        return collect($this->normalizedCompositionReferences($composicao))
            ->map(fn (array $reference): ?string => $reference['date']?->toDateString())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function firstReferenceDateForComposicao(OrcamentoComposicao $composicao): ?string
    {
        $references = $this->normalizedCompositionReferences($composicao);
        $reference = collect($references)->first(fn (array $reference): bool => $reference['base'] === $composicao->modelo && $reference['uf'] === $composicao->uf)
            ?? $references[0]
            ?? null;

        return $reference && $reference['date'] ? $reference['date']->toDateString() : null;
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

    private function persistInsumoImportBatch(array $payloads, int $userId): array
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

            if (array_key_exists($key, $existingByKey)) {
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
        ];
    }

    private function existingInsumosForImportBatch(array $payloads): array
    {
        if ($payloads === []) {
            return [];
        }

        $first = $payloads[0];
        $codes = array_values(array_unique(array_column($payloads, 'codigo_insumo')));
        $states = array_values(array_unique(array_column($payloads, 'uf')));
        $dates = array_values(array_unique(array_column($payloads, 'data_referencia')));
        $origins = array_values(array_unique(array_map(fn (array $payload): string => $payload['origem_preco'] ?? '__NULL__', $payloads)));
        $originValues = array_values(array_filter($origins, fn (string $origin): bool => $origin !== '__NULL__'));
        $hasNullOrigin = in_array('__NULL__', $origins, true);

        $query = OrcamentoInsumo::withTrashed()
            ->select(['id', 'tenant_id', 'banco', 'codigo_insumo', 'uf', 'origem_preco', 'data_referencia'])
            ->where('banco', $first['banco'])
            ->whereIn('codigo_insumo', $codes)
            ->whereIn('uf', $states)
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

        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    private function serializeInsumo(OrcamentoInsumo $insumo): array
    {
        return [
            'id' => $insumo->id,
            'scope' => $insumo->tenant_id ? 'tenant' : 'global',
            'scope_label' => $insumo->tenant_id ? 'Tenant' : 'Global',
            'banco' => $insumo->banco,
            'tipo' => $insumo->tipo,
            'classificacao' => $insumo->classificacao,
            'codigo_insumo' => $insumo->codigo_insumo,
            'descricao' => $insumo->descricao,
            'unidade' => $insumo->unidade,
            'uf' => $insumo->uf,
            'origem_preco' => $insumo->origem_preco,
            'preco_nao_desonerado' => $insumo->preco_nao_desonerado,
            'preco_desonerado' => $insumo->preco_desonerado,
            'data' => $insumo->data_referencia?->format('m/Y'),
        ];
    }

    private function serializeComposicaoItem(OrcamentoComposicaoItem $item): array
    {
        return [
            'id' => $item->id,
            'item_type' => $item->item_type,
            'base' => $item->base,
            'codigo' => $item->codigo,
            'child_composicao_id' => $item->child_composicao_id,
            'descricao' => $item->descricao,
            'tipo' => $item->tipo,
            'unidade' => $item->unidade,
            'preco_unitario_onerado' => $item->preco_unitario_onerado,
            'preco_unitario_desonerado' => $item->preco_unitario_desonerado,
            'coeficiente' => $item->coeficiente,
            'preco_onerado' => $item->preco_onerado,
            'preco_desonerado' => $item->preco_desonerado,
            'observacao' => $item->observacao,
        ];
    }

    private function serializeInsumoOption(OrcamentoInsumo $insumo): array
    {
        return [
            'id' => $insumo->id,
            'base' => $insumo->banco,
            'codigo' => $insumo->codigo_insumo,
            'descricao' => $insumo->descricao,
            'tipo' => $insumo->classificacao ?: $this->typeLabel($insumo->tipo),
            'unidade' => $insumo->unidade,
            'preco_unitario_onerado' => $insumo->preco_nao_desonerado,
            'preco_unitario_desonerado' => $insumo->preco_desonerado,
            'data' => $insumo->data_referencia?->format('m/Y'),
        ];
    }

    private function serializeComposicaoOption(OrcamentoComposicao $composicao): array
    {
        return [
            'id' => $composicao->id,
            'base' => $composicao->modelo,
            'codigo' => $composicao->codigo,
            'descricao' => $composicao->descricao,
            'tipo' => $composicao->tipo_composicao,
            'unidade' => $composicao->unidade,
            'preco_unitario_onerado' => $composicao->preco_onerado,
            'preco_unitario_desonerado' => $composicao->preco_desonerado,
            'data' => null,
        ];
    }

    private function serializeComposicao(OrcamentoComposicao $composicao): array
    {
        return [
            'id' => $composicao->id,
            'scope' => $composicao->is_global ? 'global' : 'tenant',
            'scope_label' => $composicao->is_global ? 'Global' : 'Tenant',
            'codigo' => $composicao->codigo,
            'descricao' => $composicao->descricao,
            'tipo_composicao' => $composicao->tipo_composicao,
            'unidade' => $composicao->unidade,
            'uf' => $composicao->uf,
            'estado_label' => $this->stateLabel($composicao->uf),
            'modelo' => $composicao->modelo,
            'metodo_calculo' => $composicao->metodo_calculo,
            'metodo_calculo_label' => $this->calculationMethodLabel($composicao->metodo_calculo),
            'observacao' => $composicao->observacao,
            'base_references' => $composicao->base_references ?? [],
            'preco_onerado' => $composicao->preco_onerado,
            'preco_desonerado' => $composicao->preco_desonerado,
            'items_count' => (int) ($composicao->items_count ?? 0),
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
                ))
                ->values()
                ->all(),
        ];
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

        return $query
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

    private function serializeAnaliticoState(OrcamentoComposicao $stateComposicao, \Illuminate\Support\Collection $analyticItems, array $sources): array
    {
        $items = $analyticItems
            ->map(fn (OrcamentoComposicaoAnaliticoItem $item): array => $this->serializeAnaliticoItemForState($item, $stateComposicao->uf, $sources))
            ->values();

        return [
            'uf' => $stateComposicao->uf,
            'estado_label' => $this->stateLabel($stateComposicao->uf),
            'composicao_id' => $stateComposicao->id,
            'preco_onerado' => $stateComposicao->preco_onerado,
            'preco_desonerado' => $stateComposicao->preco_desonerado,
            'computed_preco_onerado' => round($items->sum('preco_onerado'), 2),
            'computed_preco_desonerado' => round($items->sum('preco_desonerado'), 2),
            'items_count' => $items->count(),
            'items' => $items->all(),
        ];
    }

    private function serializeAnaliticoItemForState(OrcamentoComposicaoAnaliticoItem $item, string $state, array $sources): array
    {
        $type = $item->tipo_item === 'composicao' ? 'composicao' : 'insumo';
        $source = $sources[$type === 'insumo' ? 'insumos' : 'composicoes']->get($state.'|'.$this->codeKey($item->codigo_item));
        $coefficient = (float) ($item->coeficiente ?? 0);
        $unitOnerado = $source instanceof OrcamentoInsumo
            ? (float) ($source->preco_nao_desonerado ?? 0)
            : ($source instanceof OrcamentoComposicao ? (float) ($source->preco_onerado ?? 0) : 0);
        $unitDesonerado = $source instanceof OrcamentoInsumo
            ? (float) ($source->preco_desonerado ?? 0)
            : ($source instanceof OrcamentoComposicao ? (float) ($source->preco_desonerado ?? 0) : 0);

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
            'preco_unitario_onerado' => round($unitOnerado, 2),
            'preco_unitario_desonerado' => round($unitDesonerado, 2),
            'preco_onerado' => round($unitOnerado * $coefficient, 2),
            'preco_desonerado' => round($unitDesonerado * $coefficient, 2),
            'source_found' => (bool) $source,
        ];
    }

    private function itemDataFromInsumo(Request $request, Tenant $tenant, OrcamentoComposicao $composicao, OrcamentoInsumo $insumo, float $coefficient): array
    {
        return $this->itemDataFromInsumoForUser($tenant->id, $composicao, $insumo, $coefficient, $request->user()->id);
    }

    private function itemDataFromInsumoForUser(int $tenantId, OrcamentoComposicao $composicao, OrcamentoInsumo $insumo, float $coefficient, int $userId): array
    {
        $unitOnerado = (float) ($insumo->preco_nao_desonerado ?? 0);
        $unitDesonerado = (float) ($insumo->preco_desonerado ?? 0);

        return [
            'tenant_id' => $tenantId,
            'orcamento_composicao_id' => $composicao->id,
            'created_by_id' => $userId,
            'item_type' => 'insumo',
            'orcamento_insumo_id' => $insumo->id,
            'child_composicao_id' => null,
            'base' => $insumo->banco,
            'codigo' => $insumo->codigo_insumo,
            'descricao' => $insumo->descricao,
            'tipo' => $insumo->classificacao ?: $this->typeLabel($insumo->tipo),
            'unidade' => $insumo->unidade,
            'preco_unitario_onerado' => $unitOnerado,
            'preco_unitario_desonerado' => $unitDesonerado,
            'coeficiente' => $coefficient,
            'preco_onerado' => round($unitOnerado * $coefficient, 2),
            'preco_desonerado' => round($unitDesonerado * $coefficient, 2),
        ];
    }

    private function itemDataFromChildComposicao(Request $request, Tenant $tenant, OrcamentoComposicao $parent, OrcamentoComposicao $child, float $coefficient): array
    {
        return $this->itemDataFromChildComposicaoForUser($tenant->id, $parent, $child, $coefficient, $request->user()->id);
    }

    private function itemDataFromChildComposicaoForUser(int $tenantId, OrcamentoComposicao $parent, OrcamentoComposicao $child, float $coefficient, int $userId): array
    {
        $unitOnerado = (float) ($child->preco_onerado ?? 0);
        $unitDesonerado = (float) ($child->preco_desonerado ?? 0);

        return [
            'tenant_id' => $tenantId,
            'orcamento_composicao_id' => $parent->id,
            'created_by_id' => $userId,
            'item_type' => 'composicao',
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
            'preco_onerado' => round($unitOnerado * $coefficient, 2),
            'preco_desonerado' => round($unitDesonerado * $coefficient, 2),
        ];
    }

    private function recalculateComposicaoTotals(OrcamentoComposicao $composicao): void
    {
        $totals = $composicao->items()
            ->selectRaw('COALESCE(SUM(preco_onerado), 0) as preco_onerado_total, COALESCE(SUM(preco_desonerado), 0) as preco_desonerado_total')
            ->first();

        $composicao->forceFill([
            'preco_onerado' => $totals?->preco_onerado_total ?? 0,
            'preco_desonerado' => $totals?->preco_desonerado_total ?? 0,
        ])->save();
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

    private function compositionFormOptions(): array
    {
        return [
            'states' => collect(self::BRAZILIAN_STATES)
                ->map(fn (string $uf): array => ['value' => $uf, 'label' => $this->stateLabel($uf)])
                ->values()
                ->all(),
            'types' => [
                ['value' => 'ASTU - ASSENTAMENTO DE TUBOS E PECAS', 'label' => 'ASTU - Assentamento de tubos e pecas'],
                ['value' => 'PAVI - PAVIMENTACAO', 'label' => 'PAVI - Pavimentacao'],
                ['value' => 'DREN - DRENAGEM', 'label' => 'DREN - Drenagem'],
                ['value' => 'TERR - TERRAPLANAGEM', 'label' => 'TERR - Terraplanagem'],
                ['value' => 'SINA - SINALIZACAO', 'label' => 'SINA - Sinalizacao'],
                ['value' => 'SERV - SERVICOS GERAIS', 'label' => 'SERV - Servicos gerais'],
            ],
            'calculationMethods' => [
                ['value' => 'truncate_2', 'label' => 'Truncar em 2 casas decimais', 'badge' => 'Padrao TCU'],
                ['value' => 'round_2', 'label' => 'Arredondar em 2 casas decimais', 'badge' => null],
                ['value' => 'none', 'label' => 'Nao arredondar', 'badge' => null],
            ],
            'baseReferences' => $this->compositionBaseReferences(),
        ];
    }

    private function compositionBaseReferences(): array
    {
        return [
            [
                'region' => 'Nacional',
                'items' => [
                    ['codigo' => 'SINAPI-PA-04/2026', 'nome' => 'SINAPI', 'localidade' => 'Para - PA', 'uf' => 'PA', 'data' => '04/2026'],
                    ['codigo' => 'SICRO3-PA-01/2026', 'nome' => 'SICRO3', 'localidade' => 'Para - PA', 'uf' => 'PA', 'data' => '01/2026'],
                    ['codigo' => 'SICRO2-PA-11/2016', 'nome' => 'SICRO2', 'localidade' => 'Para - PA', 'uf' => 'PA', 'data' => '11/2016'],
                    ['codigo' => 'SBC-BLM-05/2026', 'nome' => 'SBC', 'localidade' => 'BLM - Belem - PA', 'uf' => 'PA', 'data' => '05/2026'],
                ],
            ],
            [
                'region' => 'Sudeste',
                'items' => [
                    ['codigo' => 'SETOP-MG-01/2026', 'nome' => 'SETOP', 'localidade' => 'Central - MG', 'uf' => 'MG', 'data' => '01/2026'],
                ],
            ],
            [
                'region' => 'Nordeste',
                'items' => [
                    ['codigo' => 'ORSE-SE-04/2026', 'nome' => 'ORSE', 'localidade' => 'Sergipe - SE', 'uf' => 'SE', 'data' => '04/2026'],
                ],
            ],
            [
                'region' => 'Norte',
                'items' => [
                    ['codigo' => 'SINAPI-AM-04/2026', 'nome' => 'SINAPI', 'localidade' => 'Amazonas - AM', 'uf' => 'AM', 'data' => '04/2026'],
                ],
            ],
            [
                'region' => 'Sul',
                'items' => [
                    ['codigo' => 'SINAPI-SC-04/2026', 'nome' => 'SINAPI', 'localidade' => 'Santa Catarina - SC', 'uf' => 'SC', 'data' => '04/2026'],
                ],
            ],
            [
                'region' => 'Centro-Oeste',
                'items' => [
                    ['codigo' => 'SINAPI-GO-04/2026', 'nome' => 'SINAPI', 'localidade' => 'Goias - GO', 'uf' => 'GO', 'data' => '04/2026'],
                ],
            ],
        ];
    }

    private function insumoOptionsForComposicao(Tenant $tenant, OrcamentoComposicao $composicao): array
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
        }

        return $query
            ->orderBy('descricao')
            ->limit(120)
            ->get()
            ->map(fn (OrcamentoInsumo $insumo): array => $this->serializeInsumoOption($insumo))
            ->values()
            ->all();
    }

    private function composicaoOptionsForComposicao(Tenant $tenant, OrcamentoComposicao $composicao): array
    {
        $references = $this->normalizedCompositionReferences($composicao);
        $query = OrcamentoComposicao::query()
            ->where(function (Builder $query) use ($tenant): void {
                $query->where('tenant_id', $tenant->id)->orWhere('is_global', true);
            })
            ->where('id', '!=', $composicao->id);

        if ($references !== []) {
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
        }

        return $query
            ->orderBy('descricao')
            ->limit(120)
            ->get()
            ->map(fn (OrcamentoComposicao $composicao): array => $this->serializeComposicaoOption($composicao))
            ->values()
            ->all();
    }

    private function normalizedCompositionReferences(OrcamentoComposicao $composicao): array
    {
        return collect($composicao->base_references ?? [])
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

    private function insumoTypeOptions(Tenant $tenant): array
    {
        return $this->insumosAvailableForTenant($tenant)
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
            'truncate_2' => 'Truncar em 2 casas decimais',
            'round_2' => 'Arredondar em 2 casas decimais',
            'none' => 'Nao arredondar',
            default => 'Nao informado',
        };
    }

    private function stateLabel(string $uf): string
    {
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

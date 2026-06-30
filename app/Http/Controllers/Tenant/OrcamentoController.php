<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\DeleteStoredImportFileJob;
use App\Jobs\ImportOrcamentoComposicoesJob;
use App\Models\Empresa;
use App\Models\Orcamento;
use App\Models\OrcamentoComposicao;
use App\Models\OrcamentoComposicaoAnaliticoItem;
use App\Models\OrcamentoComposicaoItem;
use App\Models\OrcamentoEtapa;
use App\Models\OrcamentoInsumo;
use App\Models\OrcamentoInsumoGrupo;
use App\Models\OrcamentoItem;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class OrcamentoController extends Controller
{
    private const BANKS = ['SINAPI', 'SICRO3', 'PROPRIA'];

    private const TYPES = ['material', 'labor', 'equipment', 'service'];

    private const SICRO3_CALCULATION_METHOD = 'sicro3_round_4_2';

    private const CALCULATION_METHODS = ['truncate_2', 'round_2', 'none', 'sicro3_round_4_2'];

    private const ORCAMENTO_ROUNDING_METHODS = [
        'round_all_2',
        'round_compositions_2',
        'round_and_truncate_unit',
        'truncate_all_2',
        'none',
    ];

    private const ORCAMENTO_CATEGORIES = [
        'Calcadas e meio-fio',
        'Construcao e ampliacao de rede de abastecimento de agua',
        'Creches e escolas - Construcao',
        'Creches e escolas - Reforma',
        'Espacos publicos e pracas - Construcao',
        'Espacos publicos e pracas - Reforma',
        'Galpoes',
        'Infraestruturas Esportivas - Construcao',
        'Infraestruturas Esportivas - Reforma',
        'Hospitais e unidades de saude - Construcao',
        'Hospitais e unidades de saude - Reforma',
        'Muros',
        'Passagens molhadas e pontes - Construcao',
        'Passagens molhadas e pontes - Reforma',
        'Pavimentacao asfaltica',
        'Pavimentacao e drenagem',
        'Pavimentacao em bloco de concreto intertravado',
        'Pavimentacao em paralelepipedo',
        'Predios publicos - Construcao',
        'Predios publicos - Reforma',
        'Unidades habitacionais - Construcao',
        'Unidades habitacionais - Reforma',
        'Usinas fotovoltaicas',
        'Outros',
    ];

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

    private const POSTGRES_CSV_IMPORT_BATCH_SIZE = 250;

    private const POSTGRES_CSV_INSERT_CHUNK_SIZE = 100;

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

    public function index(Request $request, Tenant $tenant): Response
    {
        $orcamentos = Orcamento::query()
            ->with(['clienteEmpresa:id,nome'])
            ->where('tenant_id', $tenant->id)
            ->latest()
            ->get();

        return Inertia::render('Tenant/Orcamentos/Index', [
            'tenant' => $tenant,
            'orcamentos' => $orcamentos
                ->map(fn (Orcamento $orcamento): array => $this->serializeOrcamento($orcamento))
                ->values(),
            'stats' => [
                'total' => $orcamentos->count(),
                'draft' => $orcamentos->where('status', 'draft')->count(),
                'closed' => $orcamentos->where('status', 'closed')->count(),
            ],
            'canManageOrcamentos' => $this->canManageTenantInsumos($request, $tenant),
        ]);
    }

    public function create(Request $request, Tenant $tenant): Response
    {
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        return Inertia::render('Tenant/Orcamentos/Create', [
            'tenant' => $tenant,
            'options' => $this->orcamentoFormOptions($tenant),
        ]);
    }

    public function createImport(Request $request, Tenant $tenant): Response
    {
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        return Inertia::render('Tenant/Orcamentos/Import', [
            'tenant' => $tenant,
            'options' => $this->orcamentoFormOptions($tenant),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:50', Rule::unique('orcamentos', 'codigo')->where('tenant_id', $tenant->id)->whereNull('deleted_at')],
            'descricao' => ['required', 'string', 'max:255'],
            'cliente_empresa_id' => [
                'nullable',
                Rule::exists('empresas', 'id')->where('tenant_id', $tenant->id),
            ],
            'categoria' => ['required', 'string', Rule::in(self::ORCAMENTO_CATEGORIES)],
            'prazo_entrega_at' => ['nullable', 'date'],
            'permitir_insumos_preco_zerado' => ['boolean'],
            'is_licitacao' => ['boolean'],
            'licitacao_tipo' => ['nullable', Rule::requiredIf($request->boolean('is_licitacao')), 'string', 'max:120'],
            'licitacao_abertura_at' => ['nullable', Rule::requiredIf($request->boolean('is_licitacao')), 'date'],
            'licitacao_processo' => ['nullable', Rule::requiredIf($request->boolean('is_licitacao')), 'string', 'max:120'],
            'arredondamento' => ['required', 'string', Rule::in(self::ORCAMENTO_ROUNDING_METHODS)],
            'encargos_sociais' => ['required', 'string', Rule::in(['desonerado', 'nao_desonerado'])],
            'bdi_tipo' => ['required', 'string', Rule::in(['unit_price', 'total_budget'])],
            'bdi_percentual' => ['required', 'string', 'max:30'],
            'base_references' => ['required', 'array', 'min:1'],
            'base_references.*.codigo' => ['required', 'string', 'max:40'],
            'base_references.*.nome' => ['required', 'string', Rule::in(['SINAPI', 'SICRO3'])],
            'base_references.*.uf' => ['required', 'string', Rule::in(self::BRAZILIAN_STATES)],
            'base_references.*.localidade' => ['nullable', 'string', 'max:120'],
            'base_references.*.data' => ['required', 'string', 'max:20'],
        ]);

        $bdiPercentual = $this->parseDecimal($data['bdi_percentual']);

        if ($bdiPercentual === null || $bdiPercentual < 0) {
            throw ValidationException::withMessages([
                'bdi_percentual' => 'Informe um percentual de BDI valido.',
            ]);
        }

        $baseReferences = collect($data['base_references'])
            ->map(fn (array $reference): array => [
                'codigo' => trim($reference['codigo']),
                'nome' => mb_strtoupper(trim($reference['nome'])),
                'uf' => mb_strtoupper(trim($reference['uf'])),
                'localidade' => trim((string) ($reference['localidade'] ?? '')),
                'data' => trim($reference['data']),
            ])
            ->unique('codigo')
            ->values()
            ->all();

        $orcamento = Orcamento::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $request->user()->id,
            'cliente_empresa_id' => $data['cliente_empresa_id'] ?? null,
            'codigo' => trim($data['codigo']),
            'descricao' => trim($data['descricao']),
            'categoria' => $data['categoria'],
            'prazo_entrega_at' => filled($data['prazo_entrega_at'] ?? null)
                ? CarbonImmutable::parse($data['prazo_entrega_at'])->toDateTimeString()
                : null,
            'permitir_insumos_preco_zerado' => (bool) ($data['permitir_insumos_preco_zerado'] ?? false),
            'is_licitacao' => (bool) ($data['is_licitacao'] ?? false),
            'licitacao_tipo' => ($data['is_licitacao'] ?? false) ? trim((string) ($data['licitacao_tipo'] ?? '')) : null,
            'licitacao_abertura_at' => ($data['is_licitacao'] ?? false) && filled($data['licitacao_abertura_at'] ?? null)
                ? CarbonImmutable::parse($data['licitacao_abertura_at'])->toDateTimeString()
                : null,
            'licitacao_processo' => ($data['is_licitacao'] ?? false) ? trim((string) ($data['licitacao_processo'] ?? '')) : null,
            'arredondamento' => $data['arredondamento'],
            'encargos_sociais' => $data['encargos_sociais'],
            'bdi_tipo' => $data['bdi_tipo'],
            'bdi_percentual' => $bdiPercentual,
            'base_references' => $baseReferences,
            'status' => 'draft',
        ]);

        return redirect()
            ->route('tenant.orcamentos.show', [$tenant, $orcamento])
            ->with('success', 'Orcamento criado.');
    }

    public function storeImport(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:50', Rule::unique('orcamentos', 'codigo')->where('tenant_id', $tenant->id)->whereNull('deleted_at')],
            'descricao' => ['required', 'string', 'max:255'],
            'cliente_empresa_id' => [
                'nullable',
                Rule::exists('empresas', 'id')->where('tenant_id', $tenant->id),
            ],
            'categoria' => ['required', 'string', Rule::in(self::ORCAMENTO_CATEGORIES)],
            'permitir_insumos_preco_zerado' => ['boolean'],
            'arredondamento' => ['required', 'string', Rule::in(self::ORCAMENTO_ROUNDING_METHODS)],
            'encargos_sociais' => ['required', 'string', Rule::in(['desonerado', 'nao_desonerado'])],
            'encargos_horista' => ['nullable', 'string', 'max:30'],
            'encargos_mensalista' => ['nullable', 'string', 'max:30'],
            'bdi_tipo' => ['required', 'string', Rule::in(['unit_price', 'total_budget'])],
            'bdi_percentual' => ['required', 'string', 'max:30'],
            'base_references' => ['required', 'array', 'min:1'],
            'base_references.*.nome' => ['required', 'string', 'max:40'],
            'base_references.*.uf' => ['nullable', 'string', Rule::in(self::BRAZILIAN_STATES)],
            'base_references.*.localidade' => ['nullable', 'string', 'max:120'],
            'base_references.*.data' => ['required', 'string', 'max:20'],
            'file' => ['required', 'file', 'mimes:csv,txt,tsv', 'max:'.self::CSV_UPLOAD_MAX_KB],
        ]);

        $bdiPercentual = $this->parseDecimal($data['bdi_percentual']);
        $encargosHorista = $this->parseOptionalPercentage($data['encargos_horista'] ?? null, 'encargos_horista');
        $encargosMensalista = $this->parseOptionalPercentage($data['encargos_mensalista'] ?? null, 'encargos_mensalista');

        if ($bdiPercentual === null || (float) $bdiPercentual < 0) {
            throw ValidationException::withMessages([
                'bdi_percentual' => 'Informe um percentual de BDI válido.',
            ]);
        }

        $baseReferences = collect($data['base_references'])
            ->map(function (array $reference): array {
                $name = mb_strtoupper(trim($reference['nome']));
                $uf = mb_strtoupper(trim((string) ($reference['uf'] ?? '')));
                $date = trim($reference['data']);

                return [
                    'codigo' => implode('-', array_filter([$name, $uf, $date])),
                    'nome' => $name,
                    'uf' => $uf,
                    'localidade' => trim((string) ($reference['localidade'] ?? '')),
                    'data' => $date,
                ];
            })
            ->unique('codigo')
            ->values()
            ->all();

        $filePath = $request->file('file')?->getRealPath();

        if (! $filePath) {
            throw ValidationException::withMessages(['file' => 'Não foi possível ler o arquivo enviado.']);
        }

        $result = DB::transaction(function () use (
            $tenant,
            $request,
            $data,
            $bdiPercentual,
            $encargosHorista,
            $encargosMensalista,
            $baseReferences,
            $filePath,
        ): array {
            $orcamento = Orcamento::create([
                'tenant_id' => $tenant->id,
                'created_by_id' => $request->user()->id,
                'cliente_empresa_id' => $data['cliente_empresa_id'] ?? null,
                'codigo' => trim($data['codigo']),
                'descricao' => trim($data['descricao']),
                'categoria' => $data['categoria'],
                'permitir_insumos_preco_zerado' => (bool) ($data['permitir_insumos_preco_zerado'] ?? false),
                'is_licitacao' => false,
                'arredondamento' => $data['arredondamento'],
                'encargos_sociais' => $data['encargos_sociais'],
                'encargos_horista' => $encargosHorista,
                'encargos_mensalista' => $encargosMensalista,
                'bdi_tipo' => $data['bdi_tipo'],
                'bdi_percentual' => $bdiPercentual,
                'base_references' => $baseReferences,
                'status' => 'draft',
            ]);

            $stats = $this->importOrcamentoItemsCsv(
                $filePath,
                $tenant,
                $orcamento,
                (int) $request->user()->id,
            );

            if ($stats['items'] === 0) {
                throw ValidationException::withMessages([
                    'file' => 'Nenhum item válido foi encontrado no CSV. Verifique o cabeçalho e a estrutura do arquivo.',
                ]);
            }

            $this->recalculateOrcamentoTotals($tenant, $orcamento);
            $this->restoreImportedOrcamentoStageTotals($tenant, $orcamento);

            return [
                'orcamento' => $orcamento,
                ...$stats,
            ];
        });

        /** @var Orcamento $orcamento */
        $orcamento = $result['orcamento'];

        return redirect()
            ->route('tenant.orcamentos.show', [$tenant, $orcamento])
            ->with(
                'success',
                "Orçamento importado: {$result['stages']} etapa(s), {$result['items']} item(ns) e {$result['skipped']} linha(s) ignorada(s)."
            );
    }

    public function show(Request $request, Tenant $tenant, Orcamento $orcamento): Response
    {
        abort_unless((int) $orcamento->tenant_id === (int) $tenant->id, 404);

        $orcamento->load([
            'clienteEmpresa:id,nome',
            'etapas' => fn ($query) => $query
                ->with(['itens' => fn ($query) => $query->orderBy('ordem')->orderBy('id')])
                ->orderBy('ordem'),
        ]);
        $this->sortLoadedOrcamentoEtapas($orcamento);

        return Inertia::render('Tenant/Orcamentos/Show', [
            'tenant' => $tenant,
            'orcamento' => $this->serializeOrcamento($orcamento),
            'etapas' => $orcamento->etapas
                ->map(fn (OrcamentoEtapa $etapa): array => $this->serializeOrcamentoEtapa($etapa, $orcamento))
                ->values(),
            'copySources' => $this->orcamentoCopySources($tenant, $orcamento),
            'canManageOrcamentos' => $this->canManageTenantInsumos($request, $tenant),
        ]);
    }

    public function copyPreview(Request $request, Tenant $tenant, Orcamento $orcamento, Orcamento $sourceOrcamento): JsonResponse
    {
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);
        abort_unless((int) $orcamento->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $sourceOrcamento->tenant_id === (int) $tenant->id, 404);
        abort_if((int) $sourceOrcamento->id === (int) $orcamento->id, 422);

        $sourceOrcamento->load([
            'clienteEmpresa:id,nome',
            'etapas' => fn ($query) => $query
                ->with(['itens' => fn ($query) => $query->orderBy('ordem')->orderBy('id')])
                ->orderBy('ordem'),
        ]);
        $this->sortLoadedOrcamentoEtapas($sourceOrcamento);

        return response()->json([
            'orcamento' => $this->serializeOrcamento($sourceOrcamento),
            'etapas' => $sourceOrcamento->etapas
                ->map(fn (OrcamentoEtapa $etapa): array => $this->serializeOrcamentoEtapa($etapa, $sourceOrcamento))
                ->values(),
        ]);
    }

    public function copyFromOrcamento(Request $request, Tenant $tenant, Orcamento $orcamento): RedirectResponse
    {
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);
        abort_unless((int) $orcamento->tenant_id === (int) $tenant->id, 404);
        $this->ensureOrcamentoIsOpen($orcamento);

        $data = $request->validate([
            'source_orcamento_id' => [
                'required',
                Rule::exists('orcamentos', 'id')
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('deleted_at'),
            ],
            'etapa_ids' => ['nullable', 'array'],
            'etapa_ids.*' => ['integer'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],
            'price_mode' => ['nullable', 'string', Rule::in(['source'])],
        ]);

        if ((int) $data['source_orcamento_id'] === (int) $orcamento->id) {
            throw ValidationException::withMessages([
                'source_orcamento_id' => 'Selecione um orçamento diferente do orçamento atual.',
            ]);
        }

        $sourceOrcamento = Orcamento::query()
            ->where('tenant_id', $tenant->id)
            ->with([
                'etapas' => fn ($query) => $query
                    ->with(['itens' => fn ($query) => $query->orderBy('ordem')->orderBy('id')])
                    ->orderBy('ordem'),
            ])
            ->findOrFail($data['source_orcamento_id']);

        $selectedEtapaIds = collect($data['etapa_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $selectedItemIds = collect($data['item_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($selectedEtapaIds->isEmpty() && $selectedItemIds->isEmpty()) {
            throw ValidationException::withMessages([
                'item_ids' => 'Selecione pelo menos uma etapa, composição ou insumo para copiar.',
            ]);
        }

        $sourceEtapas = $sourceOrcamento->etapas;
        $sourceEtapasById = $sourceEtapas->keyBy('id');
        $sourceEtapasByOrder = $sourceEtapas->keyBy(fn (OrcamentoEtapa $etapa): string => (string) $etapa->ordem);
        $sourceItems = $sourceEtapas
            ->flatMap(fn (OrcamentoEtapa $etapa) => $etapa->itens)
            ->keyBy('id');

        $requiredEtapaIds = $selectedEtapaIds;

        $selectedItemIds
            ->map(fn (int $id): ?OrcamentoItem => $sourceItems->get($id))
            ->filter()
            ->each(function (OrcamentoItem $item) use (&$requiredEtapaIds, $sourceEtapasById, $sourceEtapasByOrder): void {
                $etapa = $sourceEtapasById->get($item->orcamento_etapa_id);

                if ($etapa) {
                    $requiredEtapaIds->push((int) $etapa->id);
                    $this->pushSourceEtapaAncestors($requiredEtapaIds, $etapa, $sourceEtapasByOrder);
                }
            });

        $selectedEtapaIds
            ->map(fn (int $id): ?OrcamentoEtapa => $sourceEtapasById->get($id))
            ->filter()
            ->each(fn (OrcamentoEtapa $etapa) => $this->pushSourceEtapaAncestors($requiredEtapaIds, $etapa, $sourceEtapasByOrder));

        $requiredEtapaIds = $requiredEtapaIds->unique()->values();

        DB::transaction(function () use ($tenant, $orcamento, $sourceOrcamento, $request, $sourceEtapas, $sourceEtapasById, $sourceItems, $requiredEtapaIds, $selectedItemIds): void {
            $requiredEtapas = $sourceEtapas
                ->whereIn('id', $requiredEtapaIds->all())
                ->sortBy(fn (OrcamentoEtapa $etapa): string => $this->hierarchySortKey($etapa->ordem))
                ->values();

            $rootOrderMap = [];
            $nextRoot = (int) $this->nextRootEtapaOrder($tenant, $orcamento);
            $copiedEtapasBySourceId = [];

            foreach ($requiredEtapas as $sourceEtapa) {
                $sourceOrder = (string) $sourceEtapa->ordem;
                $rootOrder = Str::before($sourceOrder, '.');

                if (! isset($rootOrderMap[$rootOrder])) {
                    $rootOrderMap[$rootOrder] = (string) $nextRoot;
                    $nextRoot++;
                }

                $suffix = str_contains($sourceOrder, '.')
                    ? substr($sourceOrder, strlen($rootOrder))
                    : '';
                $newOrder = $rootOrderMap[$rootOrder].$suffix;
                $meta = $sourceEtapa->meta ?? [];
                $meta['copied_from_orcamento_id'] = $sourceOrcamento->id;
                $meta['copied_from_etapa_id'] = $sourceEtapa->id;

                $copiedEtapasBySourceId[$sourceEtapa->id] = OrcamentoEtapa::create([
                    'tenant_id' => $tenant->id,
                    'orcamento_id' => $orcamento->id,
                    'created_by_id' => $request->user()->id,
                    'ordem' => $newOrder,
                    'descricao' => $sourceEtapa->descricao,
                    'quantidade' => '1.000000',
                    'valor_nao_desonerado' => 0,
                    'valor_desonerado' => 0,
                    'meta' => $meta,
                ]);
            }

            $sourceItems
                ->whereIn('id', $selectedItemIds->all())
                ->sortBy(fn (OrcamentoItem $item): string => $this->hierarchySortKey($sourceEtapasById->get($item->orcamento_etapa_id)?->ordem ?? '0').'.'.str_pad((string) $item->ordem, 8, '0', STR_PAD_LEFT))
                ->each(function (OrcamentoItem $sourceItem) use ($tenant, $orcamento, $request, $copiedEtapasBySourceId, $sourceOrcamento): void {
                    $targetEtapa = $copiedEtapasBySourceId[$sourceItem->orcamento_etapa_id] ?? null;

                    if (! $targetEtapa) {
                        return;
                    }

                    $meta = $sourceItem->meta ?? [];
                    $meta['copied_from_orcamento_id'] = $sourceOrcamento->id;
                    $meta['copied_from_item_id'] = $sourceItem->id;

                    OrcamentoItem::create([
                        'tenant_id' => $tenant->id,
                        'orcamento_id' => $orcamento->id,
                        'orcamento_etapa_id' => $targetEtapa->id,
                        'created_by_id' => $request->user()->id,
                        'item_type' => $sourceItem->item_type,
                        'orcamento_composicao_id' => $sourceItem->orcamento_composicao_id,
                        'orcamento_insumo_id' => $sourceItem->orcamento_insumo_id,
                        'ordem' => $sourceItem->ordem,
                        'codigo' => $sourceItem->codigo,
                        'banco' => $sourceItem->banco,
                        'descricao' => $sourceItem->descricao,
                        'unidade' => $sourceItem->unidade,
                        'quantidade' => $sourceItem->quantidade,
                        'valor_unitario_nao_desonerado' => $sourceItem->valor_unitario_nao_desonerado,
                        'valor_unitario_desonerado' => $sourceItem->valor_unitario_desonerado,
                        'valor_com_bdi_nao_desonerado' => $sourceItem->valor_com_bdi_nao_desonerado,
                        'valor_com_bdi_desonerado' => $sourceItem->valor_com_bdi_desonerado,
                        'valor_total_nao_desonerado' => $sourceItem->valor_total_nao_desonerado,
                        'valor_total_desonerado' => $sourceItem->valor_total_desonerado,
                        'aplicar_bdi' => $sourceItem->aplicar_bdi,
                        'meta' => $meta,
                    ]);
                });

            $this->recalculateOrcamentoTotals($tenant, $orcamento);
        });

        return redirect()
            ->route('tenant.orcamentos.show', [$tenant, $orcamento])
            ->with('success', 'Itens copiados para o orçamento.');
    }

    public function close(Request $request, Tenant $tenant, Orcamento $orcamento): RedirectResponse
    {
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);
        abort_unless((int) $orcamento->tenant_id === (int) $tenant->id, 404);

        if ($orcamento->status === 'closed') {
            return back()->with('success', 'Orcamento ja estava finalizado.');
        }

        $orcamento->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by_id' => $request->user()->id,
        ])->save();

        return back()->with('success', 'Orcamento finalizado. Ele agora pode ser usado na medicao e nao pode mais ser alterado.');
    }

    public function downloadRelatorioSintetico(Request $request, Tenant $tenant, Orcamento $orcamento): StreamedResponse
    {
        abort_unless((int) $orcamento->tenant_id === (int) $tenant->id, 404);

        $this->loadOrcamentoForReport($orcamento);

        $spreadsheet = $this->buildOrcamentoSinteticoSpreadsheet($tenant, $orcamento);

        return $this->streamOrcamentoReport($spreadsheet, $this->orcamentoReportFileName($orcamento, 'sintetico'));
    }

    public function downloadRelatorioResumo(Request $request, Tenant $tenant, Orcamento $orcamento): StreamedResponse
    {
        abort_unless((int) $orcamento->tenant_id === (int) $tenant->id, 404);

        $this->loadOrcamentoForReport($orcamento);

        $spreadsheet = $this->buildOrcamentoResumoSpreadsheet($tenant, $orcamento);

        return $this->streamOrcamentoReport($spreadsheet, $this->orcamentoReportFileName($orcamento, 'resumo'));
    }

    public function downloadRelatoriosZip(Request $request, Tenant $tenant, Orcamento $orcamento): BinaryFileResponse
    {
        abort_unless((int) $orcamento->tenant_id === (int) $tenant->id, 404);

        $data = $request->validate([
            'reports' => ['required', 'array', 'min:2'],
            'reports.*' => ['required', 'string', Rule::in(['sintetico', 'resumo'])],
        ]);

        $reports = collect($data['reports'])->unique()->values();
        $this->loadOrcamentoForReport($orcamento);

        $tempDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deming-reports';

        if (! is_dir($tempDirectory)) {
            mkdir($tempDirectory, 0775, true);
        }

        $zipPath = tempnam($tempDirectory, 'orcamento_reports_');
        $zip = new ZipArchive();

        abort_unless($zip->open($zipPath, ZipArchive::OVERWRITE) === true, 500, 'Não foi possível gerar o arquivo ZIP.');

        $temporaryFiles = [];

        try {
            foreach ($reports as $reportType) {
                $spreadsheet = $this->buildOrcamentoReportSpreadsheet($tenant, $orcamento, $reportType);
                $fileName = $this->orcamentoReportFileName($orcamento, $reportType);
                $reportPath = $tempDirectory.DIRECTORY_SEPARATOR.Str::uuid().'.xlsx';

                $writer = new Xlsx($spreadsheet);
                $writer->setPreCalculateFormulas(false);
                $writer->save($reportPath);
                $spreadsheet->disconnectWorksheets();

                $temporaryFiles[] = $reportPath;
                $zip->addFile($reportPath, $fileName);
            }
        } finally {
            $zip->close();

            foreach ($temporaryFiles as $temporaryFile) {
                if (is_file($temporaryFile)) {
                    unlink($temporaryFile);
                }
            }
        }

        return response()
            ->download($zipPath, Str::slug('orcamento-'.$orcamento->codigo.'-relatorios').'.zip', [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend(true);
    }

    private function streamOrcamentoReport(Spreadsheet $spreadsheet, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function loadOrcamentoForReport(Orcamento $orcamento): void
    {
        $orcamento->load([
            'clienteEmpresa:id,nome',
            'etapas' => fn ($query) => $query
                ->with(['itens' => fn ($query) => $query->orderBy('ordem')->orderBy('id')])
                ->orderBy('ordem'),
        ]);
        $this->sortLoadedOrcamentoEtapas($orcamento);
    }

    private function buildOrcamentoReportSpreadsheet(Tenant $tenant, Orcamento $orcamento, string $reportType): Spreadsheet
    {
        return match ($reportType) {
            'resumo' => $this->buildOrcamentoResumoSpreadsheet($tenant, $orcamento),
            default => $this->buildOrcamentoSinteticoSpreadsheet($tenant, $orcamento),
        };
    }

    private function orcamentoReportFileName(Orcamento $orcamento, string $reportType): string
    {
        return Str::slug('orcamento-'.$orcamento->codigo.'-'.$reportType).'.xlsx';
    }

    public function storeEtapa(Request $request, Tenant $tenant, Orcamento $orcamento): RedirectResponse
    {
        abort_unless((int) $orcamento->tenant_id === (int) $tenant->id, 404);
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);
        $this->ensureOrcamentoIsOpen($orcamento);

        $data = $request->validate([
            'ordem' => ['nullable', 'string', 'max:40', 'regex:/^\d+(\.\d+)*$/'],
            'after_etapa_id' => [
                'nullable',
                Rule::exists('orcamento_etapas', 'id')
                    ->where('tenant_id', $tenant->id)
                    ->where('orcamento_id', $orcamento->id)
                    ->whereNull('deleted_at'),
            ],
            'descricao' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $tenant, $orcamento, $request): void {
            $ordem = $this->normalizeEtapaOrder($data['ordem'] ?? null);

            if ($ordem === null && filled($data['after_etapa_id'] ?? null)) {
                $afterEtapa = OrcamentoEtapa::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('orcamento_id', $orcamento->id)
                    ->findOrFail($data['after_etapa_id']);

                $ordem = $this->nextChildEtapaOrder($tenant, $orcamento, $afterEtapa);
            }

            if ($ordem === null) {
                $ordem = $this->nextRootEtapaOrder($tenant, $orcamento);
            }

            $this->assertEtapaOrderCanBeUsed($tenant, $orcamento, $ordem);
            $this->assertEtapaParentExists($tenant, $orcamento, $ordem);
            $this->assertEtapaOrderDoesNotConflictWithItem($tenant, $orcamento, $ordem);

            OrcamentoEtapa::create([
                'tenant_id' => $tenant->id,
                'orcamento_id' => $orcamento->id,
                'created_by_id' => $request->user()->id,
                'ordem' => $ordem,
                'descricao' => trim($data['descricao']),
                'quantidade' => '1.000000',
            ]);

            $this->recalculateOrcamentoTotals($tenant, $orcamento);
        });

        return redirect()
            ->route('tenant.orcamentos.show', [$tenant, $orcamento])
            ->with('success', 'Etapa criada.');
    }

    public function updateEtapa(Request $request, Tenant $tenant, Orcamento $orcamento, OrcamentoEtapa $etapa): RedirectResponse
    {
        $this->authorizeOrcamentoEtapa($request, $tenant, $orcamento, $etapa);
        $this->ensureOrcamentoIsOpen($orcamento);

        $data = $request->validate([
            'ordem' => ['nullable', 'string', 'max:40', 'regex:/^\d+(\.\d+)*$/'],
            'descricao' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $tenant, $orcamento, $etapa): void {
            $currentOrder = (string) $etapa->ordem;
            $newOrder = $this->normalizeEtapaOrder($data['ordem'] ?? $currentOrder) ?? $currentOrder;

            if ($newOrder !== $currentOrder) {
                $this->assertEtapaOrderCanBeUsed($tenant, $orcamento, $newOrder, $etapa, $currentOrder);
                $this->assertEtapaParentExists($tenant, $orcamento, $newOrder, $etapa, $currentOrder);
                $this->assertEtapaOrderDoesNotConflictWithItem($tenant, $orcamento, $newOrder, $etapa, $currentOrder);
            }

            $etapa->update([
                'ordem' => $newOrder,
                'descricao' => trim($data['descricao']),
                'quantidade' => '1.000000',
            ]);

            if ($newOrder !== $currentOrder) {
                $this->updateDescendantEtapaPrefixes($tenant, $orcamento, $currentOrder, $newOrder);
            }

            $this->recalculateOrcamentoTotals($tenant, $orcamento);
        });

        return redirect()
            ->route('tenant.orcamentos.show', [$tenant, $orcamento])
            ->with('success', 'Etapa atualizada.');
    }

    public function toggleEtapaVisibility(Request $request, Tenant $tenant, Orcamento $orcamento, OrcamentoEtapa $etapa): RedirectResponse
    {
        $this->authorizeOrcamentoEtapa($request, $tenant, $orcamento, $etapa);
        $this->ensureOrcamentoIsOpen($orcamento);

        $meta = $etapa->meta ?? [];
        $hidden = ! (bool) ($meta['hidden'] ?? false);
        $meta['hidden'] = $hidden;

        $etapa->update(['meta' => $meta]);

        return redirect()
            ->route('tenant.orcamentos.show', [$tenant, $orcamento])
            ->with('success', $hidden ? 'Etapa ocultada.' : 'Etapa exibida.');
    }

    public function destroyEtapa(Request $request, Tenant $tenant, Orcamento $orcamento, OrcamentoEtapa $etapa): RedirectResponse
    {
        $this->authorizeOrcamentoEtapa($request, $tenant, $orcamento, $etapa);
        $this->ensureOrcamentoIsOpen($orcamento);

        DB::transaction(function () use ($tenant, $orcamento, $etapa): void {
            OrcamentoItem::query()
                ->where('tenant_id', $tenant->id)
                ->where('orcamento_id', $orcamento->id)
                ->where('orcamento_etapa_id', $etapa->id)
                ->delete();

            $etapa->delete();
            $this->recalculateOrcamentoTotals($tenant, $orcamento);
        });

        return redirect()
            ->route('tenant.orcamentos.show', [$tenant, $orcamento])
            ->with('success', 'Etapa excluida.');
    }

    public function orcamentoComposicaoOptions(Request $request, Tenant $tenant, Orcamento $orcamento): JsonResponse
    {
        abort_unless((int) $orcamento->tenant_id === (int) $tenant->id, 404);
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        $filters = $request->validate([
            'codigo' => ['nullable', 'string', 'max:80'],
            'descricao' => ['nullable', 'string', 'max:160'],
        ]);

        $query = $this->composicoesAvailableForTenant($tenant)
            ->withCount('items')
            ->withSum('items as items_preco_onerado_sum', 'preco_onerado')
            ->withSum('items as items_preco_desonerado_sum', 'preco_desonerado');

        $this->applyInsensitiveLike($query, 'codigo', $filters['codigo'] ?? null);
        $this->applyInsensitiveLike($query, 'descricao', $filters['descricao'] ?? null);

        $references = $this->orcamentoSelectedReferences($orcamento);

        $query->where(function (Builder $query) use ($tenant, $references): void {
            $query->where(function (Builder $query) use ($tenant): void {
                $query->where('tenant_id', $tenant->id)
                    ->where('is_global', false);
            });

            if ($references === []) {
                return;
            }

            $query->orWhere(function (Builder $query) use ($references): void {
                $query->where('is_global', true)
                    ->where(function (Builder $query) use ($references): void {
                        foreach ($references as $reference) {
                            $query->orWhere(function (Builder $query) use ($reference): void {
                                $query->where('modelo', $reference['base']);

                                if ($reference['uf']) {
                                    $query->where('uf', $reference['uf']);
                                }
                            });
                        }
                    });
            });
        });

        $composicoes = $query
            ->orderBy('codigo')
            ->limit(300)
            ->get()
            ->filter(fn (OrcamentoComposicao $composicao): bool => $this->composicaoAllowedForOrcamento($tenant, $orcamento, $composicao))
            ->take(80)
            ->values();

        $summaries = $this->composicaoListPriceSummaries($tenant, $composicoes);

        return response()->json([
            'options' => $composicoes
                ->map(fn (OrcamentoComposicao $composicao): array => $this->serializeOrcamentoComposicaoOption(
                    $composicao,
                    $summaries[$composicao->id] ?? null,
                ))
                ->values()
                ->all(),
        ]);
    }

    public function orcamentoInsumoOptions(Request $request, Tenant $tenant, Orcamento $orcamento): JsonResponse
    {
        abort_unless((int) $orcamento->tenant_id === (int) $tenant->id, 404);
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);

        $filters = $request->validate([
            'codigo' => ['nullable', 'string', 'max:80'],
            'descricao' => ['nullable', 'string', 'max:160'],
        ]);

        $references = $this->orcamentoSelectedReferences($orcamento);

        $query = $this->insumosAvailableForTenant($tenant)
            ->where(function (Builder $query) use ($tenant, $references): void {
                $query->where('tenant_id', $tenant->id);

                if ($references === []) {
                    return;
                }

                $query->orWhere(function (Builder $query) use ($references): void {
                    $query->whereNull('tenant_id')
                        ->where(function (Builder $query) use ($references): void {
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
                });
            });

        $this->applyInsensitiveLike($query, 'codigo_insumo', $filters['codigo'] ?? null);
        $this->applyInsensitiveLike($query, 'descricao', $filters['descricao'] ?? null);

        if (! (bool) $orcamento->permitir_insumos_preco_zerado) {
            $priceColumn = $orcamento->encargos_sociais === 'nao_desonerado'
                ? 'preco_nao_desonerado'
                : 'preco_desonerado';

            $query->whereRaw("COALESCE({$priceColumn}, 0) > 0");
        }

        return response()->json([
            'options' => $query
                ->orderBy('descricao')
                ->limit(80)
                ->get()
                ->filter(fn (OrcamentoInsumo $insumo): bool => $this->insumoAllowedForOrcamento($tenant, $orcamento, $insumo))
                ->map(fn (OrcamentoInsumo $insumo): array => $this->serializeOrcamentoInsumoOption($insumo))
                ->values()
                ->all(),
        ]);
    }

    public function storeOrcamentoComposicaoItem(
        Request $request,
        Tenant $tenant,
        Orcamento $orcamento,
        OrcamentoEtapa $etapa,
    ): RedirectResponse {
        $this->authorizeOrcamentoEtapa($request, $tenant, $orcamento, $etapa);
        $this->ensureOrcamentoIsOpen($orcamento);

        $data = $request->validate([
            'orcamento_composicao_id' => ['required', 'integer'],
            'quantidade' => ['required', 'string', 'max:30'],
            'ordem' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'aplicar_bdi' => ['boolean'],
        ]);

        $quantidade = $this->parseDecimal($data['quantidade'] ?? null);

        if ($quantidade === null || (float) $quantidade <= 0) {
            throw ValidationException::withMessages([
                'quantidade' => 'Informe uma quantidade maior que zero.',
            ]);
        }

        $composicao = $this->composicoesAvailableForTenant($tenant)
            ->withCount('items')
            ->withSum('items as items_preco_onerado_sum', 'preco_onerado')
            ->withSum('items as items_preco_desonerado_sum', 'preco_desonerado')
            ->findOrFail($data['orcamento_composicao_id']);

        if (! $this->composicaoAllowedForOrcamento($tenant, $orcamento, $composicao)) {
            throw ValidationException::withMessages([
                'orcamento_composicao_id' => 'Esta composicao nao pertence as bases selecionadas para o orcamento.',
            ]);
        }

        DB::transaction(function () use ($data, $tenant, $orcamento, $etapa, $request, $quantidade, $composicao): void {
            $ordem = (int) ($data['ordem'] ?? 0);

            if ($ordem <= 0) {
                $ordem = $this->nextChildItemOrder($tenant, $orcamento, $etapa);
            }

            $this->assertOrcamentoItemOrderCanBeUsed($tenant, $orcamento, $etapa, $ordem);

            $prices = $this->orcamentoComposicaoPrices($tenant, $composicao);
            $item = OrcamentoItem::create([
                'tenant_id' => $tenant->id,
                'orcamento_id' => $orcamento->id,
                'orcamento_etapa_id' => $etapa->id,
                'created_by_id' => $request->user()->id,
                'item_type' => 'composicao',
                'orcamento_composicao_id' => $composicao->id,
                'ordem' => $ordem,
                'codigo' => $composicao->codigo,
                'banco' => $composicao->is_global ? $composicao->modelo : 'PROPRIA',
                'descricao' => $composicao->descricao,
                'unidade' => $composicao->unidade,
                'quantidade' => $quantidade,
                'valor_unitario_nao_desonerado' => $prices['nao_desonerado'],
                'valor_unitario_desonerado' => $prices['desonerado'],
                'aplicar_bdi' => (bool) ($data['aplicar_bdi'] ?? false),
                'meta' => [
                    'base_label' => $this->orcamentoComposicaoBaseLabel($composicao),
                ],
            ]);

            $this->recalculateOrcamentoItemTotals($item, $orcamento);
            $this->renumberOrcamentoItems($tenant, $etapa);
            $this->recalculateOrcamentoTotals($tenant, $orcamento);
        });

        return redirect()
            ->route('tenant.orcamentos.show', [$tenant, $orcamento])
            ->with('success', 'Composicao adicionada ao orcamento.');
    }

    public function storeOrcamentoInsumoItem(
        Request $request,
        Tenant $tenant,
        Orcamento $orcamento,
        OrcamentoEtapa $etapa,
    ): RedirectResponse {
        $this->authorizeOrcamentoEtapa($request, $tenant, $orcamento, $etapa);
        $this->ensureOrcamentoIsOpen($orcamento);

        $data = $request->validate([
            'orcamento_insumo_id' => ['required', 'integer'],
            'quantidade' => ['required', 'string', 'max:30'],
            'ordem' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'aplicar_bdi' => ['boolean'],
            'valor_unitario_manual' => ['nullable', 'string', 'max:30'],
        ]);

        $quantidade = $this->parseDecimal($data['quantidade'] ?? null);

        if ($quantidade === null || (float) $quantidade <= 0) {
            throw ValidationException::withMessages([
                'quantidade' => 'Informe uma quantidade maior que zero.',
            ]);
        }

        $insumo = $this->insumosAvailableForTenant($tenant)->findOrFail($data['orcamento_insumo_id']);

        if (! $this->insumoAllowedForOrcamento($tenant, $orcamento, $insumo)) {
            throw ValidationException::withMessages([
                'orcamento_insumo_id' => 'Este insumo nao pertence as bases selecionadas para o orcamento.',
            ]);
        }

        $prices = $this->orcamentoInsumoPrices($insumo);
        $selectedPrice = $orcamento->encargos_sociais === 'nao_desonerado'
            ? (float) $prices['nao_desonerado']
            : (float) $prices['desonerado'];
        $manualPrice = $this->parseDecimal($data['valor_unitario_manual'] ?? null);

        if ($selectedPrice <= 0) {
            if (! (bool) $orcamento->permitir_insumos_preco_zerado) {
                throw ValidationException::withMessages([
                    'orcamento_insumo_id' => 'Este orcamento nao permite adicionar insumos com preco zerado.',
                ]);
            }

            if ($manualPrice === null || (float) $manualPrice <= 0) {
                throw ValidationException::withMessages([
                    'valor_unitario_manual' => 'Informe o valor unitario deste insumo para o orcamento.',
                ]);
            }

            $prices = [
                'nao_desonerado' => $this->storeMoney($manualPrice),
                'desonerado' => $this->storeMoney($manualPrice),
            ];
        }

        DB::transaction(function () use ($data, $tenant, $orcamento, $etapa, $request, $quantidade, $insumo, $prices, $manualPrice, $selectedPrice): void {
            $ordem = (int) ($data['ordem'] ?? 0);

            if ($ordem <= 0) {
                $ordem = $this->nextChildItemOrder($tenant, $orcamento, $etapa);
            }

            $this->assertOrcamentoItemOrderCanBeUsed($tenant, $orcamento, $etapa, $ordem);

            $baseLabel = trim(implode(' - ', array_filter([
                $insumo->banco,
                $insumo->uf,
                $insumo->data_referencia?->format('m/Y'),
            ])));

            $item = OrcamentoItem::create([
                'tenant_id' => $tenant->id,
                'orcamento_id' => $orcamento->id,
                'orcamento_etapa_id' => $etapa->id,
                'created_by_id' => $request->user()->id,
                'item_type' => 'insumo',
                'orcamento_insumo_id' => $insumo->id,
                'ordem' => $ordem,
                'codigo' => $insumo->codigo_insumo,
                'banco' => $insumo->banco,
                'descricao' => $insumo->descricao,
                'unidade' => $insumo->unidade,
                'quantidade' => $quantidade,
                'valor_unitario_nao_desonerado' => $prices['nao_desonerado'],
                'valor_unitario_desonerado' => $prices['desonerado'],
                'aplicar_bdi' => (bool) ($data['aplicar_bdi'] ?? false),
                'meta' => [
                    'base_label' => $baseLabel,
                    'manual_price' => $selectedPrice <= 0,
                    'manual_price_value' => $selectedPrice <= 0 ? $this->storeMoney($manualPrice) : null,
                ],
            ]);

            $this->recalculateOrcamentoItemTotals($item, $orcamento);
            $this->renumberOrcamentoItems($tenant, $etapa);
            $this->recalculateOrcamentoTotals($tenant, $orcamento);
        });

        return redirect()
            ->route('tenant.orcamentos.show', [$tenant, $orcamento])
            ->with('success', 'Insumo adicionado ao orcamento.');
    }

    public function toggleOrcamentoItemBdi(Request $request, Tenant $tenant, Orcamento $orcamento, OrcamentoItem $item): RedirectResponse
    {
        $this->authorizeOrcamentoItem($request, $tenant, $orcamento, $item);
        $this->ensureOrcamentoIsOpen($orcamento);

        $data = $request->validate([
            'bdi_percentual' => ['required', 'string', 'max:30'],
        ]);

        $bdiPercentual = $this->parseDecimal($data['bdi_percentual'] ?? null);

        if ($bdiPercentual === null || (float) $bdiPercentual < 0) {
            throw ValidationException::withMessages([
                'bdi_percentual' => 'Informe um percentual de BDI valido.',
            ]);
        }

        DB::transaction(function () use ($tenant, $orcamento, $item, $bdiPercentual): void {
            $meta = $item->meta ?? [];
            $meta['bdi_percentual'] = $this->storeMoney($bdiPercentual);
            $meta['bdi_diferenciado'] = true;

            $item->forceFill([
                'aplicar_bdi' => true,
                'meta' => $meta,
            ]);

            $this->recalculateOrcamentoItemTotals($item, $orcamento);

            $etapa = OrcamentoEtapa::query()
                ->where('tenant_id', $tenant->id)
                ->where('orcamento_id', $orcamento->id)
                ->findOrFail($item->orcamento_etapa_id);

            $this->recalculateOrcamentoTotals($tenant, $orcamento);
            $this->renumberOrcamentoItems($tenant, $etapa);
        });

        return redirect()
            ->route('tenant.orcamentos.show', [$tenant, $orcamento])
            ->with('success', 'BDI diferenciado aplicado ao item.');
    }

    public function updateOrcamentoItem(Request $request, Tenant $tenant, Orcamento $orcamento, OrcamentoItem $item): RedirectResponse
    {
        $this->authorizeOrcamentoItem($request, $tenant, $orcamento, $item);
        $this->ensureOrcamentoIsOpen($orcamento);

        $data = $request->validate([
            'ordem' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'quantidade' => ['required', 'string', 'max:30'],
            'aplicar_bdi' => ['boolean'],
        ]);

        $quantidade = $this->parseDecimal($data['quantidade'] ?? null);

        if ($quantidade === null || (float) $quantidade <= 0) {
            throw ValidationException::withMessages([
                'quantidade' => 'Informe uma quantidade maior que zero.',
            ]);
        }

        DB::transaction(function () use ($data, $tenant, $orcamento, $item, $quantidade): void {
            $etapa = OrcamentoEtapa::query()
                ->where('tenant_id', $tenant->id)
                ->where('orcamento_id', $orcamento->id)
                ->findOrFail($item->orcamento_etapa_id);

            $currentOrder = (int) $item->ordem;
            $newOrder = (int) ($data['ordem'] ?? $currentOrder);
            $newOrder = max(1, $newOrder);
            $this->assertOrcamentoItemOrderCanBeUsed($tenant, $orcamento, $etapa, $newOrder, $item);

            $item->forceFill([
                'ordem' => $newOrder,
                'quantidade' => $quantidade,
                'aplicar_bdi' => (bool) ($data['aplicar_bdi'] ?? false),
            ]);

            $this->recalculateOrcamentoItemTotals($item, $orcamento);
            $this->renumberOrcamentoItems($tenant, $etapa);
            $this->recalculateOrcamentoTotals($tenant, $orcamento);
        });

        return redirect()
            ->route('tenant.orcamentos.show', [$tenant, $orcamento])
            ->with('success', 'Item atualizado no orcamento.');
    }

    public function destroyOrcamentoItem(Request $request, Tenant $tenant, Orcamento $orcamento, OrcamentoItem $item): RedirectResponse
    {
        $this->authorizeOrcamentoItem($request, $tenant, $orcamento, $item);
        $this->ensureOrcamentoIsOpen($orcamento);

        DB::transaction(function () use ($tenant, $orcamento, $item): void {
            $etapa = OrcamentoEtapa::query()
                ->where('tenant_id', $tenant->id)
                ->where('orcamento_id', $orcamento->id)
                ->findOrFail($item->orcamento_etapa_id);

            $item->delete();
            $this->renumberOrcamentoItems($tenant, $etapa);
            $this->recalculateOrcamentoTotals($tenant, $orcamento);
        });

        return redirect()
            ->route('tenant.orcamentos.show', [$tenant, $orcamento])
            ->with('success', 'Item excluido do orcamento.');
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

            return $this->queueComposicoesImport(
                $request,
                $tenant,
                'tenant_mapped',
                $data,
                'Importacao de composicoes da base propria enviada para processamento. Voce pode continuar usando o sistema enquanto o servidor grava os lotes em segundo plano.',
            );
        }

        if (! $request->filled('first_item_row')) {
            $lineCount = count(file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES));
            $request->merge([
                'first_item_row' => 2,
                'last_item_row' => max(2, $lineCount),
                'data_column' => 'H',
                'tipo_column' => 'A',
                'codigo_column' => 'B',
                'descricao_column' => 'C',
                'unidade_column' => 'D',
                'uf_column' => 'E',
                'preco_nao_desonerado_column' => 'F',
                'preco_desonerado_column' => 'G',
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

        return $this->queueComposicoesImport(
            $request,
            $tenant,
            'global_mapped',
            $data,
            'Importacao global de composicoes enviada para processamento. Voce pode continuar usando o sistema enquanto o servidor grava os lotes em segundo plano.',
        );
    }

    public function importComposicoesAnalitico(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['global'])],
            'modelo' => ['required', 'string', Rule::in(['SINAPI', 'SICRO3'])],
            'file' => ['required', 'file', 'mimes:csv,txt,tsv', 'max:'.self::CSV_UPLOAD_MAX_KB],
        ]);

        $this->authorizeInsumoScope($request, $tenant, 'global');

        return $this->queueComposicoesImport(
            $request,
            $tenant,
            'global_analytic',
            $data,
            'Importacao analitica enviada para processamento. Voce pode continuar usando o sistema enquanto o servidor grava os vinculos em segundo plano.',
        );
    }

    private function queueComposicoesImport(Request $request, Tenant $tenant, string $type, array $data, string $message): RedirectResponse
    {
        $path = $request->file('file')->store('imports/orcamentos/composicoes', 'local');

        ImportOrcamentoComposicoesJob::dispatch(
            $tenant->id,
            $request->user()->id,
            $type,
            $path,
            $data,
        );

        DeleteStoredImportFileJob::dispatch($path)->delay(now()->addHours(6));

        return back()->with('success', $message.' O arquivo temporario sera removido automaticamente apos o processamento ou em ate 6 horas.');
    }

    public function runQueuedComposicoesImport(string $type, string $path, Tenant $tenant, int $userId, array $data): array
    {
        return match ($type) {
            'tenant_mapped' => $this->importOwnMappedComposicaoCsv($path, $tenant, $userId, $data),
            'global_mapped' => $this->importMappedComposicaoCsv($path, $tenant, $userId, $data, mb_strtoupper((string) ($data['modelo'] ?? 'SINAPI')), true),
            'global_analytic' => $this->importComposicoesAnaliticoCsv($path, $tenant, mb_strtoupper((string) ($data['modelo'] ?? 'SINAPI')), true, $userId),
            default => throw new \InvalidArgumentException("Tipo de importacao de composicoes invalido: {$type}"),
        };
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
            'unidade' => ['unidade', 'unidade_item', 'unid_item', 'unid', 'un'],
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

        $chunkSize = $this->csvInsertChunkSize();

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
            'descricao_composicao' => ['descricao_composicao', 'descricao_da_composicao', 'desc_composicao'],
            'unidade_composicao' => ['unidade_composicao', 'unidade_da_composicao', 'unid_composicao'],
            'producao_equipe' => ['producao_equipe', 'produção_equipe', 'producao_da_equipe'],
            'fic' => ['fic', 'fator_influencia_chuvas', 'fator_de_influencia_de_chuvas'],
            'secao' => ['secao', 'seção', 'grupo_item', 'categoria_item'],
            'tipo_item' => ['tipo_item', 'tipo_do_item', 'item_tipo', 'tipo'],
            'tipo_transporte' => ['tipo_transporte', 'tipo_do_transporte', 'transporte'],
            'codigo_item' => ['codigo_do', 'codigo_do_item', 'codigo_item', 'cod_item', 'codigo_insumo', 'codigo_composicao_item'],
            'codigo_item_referenciado' => ['codigo_item_referenciado', 'codigo_do_item_referenciado', 'item_referenciado', 'codigo_referenciado'],
            'descricao' => ['descricao', 'descricao_item', 'desc'],
            'unidade' => ['unidade', 'unid', 'un'],
            'coeficiente' => ['coeficiente', 'coef'],
            'utilizacao_operativa' => ['utilizacao_operativa', 'utilização_operativa', 'uso_operativo'],
            'utilizacao_improdutiva' => ['utilizacao_improdutiva', 'utilização_improdutiva', 'uso_improdutivo'],
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

        $result = ['read' => 0, 'created' => 0, 'updated' => 0, 'duplicated' => 0, 'composition_headers' => 0, 'skipped' => 0];
        $seenImportKeys = [];
        $batch = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->isBlankCsvRow($row)) {
                continue;
            }

            $result['read']++;
            $payload = $this->payloadFromComposicaoAnaliticoCsvRow($row, $headerMap, $model);

            if ($payload === []) {
                $result['composition_headers']++;
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
            'descricao_composicao' => $value('descricao_composicao') !== '' ? $value('descricao_composicao') : null,
            'unidade_composicao' => $value('unidade_composicao') !== '' ? mb_strtoupper($value('unidade_composicao')) : null,
            'producao_equipe' => $this->parseDecimal($value('producao_equipe')),
            'fator_influencia_chuvas' => $this->parseDecimal($value('fic')),
            'tipo_item' => $tipoItem,
            'codigo_item' => $codigoItem,
            'descricao_item' => $value('descricao') !== '' ? $value('descricao') : null,
            'unidade' => $value('unidade') !== '' ? mb_strtoupper($value('unidade')) : null,
            'uf' => in_array($state, self::BRAZILIAN_STATES, true) ? $state : null,
            'data_referencia' => $referenceDate?->toDateString(),
            'coeficiente' => $this->parseCoefficient($value('coeficiente')),
            'secao' => $value('secao') !== '' ? mb_strtoupper($value('secao')) : null,
            'tipo_transporte' => $value('tipo_transporte') !== '' ? mb_strtoupper($value('tipo_transporte')) : null,
            'codigo_item_referenciado' => $value('codigo_item_referenciado') !== '' ? $value('codigo_item_referenciado') : null,
            'utilizacao_operativa' => $this->parseDecimal($value('utilizacao_operativa')),
            'utilizacao_improdutiva' => $this->parseDecimal($value('utilizacao_improdutiva')),
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
            $payload['secao'] ?? null,
            $payload['tipo_transporte'] ?? null,
            $payload['codigo_item_referenciado'] ?? null,
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
        ?string $section,
        ?string $transportType,
        ?string $referencedItemCode,
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
            $section ? mb_strtoupper($section) : '__NULL__',
            $transportType ? mb_strtoupper($transportType) : '__NULL__',
            $referencedItemCode ? $this->codeKey($referencedItemCode) : '__NULL__',
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
                        'raw_payload' => null,
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
                'raw_payload' => null,
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
            ->select([
                'id',
                'tenant_id',
                'is_global',
                'modelo',
                'codigo_composicao',
                'tipo_item',
                'codigo_item',
                'uf',
                'data_referencia',
                'secao',
                'tipo_transporte',
                'codigo_item_referenciado',
            ])
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
                $record->secao ? (string) $record->secao : null,
                $record->tipo_transporte ? (string) $record->tipo_transporte : null,
                $record->codigo_item_referenciado ? (string) $record->codigo_item_referenciado : null,
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

        $chunkSize = $this->csvInsertChunkSize();

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
            $chunkSize = $this->csvInsertChunkSize();

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

        $chunkSize = $this->csvInsertChunkSize();

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

    private function csvInsertChunkSize(): int
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? self::POSTGRES_CSV_INSERT_CHUNK_SIZE
            : 50;
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

        if (Str::contains($type, ['atividade auxiliar', 'atividades auxiliares', 'atividade', 'activity'])) {
            return 'atividades_auxiliares';
        }

        return null;
    }

    private function sicro3SectionFromAnaliticoItem(OrcamentoComposicaoAnaliticoItem $item, ?string $sourceType = null): ?string
    {
        $section = Str::of((string) $item->secao)
            ->ascii()
            ->lower()
            ->replace(['-', '_'], ' ')
            ->squish()
            ->toString();

        $sectionMap = [
            'a' => 'equipamentos',
            'equipamento' => 'equipamentos',
            'equipamentos' => 'equipamentos',
            'b' => 'mao_de_obra',
            'mao de obra' => 'mao_de_obra',
            'mão de obra' => 'mao_de_obra',
            'c' => 'material',
            'material' => 'material',
            'd' => 'atividades_auxiliares',
            'atividade auxiliar' => 'atividades_auxiliares',
            'atividades auxiliares' => 'atividades_auxiliares',
            'e' => 'tempo_fixo',
            'tempo fixo' => 'tempo_fixo',
            'f' => 'momento_transporte',
            'momento de transporte' => 'momento_transporte',
            'momento transporte' => 'momento_transporte',
        ];

        if (isset($sectionMap[$section])) {
            return $sectionMap[$section];
        }

        if ($item->tipo_item === 'insumo') {
            return $this->sicro3SectionFromType($sourceType);
        }

        return null;
    }

    private function sicro3TransportCodesFromAnaliticoItem(OrcamentoComposicaoAnaliticoItem $item): array
    {
        $codes = ['ln' => null, 'rp' => null, 'p' => null, 'fe' => null];
        $type = Str::of((string) $item->tipo_transporte)
            ->ascii()
            ->lower()
            ->trim()
            ->toString();

        if (array_key_exists($type, $codes)) {
            $codes[$type] = $item->codigo_item;
        }

        return $codes;
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
                $item->uf,
                $item->tipo_item,
                $this->codeKey($item->codigo_item),
                $item->secao ?: '',
                $item->tipo_transporte ?: '',
                $item->codigo_item_referenciado ?: '',
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
        $isSicro3 = mb_strtoupper((string) $stateComposicao->modelo) === 'SICRO3';
        $sicro3Summary = $isSicro3
            ? $this->sicro3AnaliticoStateSummary($stateComposicao, $items, $analyticItems, $calculationMethod)
            : null;
        $computedOnerado = $sicro3Summary
            ? (float) $sicro3Summary['preco_onerado']
            : $this->calculateMoney($items->sum('raw_preco_onerado'), $calculationMethod);
        $computedDesonerado = $sicro3Summary
            ? (float) $sicro3Summary['preco_desonerado']
            : $this->calculateMoney($items->sum('raw_preco_desonerado'), $calculationMethod);
        $rawOnerado = (float) ($stateComposicao->preco_onerado ?? 0);
        $rawDesonerado = (float) ($stateComposicao->preco_desonerado ?? 0);
        $usesAnalyticPrice = ($rawOnerado <= 0 && $computedOnerado > 0) || ($rawDesonerado <= 0 && $computedDesonerado > 0);
        $missingPriceItems = (int) $items->sum('missing_price_items_count');

        return [
            'uf' => $stateComposicao->uf,
            'estado_label' => $this->stateLabel($stateComposicao->uf),
            'composicao_id' => $stateComposicao->id,
            'data' => $stateComposicao->data_referencia?->format('m/Y'),
            'producao_equipe' => $sicro3Summary['producao_equipe'] ?? $stateComposicao->producao_equipe,
            'fator_influencia_chuvas' => $sicro3Summary['fic'] ?? $stateComposicao->fator_influencia_chuvas,
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
            'sicro3_summary' => $sicro3Summary,
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
            $custoImprodutivoOnerado = $source->custo_improdutivo_nao_desonerado !== null
                ? (float) $source->custo_improdutivo_nao_desonerado
                : null;
            $custoImprodutivoDesonerado = $source->custo_improdutivo_desonerado !== null
                ? (float) $source->custo_improdutivo_desonerado
                : null;
            $typeLabel = $source->classificacao ?: $this->typeLabel($source->tipo);
            $description = $source->descricao;
            $unit = $source->unidade;
            $code = $source->codigo_insumo;
            $composicaoId = null;
        } elseif ($source instanceof OrcamentoComposicao) {
            $isSicro3Source = mb_strtoupper((string) $source->modelo) === 'SICRO3';
            $summary = $tenant
                ? ($isSicro3Source
                    ? $this->composicaoPriceSummary($tenant, $source, $visited)
                    : $this->fastComposicaoPriceSummary($source))
                : $this->rawComposicaoPriceSummary($source);

            if ($tenant && (float) $summary['effective_preco_onerado'] <= 0 && (float) $summary['effective_preco_desonerado'] <= 0) {
                $summary = $this->composicaoPriceSummary($tenant, $source, $visited);
            }

            $sourceCalculationMethod = $this->calculationMethodForComposicao($source);
            $unitOnerado = $this->calculateMoney($summary['effective_preco_onerado'], $sourceCalculationMethod);
            $unitDesonerado = $this->calculateMoney($summary['effective_preco_desonerado'], $sourceCalculationMethod);
            $missingPriceItems = (int) $summary['missing_price_items_count'];
            $custoImprodutivoOnerado = null;
            $custoImprodutivoDesonerado = null;
            $typeLabel = $source->tipo_composicao;
            $description = $source->descricao;
            $unit = $source->unidade;
            $code = $source->codigo;
            $composicaoId = $source->id;
        } else {
            $unitOnerado = 0;
            $unitDesonerado = 0;
            $custoImprodutivoOnerado = null;
            $custoImprodutivoDesonerado = null;
            $typeLabel = null;
            $description = $item->descricao_item;
            $unit = $item->unidade;
            $code = $item->codigo_item;
            $composicaoId = null;
        }
        $sicro3Section = mb_strtoupper((string) $item->modelo) === 'SICRO3'
            ? $this->sicro3SectionFromAnaliticoItem($item, $typeLabel)
            : null;
        $sicro3SectionMeta = $this->sicro3SectionMeta($sicro3Section);
        $utilizacaoOperativa = $item->utilizacao_operativa !== null ? (float) $item->utilizacao_operativa : null;
        $utilizacaoImprodutiva = $item->utilizacao_improdutiva !== null ? (float) $item->utilizacao_improdutiva : null;
        $lineTotals = $sicro3Section
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
                'fic_base_onerado' => 0.0,
                'fic_base_desonerado' => 0.0,
            ];
        $transportCodes = $this->sicro3TransportCodesFromAnaliticoItem($item);

        return [
            'id' => $state.'-'.$item->id,
            'marker' => $type === 'composicao' ? 'C' : 'I',
            'item_type' => $type,
            'item_type_label' => $type === 'composicao' ? 'Composicao' : 'Insumo',
            'composicao_id' => $composicaoId,
            'codigo' => $code,
            'descricao' => $description,
            'tipo' => $typeLabel,
            'unidade' => $unit,
            'coeficiente' => $coefficient,
            'sicro3_section' => $sicro3Section,
            'sicro3_section_code' => $sicro3SectionMeta['code'] ?? null,
            'sicro3_section_label' => $sicro3SectionMeta['label'] ?? null,
            'sicro3_utilizacao_operativa' => $utilizacaoOperativa,
            'sicro3_utilizacao_improdutiva' => $utilizacaoImprodutiva,
            'sicro3_referenced_item_code' => $item->codigo_item_referenciado,
            'sicro3_referenced_item_description' => null,
            'sicro3_transport_ln_code' => $transportCodes['ln'],
            'sicro3_transport_rp_code' => $transportCodes['rp'],
            'sicro3_transport_p_code' => $transportCodes['p'],
            'sicro3_transport_fe_code' => $transportCodes['fe'],
            'preco_unitario_onerado' => $this->calculateIntermediateMoney($unitOnerado, $calculationMethod),
            'preco_unitario_desonerado' => $this->calculateIntermediateMoney($unitDesonerado, $calculationMethod),
            'custo_improdutivo_onerado' => $this->calculateIntermediateMoney($custoImprodutivoOnerado, $calculationMethod),
            'custo_improdutivo_desonerado' => $this->calculateIntermediateMoney($custoImprodutivoDesonerado, $calculationMethod),
            'preco_onerado' => $this->calculateIntermediateMoney($lineTotals['onerado'], $calculationMethod),
            'preco_desonerado' => $this->calculateIntermediateMoney($lineTotals['desonerado'], $calculationMethod),
            'raw_preco_unitario_onerado' => $this->storeMoney($unitOnerado),
            'raw_preco_unitario_desonerado' => $this->storeMoney($unitDesonerado),
            'raw_custo_improdutivo_onerado' => $this->storeMoney($custoImprodutivoOnerado),
            'raw_custo_improdutivo_desonerado' => $this->storeMoney($custoImprodutivoDesonerado),
            'raw_preco_onerado' => $this->storeIntermediateMoney($lineTotals['onerado'], $calculationMethod),
            'raw_preco_desonerado' => $this->storeIntermediateMoney($lineTotals['desonerado'], $calculationMethod),
            'raw_fic_base_onerado' => $this->storeIntermediateMoney($lineTotals['fic_base_onerado'], $calculationMethod),
            'raw_fic_base_desonerado' => $this->storeIntermediateMoney($lineTotals['fic_base_desonerado'], $calculationMethod),
            'source_found' => (bool) $source,
            'missing_price_items_count' => $missingPriceItems,
        ];
    }

    private function sicro3AnaliticoStateSummary(OrcamentoComposicao $stateComposicao, \Illuminate\Support\Collection $items, \Illuminate\Support\Collection $analyticItems, string $calculationMethod): array
    {
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
            $section = $item['sicro3_section'] ?? null;

            if (! isset($sections[$section])) {
                continue;
            }

            $sections[$section]['onerado'] += (float) ($item['preco_onerado'] ?? $item['raw_preco_onerado'] ?? 0);
            $sections[$section]['desonerado'] += (float) ($item['preco_desonerado'] ?? $item['raw_preco_desonerado'] ?? 0);

            if ($section === 'equipamentos') {
                $ficEquipmentBaseOnerado += (float) ($item['raw_fic_base_onerado'] ?? 0);
                $ficEquipmentBaseDesonerado += (float) ($item['raw_fic_base_desonerado'] ?? 0);
            }
        }

        $analyticMeta = $analyticItems->first(fn (OrcamentoComposicaoAnaliticoItem $item): bool => $item->uf === $stateComposicao->uf)
            ?? $analyticItems->first();
        $production = max((float) ($stateComposicao->producao_equipe ?? $analyticMeta?->producao_equipe ?? 1), 0.000001);
        $fic = max((float) ($stateComposicao->fator_influencia_chuvas ?? $analyticMeta?->fator_influencia_chuvas ?? 0), 0);
        $additionalLabor = (float) ($stateComposicao->adicional_mao_obra ?? 0);

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
            + $sections['tempo_fixo']['onerado'];
        $directSectionsDesonerado = $sections['material']['desonerado']
            + $sections['atividades_auxiliares']['desonerado']
            + $sections['tempo_fixo']['desonerado'];

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
        $isSicro3 = mb_strtoupper((string) $composicao->modelo) === 'SICRO3';
        $sicro3Summary = $isSicro3
            ? $this->sicro3AnaliticoStateSummary($composicao, $items, $analyticItems, $calculationMethod)
            : null;
        $computedOnerado = $sicro3Summary
            ? (float) $sicro3Summary['preco_onerado']
            : $this->calculateMoney($items->sum('raw_preco_onerado'), $calculationMethod);
        $computedDesonerado = $sicro3Summary
            ? (float) $sicro3Summary['preco_desonerado']
            : $this->calculateMoney($items->sum('raw_preco_desonerado'), $calculationMethod);
        $rawOnerado = (float) $rawSummary['preco_onerado'];
        $rawDesonerado = (float) $rawSummary['preco_desonerado'];
        $effectiveOnerado = $isSicro3 && $sicro3Summary
            ? $computedOnerado
            : $this->calculateMoney($rawOnerado > 0 ? $rawOnerado : $computedOnerado, $calculationMethod);
        $effectiveDesonerado = $isSicro3 && $sicro3Summary
            ? $computedDesonerado
            : $this->calculateMoney($rawDesonerado > 0 ? $rawDesonerado : $computedDesonerado, $calculationMethod);
        $usesAnalyticPrice = ($isSicro3 && $sicro3Summary)
            || ($rawOnerado <= 0 && $computedOnerado > 0)
            || ($rawDesonerado <= 0 && $computedDesonerado > 0);

        return $this->composicaoPriceSummaryCache[$cacheKey] = array_merge($rawSummary, [
            'computed_preco_onerado' => $computedOnerado,
            'computed_preco_desonerado' => $computedDesonerado,
            'effective_preco_onerado' => $effectiveOnerado,
            'effective_preco_desonerado' => $effectiveDesonerado,
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
            + $sections['tempo_fixo']['onerado'];
        $directSectionsDesonerado = $sections['material']['desonerado']
            + $sections['atividades_auxiliares']['desonerado']
            + $sections['tempo_fixo']['desonerado'];

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
            $unitOnerado = $unitOnerado > 0 ? $unitOnerado : $unitDesonerado;
            $unitDesonerado = $unitDesonerado > 0 ? $unitDesonerado : $unitOnerado;
            $custoImprodutivoOnerado ??= $unitOnerado;
            $custoImprodutivoDesonerado ??= $custoImprodutivoOnerado;
            $custoImprodutivoOnerado = $custoImprodutivoOnerado > 0 ? $custoImprodutivoOnerado : $custoImprodutivoDesonerado;
            $custoImprodutivoDesonerado = $custoImprodutivoDesonerado > 0 ? $custoImprodutivoDesonerado : $custoImprodutivoOnerado;
            $utilizacaoOperativa ??= 1.0;
            $utilizacaoImprodutiva ??= 0.0;

            return [
                'onerado' => $coefficient * (($unitOnerado * $utilizacaoOperativa) + ($custoImprodutivoOnerado * $utilizacaoImprodutiva)),
                'desonerado' => $coefficient * (($unitDesonerado * $utilizacaoOperativa) + ($custoImprodutivoDesonerado * $utilizacaoImprodutiva)),
                'fic_base_onerado' => $coefficient * $custoImprodutivoOnerado,
                'fic_base_desonerado' => $coefficient * $custoImprodutivoDesonerado,
            ];
        }

        $unitOnerado = $unitOnerado > 0 ? $unitOnerado : $unitDesonerado;
        $unitDesonerado = $unitDesonerado > 0 ? $unitDesonerado : $unitOnerado;
        $lineOnerado = $unitOnerado * $coefficient;
        $lineDesonerado = $unitDesonerado * $coefficient;

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

    private function parseOptionalPercentage(mixed $value, string $field): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $parsed = $this->parseDecimal((string) $value);

        if ($parsed === null || (float) $parsed < 0) {
            throw ValidationException::withMessages([
                $field => 'Informe um percentual válido.',
            ]);
        }

        return $parsed;
    }

    private function importOrcamentoItemsCsv(
        string $path,
        Tenant $tenant,
        Orcamento $orcamento,
        int $userId,
    ): array {
        $this->prepareLongRunningImport();

        $handle = fopen($path, 'rb');

        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'Não foi possível abrir o arquivo CSV.']);
        }

        $firstLine = fgets($handle) ?: '';
        $delimiter = $this->detectCsvDelimiter($firstLine);
        rewind($handle);

        $headers = $this->readCsvHeaders($handle, $delimiter);
        $columns = $this->resolveCsvHeaderMap($headers, [
            'item' => ['item'],
            'codigo' => ['codigo', 'codigo item'],
            'banco' => ['banco', 'base'],
            'descricao' => ['descricao'],
            'unidade' => ['und', 'unidade'],
            'quantidade' => ['quant', 'quantidade', 'qtd'],
            'valor_unitario' => ['valor unit', 'valor unitario', 'preco unitario', 'preco unitario p0'],
            'valor_com_bdi' => ['valor unit com bdi', 'valor com bdi', 'preco unitario com bdi'],
            'valor_total' => ['total', 'valor total'],
        ]);

        $required = ['item', 'descricao', 'quantidade', 'valor_unitario', 'valor_com_bdi', 'valor_total'];
        $missing = collect($required)
            ->reject(fn (string $field): bool => array_key_exists($field, $columns))
            ->values()
            ->all();

        if ($missing !== []) {
            fclose($handle);

            throw ValidationException::withMessages([
                'file' => 'Colunas ausentes no CSV: '.implode(', ', $missing).'.',
            ]);
        }

        $rows = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $row = str_getcsv(rtrim($line, "\r\n"), $delimiter);

                if ($this->isBlankCsvRow($row)) {
                    continue;
                }

                $value = fn (string $field): string => array_key_exists($field, $columns)
                    ? $this->normalizeCsvValue((string) ($row[$columns[$field]] ?? ''))
                    : '';
                $itemCode = trim($value('item'));
                $description = trim($value('descricao'));

                if ($itemCode === '' || $description === '') {
                    continue;
                }

                $rows[] = [
                    'item' => preg_replace('/\s+/', '', $itemCode) ?: $itemCode,
                    'codigo' => trim($value('codigo')),
                    'banco' => trim($value('banco')),
                    'descricao' => $description,
                    'unidade' => trim($value('unidade')),
                    'quantidade' => $value('quantidade'),
                    'valor_unitario' => $value('valor_unitario'),
                    'valor_com_bdi' => $value('valor_com_bdi'),
                    'valor_total' => $value('valor_total'),
                ];
            }
        } finally {
            fclose($handle);
        }

        $stageRows = collect($rows)
            ->filter(fn (array $row): bool => $this->isImportedOrcamentoStage($row))
            ->unique('item', true)
            ->sortBy(fn (array $row): string => $this->hierarchySortKey($row['item']))
            ->values();
        $stagesByOrder = [];

        foreach ($stageRows as $row) {
            $stage = OrcamentoEtapa::create([
                'tenant_id' => $tenant->id,
                'orcamento_id' => $orcamento->id,
                'created_by_id' => $userId,
                'ordem' => $row['item'],
                'descricao' => $row['descricao'],
                'quantidade' => '1.000000',
                'valor_nao_desonerado' => 0,
                'valor_desonerado' => 0,
                'meta' => [
                    'created_from' => 'budget_csv_import',
                    'original_total' => $this->parseDecimal($row['valor_total']),
                ],
            ]);

            $stagesByOrder[$row['item']] = $stage;
        }

        $items = 0;
        $skipped = count($rows) - $stageRows->count();
        $ordersByStage = [];
        $bdiMultiplier = 1 + ((float) $orcamento->bdi_percentual / 100);

        foreach ($rows as $row) {
            if ($this->isImportedOrcamentoStage($row)) {
                continue;
            }

            $parentOrder = $this->parentEtapaOrder($row['item']);
            $stage = $parentOrder ? ($stagesByOrder[$parentOrder] ?? null) : null;

            if (! $stage) {
                continue;
            }

            $quantity = $this->parseDecimal($row['quantidade']) ?? '0.000000';
            $unitValue = $this->parseDecimal($row['valor_unitario']) ?? '0.000000';
            $valueWithBdi = $this->parseDecimal($row['valor_com_bdi']);

            if ($valueWithBdi === null) {
                $valueWithBdi = $this->storeMoney(
                    $this->calculateMoney((float) $unitValue * $bdiMultiplier, 'truncate_2')
                );
            }

            $totalValue = $this->parseDecimal($row['valor_total']);

            if ($totalValue === null) {
                $totalValue = $this->storeMoney(
                    $this->calculateMoney((float) $quantity * (float) $valueWithBdi, 'truncate_2')
                );
            }

            $suggestedOrder = (int) $this->lastEtapaOrderSegment($row['item']);
            $nextOrder = ($ordersByStage[$stage->id] ?? 0) + 1;
            $order = $suggestedOrder > 0 ? $suggestedOrder : $nextOrder;

            while (OrcamentoItem::query()
                ->where('orcamento_etapa_id', $stage->id)
                ->where('ordem', $order)
                ->exists()) {
                $order++;
            }

            $ordersByStage[$stage->id] = max($nextOrder, $order);

            OrcamentoItem::create([
                'tenant_id' => $tenant->id,
                'orcamento_id' => $orcamento->id,
                'orcamento_etapa_id' => $stage->id,
                'created_by_id' => $userId,
                'item_type' => 'importado',
                'ordem' => $order,
                'codigo' => $row['codigo'] !== '' ? $row['codigo'] : $row['item'],
                'banco' => $row['banco'] !== '' ? mb_strtoupper($row['banco']) : null,
                'descricao' => $row['descricao'],
                'unidade' => $row['unidade'] !== '' ? $row['unidade'] : null,
                'quantidade' => $quantity,
                'valor_unitario_nao_desonerado' => $unitValue,
                'valor_unitario_desonerado' => $unitValue,
                'valor_com_bdi_nao_desonerado' => $valueWithBdi,
                'valor_com_bdi_desonerado' => $valueWithBdi,
                'valor_total_nao_desonerado' => $totalValue,
                'valor_total_desonerado' => $totalValue,
                'aplicar_bdi' => true,
                'meta' => [
                    'created_from' => 'budget_csv_import',
                    'source_item' => $row['item'],
                    'base_label' => $row['banco'] !== '' ? mb_strtoupper($row['banco']) : null,
                    'imported_value_with_bdi' => $valueWithBdi,
                    'imported_total' => $totalValue,
                ],
            ]);

            $items++;
            $skipped--;
        }

        return [
            'stages' => count($stagesByOrder),
            'items' => $items,
            'skipped' => max(0, $skipped),
        ];
    }

    private function isImportedOrcamentoStage(array $row): bool
    {
        return trim((string) ($row['codigo'] ?? '')) === ''
            && trim((string) ($row['banco'] ?? '')) === ''
            && trim((string) ($row['unidade'] ?? '')) === '';
    }

    private function restoreImportedOrcamentoStageTotals(Tenant $tenant, Orcamento $orcamento): void
    {
        $stages = OrcamentoEtapa::query()
            ->where('tenant_id', $tenant->id)
            ->where('orcamento_id', $orcamento->id)
            ->get();

        foreach ($stages as $stage) {
            $originalTotal = ($stage->meta ?? [])['original_total'] ?? null;

            if ($originalTotal === null) {
                continue;
            }

            $stage->forceFill([
                'valor_nao_desonerado' => $originalTotal,
                'valor_desonerado' => $originalTotal,
            ])->save();
        }

        $rootTotal = $stages
            ->filter(fn (OrcamentoEtapa $stage): bool => $this->etapaOrderDepth($stage->ordem) === 1)
            ->sum(function (OrcamentoEtapa $stage): float {
                $originalTotal = ($stage->meta ?? [])['original_total'] ?? null;

                return (float) ($originalTotal ?? $stage->valor_desonerado);
            });

        $orcamento->forceFill([
            'valor_nao_desonerado' => $this->storeMoney($rootTotal),
            'valor_desonerado' => $this->storeMoney($rootTotal),
        ])->save();
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

    private function orcamentoFormOptions(Tenant $tenant): array
    {
        $clienteTipoId = TipoEmpresa::query()
            ->where('nome', 'cliente')
            ->value('id');

        return [
            'nextCode' => $this->nextOrcamentoCode($tenant),
            'clients' => Empresa::query()
                ->where('tenant_id', $tenant->id)
                ->when($clienteTipoId, fn (Builder $query): Builder => $query->where('tipo_empresa_id', $clienteTipoId))
                ->orderBy('nome')
                ->get(['id', 'nome', 'sigla'])
                ->map(fn (Empresa $empresa): array => [
                    'id' => $empresa->id,
                    'nome' => $empresa->nome,
                    'sigla' => $empresa->sigla,
                ])
                ->values()
                ->all(),
            'categories' => collect(self::ORCAMENTO_CATEGORIES)
                ->map(fn (string $category): array => ['value' => $category, 'label' => $category])
                ->values()
                ->all(),
            'roundingMethods' => [
                ['value' => 'round_all_2', 'label' => 'Arredondar tudo em 2 casas decimais'],
                ['value' => 'round_compositions_2', 'label' => 'Arredondar em 2 casas decimais incluindo as composicoes auxiliares'],
                ['value' => 'round_and_truncate_unit', 'label' => 'Arredondar em 2 casas decimais e truncar os precos unitarios'],
                ['value' => 'truncate_all_2', 'label' => 'Truncar tudo em 2 casas decimais', 'badge' => 'Padrao TCU'],
                ['value' => 'none', 'label' => 'Nao arredondar'],
            ],
            'bdiTypes' => [
                ['value' => 'unit_price', 'label' => 'Incidir sobre o preco unitario da composicao', 'badge' => 'TCU recomenda'],
                ['value' => 'total_budget', 'label' => 'Incidir sobre o preco final do orcamento'],
            ],
            'encargosOptions' => [
                ['value' => 'desonerado', 'label' => 'Desonerado'],
                ['value' => 'nao_desonerado', 'label' => 'Nao desonerado'],
            ],
            'licitacaoTipos' => [
                ['value' => 'Concorrencia', 'label' => 'Concorrencia'],
                ['value' => 'Tomada de precos', 'label' => 'Tomada de precos'],
                ['value' => 'Pregao', 'label' => 'Pregao'],
                ['value' => 'Dispensa', 'label' => 'Dispensa'],
                ['value' => 'Inexigibilidade', 'label' => 'Inexigibilidade'],
                ['value' => 'Outro', 'label' => 'Outro'],
            ],
            'baseReferences' => $this->compositionBaseReferences($tenant),
        ];
    }

    private function nextOrcamentoCode(Tenant $tenant): string
    {
        $next = Orcamento::query()
            ->where('tenant_id', $tenant->id)
            ->withTrashed()
            ->count() + 1;

        do {
            $code = str_pad((string) $next, 8, '0', STR_PAD_LEFT);
            $exists = Orcamento::query()
                ->where('tenant_id', $tenant->id)
                ->withTrashed()
                ->where('codigo', $code)
                ->exists();
            $next++;
        } while ($exists);

        return $code;
    }

    private function authorizeOrcamentoEtapa(Request $request, Tenant $tenant, Orcamento $orcamento, OrcamentoEtapa $etapa): void
    {
        abort_unless((int) $orcamento->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $etapa->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $etapa->orcamento_id === (int) $orcamento->id, 404);
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);
    }

    private function renumberOrcamentoEtapas(Tenant $tenant, Orcamento $orcamento): void
    {
        // Hierarchical budget codes are user-defined, so they must not be renumbered.
    }

    private function authorizeOrcamentoItem(Request $request, Tenant $tenant, Orcamento $orcamento, OrcamentoItem $item): void
    {
        abort_unless((int) $orcamento->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $item->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $item->orcamento_id === (int) $orcamento->id, 404);
        abort_unless($this->canManageTenantInsumos($request, $tenant), 403);
    }

    private function renumberOrcamentoItems(Tenant $tenant, OrcamentoEtapa $etapa): void
    {
        // Item numbers are part of the analytical structure and remain stable.
    }

    private function normalizeEtapaOrder(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $segments = explode('.', $value);

        foreach ($segments as $index => $segment) {
            $segment = ltrim($segment, '0');
            $segment = $segment === '' ? '0' : $segment;

            if ((int) $segment <= 0) {
                throw ValidationException::withMessages([
                    'ordem' => 'Informe uma numeracao hierarquica valida. Ex: 3, 3.2 ou 3.2.1.',
                ]);
            }

            $segments[$index] = $segment;
        }

        return implode('.', $segments);
    }

    private function nextRootEtapaOrder(Tenant $tenant, Orcamento $orcamento): string
    {
        $maxRoot = OrcamentoEtapa::query()
            ->where('tenant_id', $tenant->id)
            ->where('orcamento_id', $orcamento->id)
            ->get(['ordem'])
            ->map(fn (OrcamentoEtapa $etapa): string => (string) $etapa->ordem)
            ->filter(fn (string $ordem): bool => $this->etapaOrderDepth($ordem) === 1)
            ->map(fn (string $ordem): int => (int) $ordem)
            ->max();

        return (string) (((int) $maxRoot) + 1);
    }

    private function nextChildEtapaOrder(Tenant $tenant, Orcamento $orcamento, OrcamentoEtapa $parent): string
    {
        $next = $this->nextDirectChildSequence($tenant, $orcamento, $parent);

        return ((string) $parent->ordem).'.'.$next;
    }

    private function nextChildItemOrder(Tenant $tenant, Orcamento $orcamento, OrcamentoEtapa $parent): int
    {
        return $this->nextDirectChildSequence($tenant, $orcamento, $parent);
    }

    private function nextDirectChildSequence(Tenant $tenant, Orcamento $orcamento, OrcamentoEtapa $parent): int
    {
        $parentOrder = (string) $parent->ordem;
        $itemMax = (int) OrcamentoItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('orcamento_id', $orcamento->id)
            ->where('orcamento_etapa_id', $parent->id)
            ->max('ordem');

        $childEtapaMax = OrcamentoEtapa::query()
            ->where('tenant_id', $tenant->id)
            ->where('orcamento_id', $orcamento->id)
            ->where('ordem', 'like', $parentOrder.'.%')
            ->get(['ordem'])
            ->map(fn (OrcamentoEtapa $etapa): string => (string) $etapa->ordem)
            ->filter(fn (string $ordem): bool => $this->isDirectChildEtapaOrder($ordem, $parentOrder))
            ->map(fn (string $ordem): int => (int) $this->lastEtapaOrderSegment($ordem))
            ->max();

        return max($itemMax, (int) $childEtapaMax) + 1;
    }

    private function assertEtapaOrderCanBeUsed(
        Tenant $tenant,
        Orcamento $orcamento,
        string $ordem,
        ?OrcamentoEtapa $ignoreEtapa = null,
        ?string $oldPrefix = null,
    ): void {
        $query = OrcamentoEtapa::query()
            ->where('tenant_id', $tenant->id)
            ->where('orcamento_id', $orcamento->id)
            ->where(function (Builder $query) use ($ordem): void {
                $query->where('ordem', $ordem)
                    ->orWhere('ordem', 'like', $ordem.'.%');
            });

        if ($ignoreEtapa) {
            $query->whereKeyNot($ignoreEtapa->id);
        }

        if ($oldPrefix) {
            $query->where('ordem', 'not like', $oldPrefix.'.%');
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'ordem' => 'Ja existe uma etapa usando esta numeracao.',
            ]);
        }
    }

    private function assertEtapaParentExists(
        Tenant $tenant,
        Orcamento $orcamento,
        string $ordem,
        ?OrcamentoEtapa $ignoreEtapa = null,
        ?string $oldPrefix = null,
    ): void {
        $parentOrder = $this->parentEtapaOrder($ordem);

        if ($parentOrder === null) {
            return;
        }

        $query = OrcamentoEtapa::query()
            ->where('tenant_id', $tenant->id)
            ->where('orcamento_id', $orcamento->id)
            ->where('ordem', $parentOrder);

        if ($ignoreEtapa) {
            $query->whereKeyNot($ignoreEtapa->id);
        }

        if (! $query->exists()) {
            throw ValidationException::withMessages([
                'ordem' => 'Crie a etapa pai antes de criar esta subetapa.',
            ]);
        }
    }

    private function assertEtapaOrderDoesNotConflictWithItem(
        Tenant $tenant,
        Orcamento $orcamento,
        string $ordem,
        ?OrcamentoEtapa $ignoreEtapa = null,
        ?string $oldPrefix = null,
    ): void {
        $parentOrder = $this->parentEtapaOrder($ordem);

        if ($parentOrder === null) {
            return;
        }

        $parent = OrcamentoEtapa::query()
            ->where('tenant_id', $tenant->id)
            ->where('orcamento_id', $orcamento->id)
            ->where('ordem', $parentOrder)
            ->first();

        if (! $parent) {
            return;
        }

        $suffix = (int) $this->lastEtapaOrderSegment($ordem);
        $conflictsWithItem = OrcamentoItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('orcamento_id', $orcamento->id)
            ->where('orcamento_etapa_id', $parent->id)
            ->where('ordem', $suffix)
            ->exists();

        if ($conflictsWithItem) {
            throw ValidationException::withMessages([
                'ordem' => 'Ja existe um item usando esta numeracao dentro da etapa pai.',
            ]);
        }
    }

    private function assertOrcamentoItemOrderCanBeUsed(
        Tenant $tenant,
        Orcamento $orcamento,
        OrcamentoEtapa $etapa,
        int $ordem,
        ?OrcamentoItem $ignoreItem = null,
    ): void {
        $itemQuery = OrcamentoItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('orcamento_id', $orcamento->id)
            ->where('orcamento_etapa_id', $etapa->id)
            ->where('ordem', $ordem);

        if ($ignoreItem) {
            $itemQuery->whereKeyNot($ignoreItem->id);
        }

        if ($itemQuery->exists()) {
            throw ValidationException::withMessages([
                'ordem' => 'Ja existe um item usando esta numeracao dentro da etapa.',
            ]);
        }

        $childEtapaOrder = ((string) $etapa->ordem).'.'.$ordem;
        $childEtapaExists = OrcamentoEtapa::query()
            ->where('tenant_id', $tenant->id)
            ->where('orcamento_id', $orcamento->id)
            ->where('ordem', $childEtapaOrder)
            ->exists();

        if ($childEtapaExists) {
            throw ValidationException::withMessages([
                'ordem' => 'Ja existe uma subetapa usando esta numeracao.',
            ]);
        }
    }

    private function updateDescendantEtapaPrefixes(Tenant $tenant, Orcamento $orcamento, string $oldPrefix, string $newPrefix): void
    {
        OrcamentoEtapa::query()
            ->where('tenant_id', $tenant->id)
            ->where('orcamento_id', $orcamento->id)
            ->where('ordem', 'like', $oldPrefix.'.%')
            ->get()
            ->each(function (OrcamentoEtapa $descendant) use ($oldPrefix, $newPrefix): void {
                $suffix = substr((string) $descendant->ordem, strlen($oldPrefix));
                $descendant->forceFill(['ordem' => $newPrefix.$suffix])->save();
            });
    }

    private function sortLoadedOrcamentoEtapas(Orcamento $orcamento): void
    {
        if (! $orcamento->relationLoaded('etapas')) {
            return;
        }

        $orcamento->setRelation(
            'etapas',
            $orcamento->etapas
                ->sortBy(fn (OrcamentoEtapa $etapa): string => $this->hierarchySortKey($etapa->ordem))
                ->values()
        );
    }

    private function hierarchySortKey(mixed $ordem): string
    {
        return collect(explode('.', (string) $ordem))
            ->map(fn (string $segment): string => str_pad((string) ((int) $segment), 8, '0', STR_PAD_LEFT))
            ->implode('.');
    }

    private function isDirectChildEtapaOrder(mixed $candidateOrder, mixed $parentOrder): bool
    {
        $candidate = (string) $candidateOrder;
        $parent = (string) $parentOrder;

        return str_starts_with($candidate, $parent.'.')
            && $this->etapaOrderDepth($candidate) === $this->etapaOrderDepth($parent) + 1;
    }

    private function etapaOrderDepth(mixed $ordem): int
    {
        return substr_count((string) $ordem, '.') + 1;
    }

    private function parentEtapaOrder(string $ordem): ?string
    {
        if (! str_contains($ordem, '.')) {
            return null;
        }

        return Str::beforeLast($ordem, '.');
    }

    private function lastEtapaOrderSegment(string $ordem): string
    {
        return Str::afterLast($ordem, '.');
    }

    private function recalculateOrcamentoItemTotals(OrcamentoItem $item, Orcamento $orcamento): void
    {
        $quantity = (float) $item->quantidade;
        $meta = $item->meta ?? [];
        $bdiPercentual = $meta['bdi_percentual'] ?? $orcamento->bdi_percentual;
        $bdiMultiplier = 1 + ((float) $bdiPercentual / 100);
        $unitNaoDesonerado = (float) $item->valor_unitario_nao_desonerado;
        $unitDesonerado = (float) $item->valor_unitario_desonerado;
        $withBdiNaoDesonerado = $this->calculateMoney($unitNaoDesonerado * $bdiMultiplier, 'truncate_2');
        $withBdiDesonerado = $this->calculateMoney($unitDesonerado * $bdiMultiplier, 'truncate_2');

        $item->forceFill([
            'valor_com_bdi_nao_desonerado' => $this->storeMoney($withBdiNaoDesonerado),
            'valor_com_bdi_desonerado' => $this->storeMoney($withBdiDesonerado),
            'valor_total_nao_desonerado' => $this->storeMoney($this->calculateMoney($withBdiNaoDesonerado * $quantity, 'truncate_2')),
            'valor_total_desonerado' => $this->storeMoney($this->calculateMoney($withBdiDesonerado * $quantity, 'truncate_2')),
        ])->save();
    }

    private function recalculateOrcamentoTotals(Tenant $tenant, Orcamento $orcamento): void
    {
        $etapas = OrcamentoEtapa::query()
            ->where('tenant_id', $tenant->id)
            ->where('orcamento_id', $orcamento->id)
            ->with('itens')
            ->get();

        $memo = [];
        $calculateEtapaTotals = function (OrcamentoEtapa $etapa) use (&$calculateEtapaTotals, &$memo, $etapas): array {
            if (isset($memo[$etapa->id])) {
                return $memo[$etapa->id];
            }

            $naoDesonerado = (float) $etapa->itens->sum('valor_total_nao_desonerado');
            $desonerado = (float) $etapa->itens->sum('valor_total_desonerado');

            $children = $etapas->filter(fn (OrcamentoEtapa $candidate): bool => $this->isDirectChildEtapaOrder(
                $candidate->ordem,
                $etapa->ordem
            ));

            foreach ($children as $child) {
                $childTotals = $calculateEtapaTotals($child);
                $naoDesonerado += $childTotals['nao_desonerado'];
                $desonerado += $childTotals['desonerado'];
            }

            return $memo[$etapa->id] = [
                'nao_desonerado' => $this->calculateMoney($naoDesonerado, 'truncate_2'),
                'desonerado' => $this->calculateMoney($desonerado, 'truncate_2'),
            ];
        };

        foreach ($etapas as $etapa) {
            $totals = $calculateEtapaTotals($etapa);

            $etapa->forceFill([
                'valor_nao_desonerado' => $this->storeMoney($totals['nao_desonerado']),
                'valor_desonerado' => $this->storeMoney($totals['desonerado']),
            ])->save();
        }

        $rootEtapas = $etapas->filter(fn (OrcamentoEtapa $etapa): bool => $this->etapaOrderDepth($etapa->ordem) === 1);
        $budgetEtapas = $rootEtapas->isNotEmpty() ? $rootEtapas : $etapas;

        $orcamento->forceFill([
            'valor_nao_desonerado' => $this->storeMoney($budgetEtapas->sum(fn (OrcamentoEtapa $etapa): float => (float) ($memo[$etapa->id]['nao_desonerado'] ?? 0))),
            'valor_desonerado' => $this->storeMoney($budgetEtapas->sum(fn (OrcamentoEtapa $etapa): float => (float) ($memo[$etapa->id]['desonerado'] ?? 0))),
        ])->save();
    }

    private function orcamentoSelectedReferences(Orcamento $orcamento): array
    {
        return collect($orcamento->base_references ?? [])
            ->map(function (array $reference): array {
                return [
                    'base' => mb_strtoupper(trim((string) ($reference['nome'] ?? ''))),
                    'uf' => isset($reference['uf']) ? mb_strtoupper(trim((string) $reference['uf'])) : null,
                    'date' => isset($reference['data']) ? $this->parseReferenceDateOrNull((string) $reference['data']) : null,
                ];
            })
            ->filter(fn (array $reference): bool => in_array($reference['base'], ['SINAPI', 'SICRO3'], true))
            ->values()
            ->all();
    }

    private function composicaoAllowedForOrcamento(Tenant $tenant, Orcamento $orcamento, OrcamentoComposicao $composicao): bool
    {
        if (! $composicao->is_global && (int) $composicao->tenant_id === (int) $tenant->id) {
            return true;
        }

        $references = $this->orcamentoSelectedReferences($orcamento);

        return $references !== [] && $this->composicaoMatchesAnyReference($composicao, $references);
    }

    private function insumoAllowedForOrcamento(Tenant $tenant, Orcamento $orcamento, OrcamentoInsumo $insumo): bool
    {
        if ((int) $insumo->tenant_id === (int) $tenant->id) {
            return true;
        }

        $references = $this->orcamentoSelectedReferences($orcamento);

        return $references !== [] && $this->insumoMatchesAnyReference($insumo, $references);
    }

    private function insumoMatchesAnyReference(OrcamentoInsumo $insumo, array $references): bool
    {
        foreach ($references as $reference) {
            if ($insumo->banco !== $reference['base']) {
                continue;
            }

            if ($reference['uf'] && $insumo->uf !== $reference['uf']) {
                continue;
            }

            if ($reference['date'] && $insumo->data_referencia?->toDateString() !== $reference['date']->toDateString()) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function orcamentoComposicaoPrices(Tenant $tenant, OrcamentoComposicao $composicao): array
    {
        $summary = $this->composicaoListPriceSummaries($tenant, collect([$composicao]))[$composicao->id]
            ?? $this->fastComposicaoPriceSummary($composicao);
        $method = $this->calculationMethodForComposicao($composicao);

        return [
            'nao_desonerado' => $this->storeMoney($this->calculateMoney($summary['effective_preco_onerado'] ?? 0, $method)),
            'desonerado' => $this->storeMoney($this->calculateMoney($summary['effective_preco_desonerado'] ?? 0, $method)),
        ];
    }

    private function orcamentoInsumoPrices(OrcamentoInsumo $insumo): array
    {
        return [
            'nao_desonerado' => $this->storeMoney($this->calculateMoney($insumo->preco_nao_desonerado ?? 0)),
            'desonerado' => $this->storeMoney($this->calculateMoney($insumo->preco_desonerado ?? 0)),
        ];
    }

    private function orcamentoComposicaoBaseLabel(OrcamentoComposicao $composicao): string
    {
        if (! $composicao->is_global) {
            return 'Base propria';
        }

        $date = $this->firstReferenceDateForComposicao($composicao);
        $dateLabel = $date ? CarbonImmutable::parse($date)->format('m/Y') : null;

        return trim(implode(' - ', array_filter([
            $composicao->modelo,
            $composicao->uf,
            $dateLabel,
        ])));
    }

    private function serializeOrcamentoComposicaoOption(OrcamentoComposicao $composicao, ?array $summary = null): array
    {
        $method = $this->calculationMethodForComposicao($composicao);
        $summary ??= $this->fastComposicaoPriceSummary($composicao);
        $date = $this->firstReferenceDateForComposicao($composicao);

        return [
            'id' => $composicao->id,
            'base' => $composicao->is_global ? $composicao->modelo : 'PROPRIA',
            'base_label' => $this->orcamentoComposicaoBaseLabel($composicao),
            'codigo' => $composicao->codigo,
            'descricao' => $composicao->descricao,
            'tipo' => $composicao->tipo_composicao,
            'unidade' => $composicao->unidade,
            'data' => $date ? CarbonImmutable::parse($date)->format('m/Y') : null,
            'preco_unitario_nao_desonerado' => $this->calculateMoney($summary['effective_preco_onerado'] ?? 0, $method),
            'preco_unitario_desonerado' => $this->calculateMoney($summary['effective_preco_desonerado'] ?? 0, $method),
        ];
    }

    private function serializeOrcamentoInsumoOption(OrcamentoInsumo $insumo): array
    {
        $precoNaoDesonerado = $this->calculateMoney($insumo->preco_nao_desonerado ?? 0);
        $precoDesonerado = $this->calculateMoney($insumo->preco_desonerado ?? 0);

        return [
            'id' => $insumo->id,
            'base' => $insumo->banco,
            'base_label' => trim(implode(' - ', array_filter([
                $insumo->banco,
                $insumo->uf,
                $insumo->data_referencia?->format('m/Y'),
            ]))),
            'codigo' => $insumo->codigo_insumo,
            'descricao' => $insumo->descricao,
            'tipo' => $insumo->classificacao ?: $this->typeLabel($insumo->tipo),
            'unidade' => $insumo->unidade,
            'data' => $insumo->data_referencia?->format('m/Y'),
            'uf' => $insumo->uf,
            'preco_unitario_nao_desonerado' => $precoNaoDesonerado,
            'preco_unitario_desonerado' => $precoDesonerado,
            'has_zero_price' => $precoNaoDesonerado <= 0 || $precoDesonerado <= 0,
        ];
    }

    private function buildOrcamentoSinteticoSpreadsheet(Tenant $tenant, Orcamento $orcamento): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Deming')
            ->setTitle('Orcamento Sintetico - '.$orcamento->codigo)
            ->setSubject('Relatorio sintetico de orcamento');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sintetico');
        $sheet->setShowGridlines(false);
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        foreach ([
            'A' => 12,
            'B' => 16,
            'C' => 15,
            'D' => 72,
            'E' => 12,
            'F' => 13,
            'G' => 16,
            'H' => 18,
            'I' => 16,
            'J' => 12,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->mergeCells('A1:C2');
        $sheet->setCellValue('A1', 'Deming');
        $sheet->getStyle('A1:C2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 22, 'color' => ['rgb' => '0B5FFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EFF6FF']],
        ]);

        $sheet->setCellValue('D1', 'Obra');
        $sheet->setCellValue('D2', trim($orcamento->codigo.' - '.$orcamento->descricao));
        $sheet->mergeCells('E1:F1');
        $sheet->mergeCells('E2:F2');
        $sheet->setCellValue('E1', 'Bancos');
        $sheet->setCellValue('E2', $this->orcamentoBaseReferencesReportText($orcamento));
        $sheet->mergeCells('G1:H1');
        $sheet->mergeCells('G2:H2');
        $sheet->setCellValue('G1', 'B.D.I.');
        $sheet->setCellValue('G2', ((float) $orcamento->bdi_percentual / 100));
        $sheet->mergeCells('I1:J1');
        $sheet->mergeCells('I2:J2');
        $sheet->setCellValue('I1', 'Cliente');
        $sheet->setCellValue('I2', $orcamento->clienteEmpresa?->nome ?? 'Sem cliente');

        $sheet->getStyle('D1:J1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0B3F75']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('D2:J2')->applyFromArray([
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        ]);
        $sheet->getStyle('G2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
        $sheet->getRowDimension(2)->setRowHeight(48);

        $sheet->mergeCells('A3:J3');
        $sheet->setCellValue('A3', 'Orcamento Sintetico com Formulas');
        $sheet->getStyle('A3:J3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '0F172A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
        ]);

        $sheet->mergeCells('H4:I4');
        $sheet->setCellValue('H4', 'Valor Final do Orcamento');
        $sheet->setCellValue('J4', 0);
        $sheet->mergeCells('H5:I5');
        $sheet->setCellValue('H5', 'BDI');
        $sheet->setCellValue('J5', ((float) $orcamento->bdi_percentual / 100));
        $sheet->getStyle('H4:J5')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        ]);
        $sheet->getStyle('J4')->getNumberFormat()->setFormatCode('"R$" #,##0.00');
        $sheet->getStyle('J5')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);

        $headers = ['Item', 'Codigo', 'Banco', 'Descricao', 'Und', 'Quant.', 'Valor Unit', 'Valor Unit com BDI', 'Total', 'Peso (%)'];

        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'6', $header);
        }

        $sheet->getStyle('A6:J6')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '111827']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(6)->setRowHeight(24);

        $row = 7;
        $stageRows = [];
        $useNaoDesonerado = $orcamento->encargos_sociais === 'nao_desonerado';

        foreach ($orcamento->etapas as $etapa) {
            $stageRow = $row;
            $stageRows[] = $stageRow;

            $sheet->setCellValueExplicit("A{$stageRow}", (string) $etapa->ordem, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$stageRow}", (string) $etapa->descricao, DataType::TYPE_STRING);

            $row++;
            $firstItemRow = $row;

            foreach ($etapa->itens as $item) {
                $unit = $useNaoDesonerado
                    ? (float) $item->valor_unitario_nao_desonerado
                    : (float) $item->valor_unitario_desonerado;
                $meta = $item->meta ?? [];
                $bdiPercent = (float) ($meta['bdi_percentual'] ?? $orcamento->bdi_percentual);
                $bdiFormulaValue = number_format($bdiPercent / 100, 8, '.', '');
                $bdiReference = (bool) ($meta['bdi_diferenciado'] ?? false) ? $bdiFormulaValue : '$J$5';
                $itemFormula = "=TRUNC(TRUNC(G{$row}*{$bdiReference},2)+G{$row},2)";

                $sheet->setCellValueExplicit("A{$row}", $etapa->ordem.'.'.$item->ordem, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("B{$row}", (string) $item->codigo, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("C{$row}", (string) $item->banco, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("D{$row}", (string) $item->descricao, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("E{$row}", (string) $item->unidade, DataType::TYPE_STRING);
                $sheet->setCellValue("F{$row}", (float) $item->quantidade);
                $sheet->setCellValue("G{$row}", $unit);
                $sheet->setCellValue("H{$row}", $itemFormula);
                $sheet->setCellValue("I{$row}", "=TRUNC(F{$row}*H{$row},2)");
                $sheet->setCellValue("J{$row}", "=IF(\$J\$4=0,0,I{$row}/\$J\$4)");

                $this->styleOrcamentoSinteticoDataRow($sheet, $row, $item->item_type === 'insumo' ? 'FEF3C7' : 'DCFCE7');
                $row++;
            }

            $lastItemRow = $row - 1;
            $stageTotalFormula = $lastItemRow >= $firstItemRow ? "SUM(I{$firstItemRow}:I{$lastItemRow})" : '0';
            $sheet->setCellValue("I{$stageRow}", "=TRUNC({$stageTotalFormula},2)");
            $sheet->setCellValue("J{$stageRow}", "=IF(\$J\$4=0,0,I{$stageRow}/\$J\$4)");
            $this->styleOrcamentoSinteticoDataRow($sheet, $stageRow, 'DBEAFE', true);
        }

        $lastDataRow = max(6, $row - 1);
        $totalFormula = count($stageRows) > 0
            ? '=SUM('.collect($stageRows)->map(fn (int $stageRow): string => "I{$stageRow}")->implode(',').')'
            : '=0';
        $sheet->setCellValue('J4', $totalFormula);

        $summaryRow = $lastDataRow + 2;
        $sheet->mergeCells("F{$summaryRow}:H{$summaryRow}");
        $sheet->setCellValue("F{$summaryRow}", 'Total sem BDI');
        $sheet->setCellValue("J{$summaryRow}", "=SUMPRODUCT(F7:F{$lastDataRow},G7:G{$lastDataRow})");
        $sheet->mergeCells('F'.($summaryRow + 1).':H'.($summaryRow + 1));
        $sheet->setCellValue('F'.($summaryRow + 1), 'Total do BDI');
        $sheet->setCellValue('J'.($summaryRow + 1), "=J4-J{$summaryRow}");
        $sheet->mergeCells('F'.($summaryRow + 3).':H'.($summaryRow + 3));
        $sheet->setCellValue('F'.($summaryRow + 3), 'TOTAL');
        $sheet->setCellValue('J'.($summaryRow + 3), '=J4');
        $sheet->getStyle("F{$summaryRow}:J".($summaryRow + 3))->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        ]);
        $sheet->getStyle('J'.($summaryRow + 3))->getFont()->setSize(16);

        $sheet->getStyle("F7:F{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("G7:I{$lastDataRow}")->getNumberFormat()->setFormatCode('"R$" #,##0.00');
        $sheet->getStyle("J7:J{$lastDataRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
        $sheet->getStyle("J{$summaryRow}:J".($summaryRow + 3))->getNumberFormat()->setFormatCode('"R$" #,##0.00');
        $sheet->getStyle("A6:J{$lastDataRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
        ]);
        $sheet->getStyle("D7:D{$lastDataRow}")->getAlignment()->setWrapText(true);
        $sheet->freezePane('A7');
        $sheet->setAutoFilter("A6:J{$lastDataRow}");

        return $spreadsheet;
    }

    private function buildOrcamentoResumoSpreadsheet(Tenant $tenant, Orcamento $orcamento): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Deming')
            ->setTitle('Resumo do Orcamento - '.$orcamento->codigo)
            ->setSubject('Relatorio resumo de orcamento');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Resumo do Orcamento');
        $sheet->setShowGridlines(false);
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        foreach ([
            'A' => 9,
            'B' => 8,
            'C' => 8,
            'D' => 32,
            'E' => 24,
            'F' => 22,
            'G' => 14,
            'H' => 13,
            'I' => 18,
            'J' => 12,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->mergeCells('A1:C2');
        $sheet->setCellValue('A1', 'Deming');
        $sheet->getStyle('A1:C2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 22, 'color' => ['rgb' => '0B5FFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EFF6FF']],
        ]);

        $sheet->setCellValue('D1', 'Obra');
        $sheet->setCellValue('D2', trim($orcamento->codigo.' - '.$orcamento->descricao));
        $sheet->setCellValue('E1', 'Bancos');
        $sheet->setCellValue('E2', $this->orcamentoBaseReferencesReportText($orcamento));
        $sheet->mergeCells('F1:H1');
        $sheet->mergeCells('F2:H2');
        $sheet->setCellValue('F1', 'B.D.I.');
        $sheet->setCellValue('F2', ((float) $orcamento->bdi_percentual / 100));
        $sheet->mergeCells('I1:J1');
        $sheet->mergeCells('I2:J2');
        $sheet->setCellValue('I1', 'Encargos Sociais');
        $sheet->setCellValue('I2', $this->orcamentoEncargosSociaisReportText($orcamento));

        $sheet->getStyle('D1:J1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0B3F75']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('D2:J2')->applyFromArray([
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        ]);
        $sheet->getStyle('F2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
        $sheet->getRowDimension(2)->setRowHeight(48);

        $sheet->mergeCells('A3:J3');
        $sheet->setCellValue('A3', 'Planilha Orçamentária Resumida');
        $sheet->getStyle('A3:J3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '0F172A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
        ]);

        $sheet->setCellValue('A4', 'Item');
        $sheet->mergeCells('D4:F4');
        $sheet->setCellValue('D4', 'Descrição');
        $sheet->setCellValue('H4', 'Quant.');
        $sheet->setCellValue('I4', 'Total');
        $sheet->setCellValue('J4', 'Peso (%)');
        $sheet->getStyle('A4:J4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '111827']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $row = 5;
        $useNaoDesonerado = $orcamento->encargos_sociais === 'nao_desonerado';
        $totalSemBdi = 0.0;
        $totalGeralRow = $orcamento->etapas->count() + 8;

        foreach ($orcamento->etapas as $etapa) {
            foreach ($etapa->itens as $item) {
                $unit = $useNaoDesonerado
                    ? (float) $item->valor_unitario_nao_desonerado
                    : (float) $item->valor_unitario_desonerado;
                $totalSemBdi += ((float) $item->quantidade) * $unit;
            }
        }

        $totalGeral = $useNaoDesonerado
            ? (float) $orcamento->valor_nao_desonerado
            : (float) $orcamento->valor_desonerado;
        $totalGeral = $totalGeral > 0
            ? $totalGeral
            : (float) $orcamento->etapas->sum(fn (OrcamentoEtapa $etapa): float => $useNaoDesonerado
                ? (float) $etapa->valor_nao_desonerado
                : (float) $etapa->valor_desonerado);

        foreach ($orcamento->etapas as $etapa) {
            $stageTotal = $this->orcamentoEtapaTotalWithBdi($etapa, $useNaoDesonerado);

            $sheet->setCellValueExplicit("A{$row}", (string) $etapa->ordem, DataType::TYPE_STRING);
            $sheet->mergeCells("D{$row}:F{$row}");
            $sheet->setCellValueExplicit("D{$row}", (string) $etapa->descricao, DataType::TYPE_STRING);
            $sheet->setCellValue("I{$row}", $stageTotal);
            $sheet->setCellValue("J{$row}", "=IF(\$H\${$totalGeralRow}=0,0,I{$row}/\$H\${$totalGeralRow})");

            $sheet->getStyle("A{$row}:J{$row}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '0F172A']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(24);
            $row++;
        }

        $lastDataRow = max(4, $row - 1);
        $summaryRow = $lastDataRow + 2;
        $totalRow = $summaryRow + 2;

        if ($lastDataRow >= 5) {
            $sheet->getStyle("H5:I{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("I5:I{$lastDataRow}")->getNumberFormat()->setFormatCode('"R$" #,##0.00');
            $sheet->getStyle("J5:J{$lastDataRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
            $sheet->setAutoFilter("A4:J{$lastDataRow}");
        }

        $sheet->mergeCells("A{$summaryRow}:C{$summaryRow}");
        $sheet->mergeCells("F{$summaryRow}:G{$summaryRow}");
        $sheet->setCellValue("F{$summaryRow}", 'Total sem BDI');
        $sheet->setCellValue("H{$summaryRow}", $this->calculateMoney($totalSemBdi, 'truncate_2'));

        $sheet->mergeCells('A'.($summaryRow + 1).':C'.($summaryRow + 1));
        $sheet->mergeCells('F'.($summaryRow + 1).':G'.($summaryRow + 1));
        $sheet->setCellValue('F'.($summaryRow + 1), 'Total do BDI');
        $sheet->setCellValue('H'.($summaryRow + 1), '=H'.($summaryRow + 2)."-H{$summaryRow}");

        $sheet->mergeCells("A{$totalRow}:C{$totalRow}");
        $sheet->mergeCells("F{$totalRow}:G{$totalRow}");
        $sheet->setCellValue("F{$totalRow}", 'Total Geral');
        $sheet->setCellValue("H{$totalRow}", $lastDataRow >= 5 ? "=SUM(I5:I{$lastDataRow})" : 0);

        $sheet->getStyle("F{$summaryRow}:H{$totalRow}")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        ]);
        $sheet->getStyle("H{$summaryRow}:H{$totalRow}")->getNumberFormat()->setFormatCode('"R$" #,##0.00');
        $sheet->getStyle("F{$totalRow}:H{$totalRow}")->getFont()->setSize(12);

        $signatureRow = $totalRow + 2;
        $sheet->mergeCells("A{$signatureRow}:J{$signatureRow}");
        $sheet->setCellValue("A{$signatureRow}", "_______________________________________________________________\nResponsável técnico");
        $sheet->getStyle("A{$signatureRow}:J{$signatureRow}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension($signatureRow)->setRowHeight(48);

        $sheet->freezePane('A5');

        return $spreadsheet;
    }

    private function styleOrcamentoSinteticoDataRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, string $fillColor, bool $bold = false): void
    {
        $sheet->getStyle("A{$row}:J{$row}")->applyFromArray([
            'font' => ['bold' => $bold, 'color' => ['rgb' => '0F172A']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fillColor]],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
        ]);
        $sheet->getRowDimension($row)->setRowHeight($bold ? 24 : 38);
    }

    private function orcamentoBaseReferencesReportText(Orcamento $orcamento): string
    {
        return collect($orcamento->base_references ?? [])
            ->map(function (array $reference): string {
                $parts = [
                    trim((string) ($reference['nome'] ?? '')),
                    trim((string) ($reference['data'] ?? '')),
                    trim((string) ($reference['localidade'] ?? $reference['uf'] ?? '')),
                ];

                return collect($parts)
                    ->filter(fn (string $part): bool => $part !== '')
                    ->implode(' - ');
            })
            ->filter()
            ->implode("\n");
    }

    private function orcamentoEncargosSociaisReportText(Orcamento $orcamento): string
    {
        return $orcamento->encargos_sociais === 'nao_desonerado'
            ? 'Não desonerado'
            : 'Desonerado';
    }

    private function serializeOrcamento(Orcamento $orcamento): array
    {
        return [
            'id' => $orcamento->id,
            'codigo' => $orcamento->codigo,
            'descricao' => $orcamento->descricao,
            'categoria' => $orcamento->categoria,
            'cliente' => $orcamento->clienteEmpresa?->nome,
            'prazo_entrega' => $orcamento->prazo_entrega_at?->format('d/m/Y H:i'),
            'status' => $orcamento->status,
            'status_label' => $this->orcamentoStatusLabel($orcamento->status),
            'is_closed' => $orcamento->status === 'closed',
            'closed_at' => $orcamento->closed_at?->format('d/m/Y H:i'),
            'permitir_insumos_preco_zerado' => (bool) $orcamento->permitir_insumos_preco_zerado,
            'is_licitacao' => (bool) $orcamento->is_licitacao,
            'arredondamento_label' => $this->orcamentoRoundingLabel($orcamento->arredondamento),
            'encargos_sociais' => $orcamento->encargos_sociais,
            'encargos_sociais_label' => $orcamento->encargos_sociais === 'nao_desonerado' ? 'Nao desonerado' : 'Desonerado',
            'encargos_horista' => $orcamento->encargos_horista,
            'encargos_mensalista' => $orcamento->encargos_mensalista,
            'bdi_percentual' => $orcamento->bdi_percentual,
            'base_references' => $orcamento->base_references ?? [],
            'valor_nao_desonerado' => $orcamento->valor_nao_desonerado,
            'valor_desonerado' => $orcamento->valor_desonerado,
            'created_at' => $orcamento->created_at?->format('d/m/Y H:i'),
        ];
    }

    private function orcamentoCopySources(Tenant $tenant, Orcamento $currentOrcamento): array
    {
        return Orcamento::query()
            ->with(['clienteEmpresa:id,nome'])
            ->withCount(['etapas', 'itens'])
            ->where('tenant_id', $tenant->id)
            ->whereKeyNot($currentOrcamento->id)
            ->latest('updated_at')
            ->get()
            ->map(fn (Orcamento $orcamento): array => [
                ...$this->serializeOrcamento($orcamento),
                'etapas_count' => $orcamento->etapas_count,
                'itens_count' => $orcamento->itens_count,
            ])
            ->values()
            ->all();
    }

    private function pushSourceEtapaAncestors(mixed &$requiredEtapaIds, OrcamentoEtapa $etapa, mixed $sourceEtapasByOrder): void
    {
        $parentOrder = $this->parentEtapaOrder((string) $etapa->ordem);

        while ($parentOrder !== null) {
            $parent = $sourceEtapasByOrder->get($parentOrder);

            if (! $parent) {
                return;
            }

            $requiredEtapaIds->push((int) $parent->id);
            $parentOrder = $this->parentEtapaOrder((string) $parent->ordem);
        }
    }

    private function serializeOrcamentoEtapa(OrcamentoEtapa $etapa, ?Orcamento $orcamento = null): array
    {
        $orcamento ??= $etapa->relationLoaded('orcamento') ? $etapa->orcamento : null;

        return [
            'id' => $etapa->id,
            'ordem' => $etapa->ordem,
            'item' => (string) $etapa->ordem,
            'descricao' => $etapa->descricao,
            'quantidade' => null,
            'valor_nao_desonerado' => $etapa->valor_nao_desonerado,
            'valor_desonerado' => $etapa->valor_desonerado,
            'valor_total' => $this->orcamentoEtapaTotalWithBdi($etapa, ($orcamento?->encargos_sociais ?? 'desonerado') === 'nao_desonerado'),
            'is_hidden' => (bool) (($etapa->meta ?? [])['hidden'] ?? false),
            'itens' => $etapa->relationLoaded('itens')
                ? $etapa->itens
                    ->map(fn (OrcamentoItem $item): array => $this->serializeOrcamentoItem($item, $etapa, $orcamento))
                    ->values()
                    ->all()
                : [],
            'created_at' => $etapa->created_at?->format('d/m/Y H:i'),
        ];
    }

    private function serializeOrcamentoItem(OrcamentoItem $item, OrcamentoEtapa $etapa, ?Orcamento $orcamento = null): array
    {
        $useNaoDesonerado = ($orcamento?->encargos_sociais ?? 'desonerado') === 'nao_desonerado';
        $unit = $useNaoDesonerado ? $item->valor_unitario_nao_desonerado : $item->valor_unitario_desonerado;
        $unitWithBdi = $useNaoDesonerado ? $item->valor_com_bdi_nao_desonerado : $item->valor_com_bdi_desonerado;
        $total = $this->orcamentoItemTotalWithBdi($item, $useNaoDesonerado);
        $meta = $item->meta ?? [];

        return [
            'id' => $item->id,
            'item_type' => $item->item_type,
            'ordem' => $item->ordem,
            'item' => $etapa->ordem.'.'.$item->ordem,
            'codigo' => $item->codigo,
            'banco' => $item->banco,
            'descricao' => $item->descricao,
            'unidade' => $item->unidade,
            'quantidade' => $item->quantidade,
            'valor_unitario' => $unit,
            'valor_com_bdi' => $unitWithBdi,
            'valor_total' => $total,
            'valor_unitario_nao_desonerado' => $item->valor_unitario_nao_desonerado,
            'valor_unitario_desonerado' => $item->valor_unitario_desonerado,
            'valor_total_nao_desonerado' => $item->valor_total_nao_desonerado,
            'valor_total_desonerado' => $item->valor_total_desonerado,
            'aplicar_bdi' => (bool) $item->aplicar_bdi,
            'bdi_percentual' => $meta['bdi_percentual'] ?? $orcamento?->bdi_percentual,
            'bdi_diferenciado' => (bool) ($meta['bdi_diferenciado'] ?? false),
            'base_label' => $meta['base_label'] ?? $item->banco,
            'created_at' => $item->created_at?->format('d/m/Y H:i'),
        ];
    }

    private function orcamentoEtapaTotalWithBdi(OrcamentoEtapa $etapa, bool $useNaoDesonerado): float
    {
        return $useNaoDesonerado
            ? (float) $etapa->valor_nao_desonerado
            : (float) $etapa->valor_desonerado;
    }

    private function orcamentoItemTotalWithBdi(OrcamentoItem $item, bool $useNaoDesonerado): float
    {
        $unitWithBdi = $useNaoDesonerado
            ? (float) $item->valor_com_bdi_nao_desonerado
            : (float) $item->valor_com_bdi_desonerado;

        if ($unitWithBdi <= 0) {
            $unitWithBdi = $useNaoDesonerado
                ? (float) $item->valor_unitario_nao_desonerado
                : (float) $item->valor_unitario_desonerado;
        }

        return $this->calculateMoney($unitWithBdi * (float) $item->quantidade, 'truncate_2');
    }

    private function orcamentoStatusLabel(?string $status): string
    {
        return match ($status) {
            'closed' => 'Finalizado',
            'approved' => 'Aprovado',
            'sent' => 'Enviado',
            'archived' => 'Arquivado',
            default => 'Em elaboracao',
        };
    }

    private function ensureOrcamentoIsOpen(Orcamento $orcamento): void
    {
        if ($orcamento->status === 'closed') {
            throw ValidationException::withMessages([
                'orcamento' => 'Este orcamento esta finalizado e nao pode mais ser alterado.',
            ]);
        }
    }

    private function orcamentoRoundingLabel(?string $method): string
    {
        return match ($method) {
            'round_all_2' => 'Arredondar tudo em 2 casas',
            'round_compositions_2' => 'Arredondar composicoes auxiliares',
            'round_and_truncate_unit' => 'Arredondar e truncar unitarios',
            'truncate_all_2' => 'Truncar tudo em 2 casas',
            'none' => 'Nao arredondar',
            default => 'Nao informado',
        };
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

<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\RdaApontamento;
use App\Models\RdoConfiguracao;
use App\Models\RdoDiario;
use App\Models\RdoEquipamentoCadastro;
use App\Models\RdoMaoObraCadastro;
use App\Models\RdoSubcontratadaCadastro;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RdaController extends Controller
{
    public function index(Request $request, Tenant $tenant): Response
    {
        [$contracts, $selectedContractId, $month, $configurations, $obras, $selectedObraId, $configuration] = $this->context($request, $tenant);
        [$validDays, $existingRdos, $apontamentos] = $this->calendarData($tenant, $configuration, $selectedContractId, $month);

        return Inertia::render('Tenant/Rda/Index', [
            'contracts' => $contracts,
            'obras' => $obras,
            'filters' => [
                'contract_id' => $selectedContractId ?: null,
                'obra_id' => $selectedObraId ?: null,
                'month' => $month->format('Y-m'),
            ],
            'configuration' => $this->configurationPayload($configuration),
            'validDays' => $validDays,
            'existingRdos' => $existingRdos,
            'apontamentos' => $apontamentos,
            'summary' => [
                'month_label' => ucfirst($month->translatedFormat('F/Y')),
                'days_in_month' => $month->daysInMonth,
                'planned_flow' => [
                    'Herdar contrato, frente, prazo e geração da parametrização do RDO',
                    'Criar apontamentos rápidos em campo',
                    'Fotos e comentários por frente de serviço',
                    'Funcionamento preparado para mobile/offline',
                    'Consolidação posterior no RDO oficial',
                ],
            ],
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'contract_id' => ['required', 'integer'],
            'obra_id' => ['required', 'integer'],
            'reference_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $configuration = $this->configurationFor($tenant, (int) $validated['contract_id'], (int) $validated['obra_id']);
        abort_unless($configuration, 422, 'Parametrize o RDO antes de preencher o RDA.');
        abort_unless($this->isValidRdaDate($configuration, $validated['reference_date']), 422, 'A data selecionada está fora da regra de geração do RDO.');

        $rdo = $this->rdoFor($tenant, $configuration, $validated['reference_date']);
        abort_unless($rdo, 422, 'Crie o RDO desta data antes de preencher o RDA.');
        abort_unless($this->rdoFillWindowOpen($rdo), 422, 'O prazo deste RDO venceu. Reabra o RDO no calendário para preencher o RDA.');

        $apontamento = RdaApontamento::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'contract_id' => (int) $validated['contract_id'],
                'obra_id' => (int) $validated['obra_id'],
                'reference_date' => $validated['reference_date'],
            ],
            [
                'rdo_configuracao_id' => $configuration->id,
                'rdo_diario_id' => $rdo->id,
                'created_by_id' => $request->user()?->id,
                'updated_by_id' => $request->user()?->id,
                'status' => 'rascunho',
                'dados' => $this->emptyData(),
            ]
        );

        if (! $apontamento->rdo_diario_id && $rdo) {
            $apontamento->update(['rdo_diario_id' => $rdo->id]);
        }

        return redirect()->route('tenant.diario-obra.rda.show', [$tenant->slug, $apontamento->id]);
    }

    public function show(Tenant $tenant, RdaApontamento $rda): Response
    {
        abort_unless((int) $rda->tenant_id === (int) $tenant->id, 404);

        $rda->loadMissing(['contract:id,code,name', 'obra:id,codigo,nome', 'rdo:id,code,status', 'configuracao.obras:id,codigo,nome']);

        return Inertia::render('Tenant/Rda/Show', [
            'rda' => $this->apontamentoPayload($tenant, $rda),
            'catalogs' => $this->catalogs($tenant),
        ]);
    }

    public function update(Request $request, Tenant $tenant, RdaApontamento $rda): RedirectResponse
    {
        abort_unless((int) $rda->tenant_id === (int) $tenant->id, 404);
        abort_if($rda->status === 'publicado', 422, 'RDA publicado não pode ser alterado.');
        $rda->loadMissing('rdo.configuracao');
        abort_unless($rda->rdo && $this->rdoFillWindowOpen($rda->rdo), 422, 'O prazo deste RDO venceu. Reabra o RDO no calendário para preencher o RDA.');

        $this->saveCurrentData($request, $tenant, $rda);

        return back()->with('success', 'RDA salvo como rascunho.');
    }

    public function publish(Request $request, Tenant $tenant, RdaApontamento $rda): RedirectResponse
    {
        abort_unless((int) $rda->tenant_id === (int) $tenant->id, 404);
        abort_if($rda->status === 'publicado', 422, 'RDA já publicado.');
        $rda->loadMissing('rdo.configuracao');
        abort_unless($rda->rdo && $this->rdoFillWindowOpen($rda->rdo), 422, 'O prazo deste RDO venceu. Reabra o RDO no calendário para publicar o RDA.');

        $this->saveCurrentData($request, $tenant, $rda);

        $rda->update([
            'status' => 'publicado',
            'published_at' => now(),
            'updated_by_id' => $request->user()?->id,
        ]);

        return back()->with('success', 'RDA publicado com sucesso.');
    }

    private function saveCurrentData(Request $request, Tenant $tenant, RdaApontamento $rda): void
    {
        $validated = $request->validate([
            'dados' => ['nullable', 'array'],
            'dados.atividades' => ['nullable', 'array'],
            'dados.atividades.*.titulo' => ['nullable', 'string', 'max:180'],
            'dados.atividades.*.ocorrencia' => ['nullable', 'string', 'max:2000'],
            'dados.clima' => ['nullable', 'array'],
            'dados.clima.manha' => ['nullable'],
            'dados.clima.tarde' => ['nullable'],
            'dados.clima.noite' => ['nullable'],
            'dados.clima.precipitacao_manha_mm' => ['nullable', 'numeric', 'min:0'],
            'dados.clima.precipitacao_tarde_mm' => ['nullable', 'numeric', 'min:0'],
            'dados.clima.precipitacao_noite_mm' => ['nullable', 'numeric', 'min:0'],
            'dados.clima.dia_impraticavel' => ['nullable', 'boolean'],
            'dados.mao_obra' => ['nullable', 'array'],
            'dados.mao_obra.*.cadastro_id' => ['nullable', 'integer'],
            'dados.mao_obra.*.descricao' => ['nullable', 'string', 'max:255'],
            'dados.mao_obra.*.quantidade' => ['nullable', 'numeric', 'min:0'],
            'dados.equipamentos' => ['nullable', 'array'],
            'dados.equipamentos.*.cadastro_id' => ['nullable', 'integer'],
            'dados.equipamentos.*.descricao' => ['nullable', 'string', 'max:255'],
            'dados.equipamentos.*.quantidade' => ['nullable', 'numeric', 'min:0'],
            'dados.subcontratadas' => ['nullable', 'array'],
            'dados.subcontratadas.*.cadastro_id' => ['nullable', 'integer'],
            'dados.subcontratadas.*.descricao' => ['nullable', 'string', 'max:255'],
            'dados.subcontratadas.*.quantidade' => ['nullable', 'numeric', 'min:0'],
            'dados.fotos' => ['nullable', 'array'],
            'fotos' => ['nullable', 'array', 'max:20'],
            'fotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ]);

        $dados = $this->normalizeData($validated['dados'] ?? []);
        $dados['fotos'] = $this->preparePhotoData(
            $tenant,
            $rda,
            $dados['fotos'] ?? $this->emptyPhotoData(),
            $request->file('fotos', []),
        );

        $rda->update([
            'dados' => $dados,
            'updated_by_id' => $request->user()?->id,
        ]);
    }

    private function context(Request $request, Tenant $tenant): array
    {
        $filters = $request->validate([
            'contract_id' => ['nullable', 'integer'],
            'obra_id' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $contracts = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->whereHas('rdoConfiguracoes')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        if ($contracts->isEmpty()) {
            $contracts = Contract::query()
                ->where('tenant_id', $tenant->id)
                ->orderBy('code')
                ->get(['id', 'code', 'name']);
        }

        $selectedContractId = (int) ($filters['contract_id'] ?? $contracts->first()?->id ?? 0);
        $month = CarbonImmutable::createFromFormat('Y-m', $filters['month'] ?? now()->format('Y-m'))->startOfMonth();

        $configurations = RdoConfiguracao::query()
            ->with(['obras:id,contract_id,codigo,nome', 'obra:id,contract_id,codigo,nome'])
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $selectedContractId)
            ->where('active', true)
            ->orderByDesc('id')
            ->get();

        $obras = $configurations
            ->flatMap(fn (RdoConfiguracao $configuration) => $configuration->obras->isNotEmpty()
                ? $configuration->obras
                : collect([$configuration->obra])->filter())
            ->unique('id')
            ->sortBy([['codigo', 'asc'], ['nome', 'asc']])
            ->values();

        $selectedObraId = (int) ($filters['obra_id'] ?? $obras->first()?->id ?? 0);
        $configuration = $configurations->first(fn (RdoConfiguracao $configuration) => $this->configurationContainsObra($configuration, $selectedObraId));

        return [$contracts, $selectedContractId, $month, $configurations, $obras, $selectedObraId, $configuration];
    }

    private function calendarData(Tenant $tenant, ?RdoConfiguracao $configuration, int $contractId, CarbonImmutable $month): array
    {
        $validDays = [];
        $existingRdos = collect();
        $apontamentos = collect();

        if (! $configuration) {
            return [$validDays, $existingRdos, $apontamentos];
        }

        for ($day = $month->startOfMonth(); $day->lte($month->endOfMonth()); $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            if ($this->isValidRdaDate($configuration, $date)) {
                $validDays[] = $date;
            }
        }

        $existingRdos = RdoDiario::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contractId)
            ->where('rdo_configuracao_id', $configuration->id)
            ->whereBetween('reference_date', [$month->startOfMonth(), $month->endOfMonth()])
            ->orderBy('reference_date')
            ->get(['id', 'rdo_configuracao_id', 'code', 'reference_date', 'status', 'reopened_until'])
            ->map(fn (RdoDiario $rdo) => [
                'id' => $rdo->id,
                'code' => $rdo->code,
                'reference_date' => $rdo->reference_date?->format('Y-m-d'),
                'status' => $rdo->status,
                'can_fill' => in_array($rdo->status, ['rascunho', 'devolvido_construtora', 'pendente_comprovacao'], true)
                    && $this->rdoFillWindowOpen($rdo),
                'url' => route('tenant.diario-obra.rdo.show', [$tenant->slug, $rdo->id]),
            ]);

        $apontamentos = RdaApontamento::query()
            ->with(['obra:id,codigo,nome', 'rdo:id,rdo_configuracao_id,reference_date,status,reopened_until'])
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contractId)
            ->where('rdo_configuracao_id', $configuration->id)
            ->whereBetween('reference_date', [$month->startOfMonth(), $month->endOfMonth()])
            ->orderBy('reference_date')
            ->get(['id', 'rdo_diario_id', 'obra_id', 'reference_date', 'status', 'published_at'])
            ->map(fn (RdaApontamento $rda) => [
                'id' => $rda->id,
                'rdo_diario_id' => $rda->rdo_diario_id,
                'obra_id' => $rda->obra_id,
                'obra_label' => $rda->obra ? "{$rda->obra->codigo} - {$rda->obra->nome}" : null,
                'reference_date' => $rda->reference_date?->format('Y-m-d'),
                'status' => $rda->status,
                'status_label' => $rda->status === 'publicado' ? 'Publicado' : 'Rascunho',
                'can_fill' => $rda->status !== 'publicado'
                    && $rda->rdo
                    && $this->rdoFillWindowOpen($rda->rdo),
                'url' => route('tenant.diario-obra.rda.show', [$tenant->slug, $rda->id]),
            ]);

        return [$validDays, $existingRdos, $apontamentos];
    }

    private function configurationFor(Tenant $tenant, int $contractId, int $obraId): ?RdoConfiguracao
    {
        return RdoConfiguracao::query()
            ->with(['obras:id', 'obra:id'])
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contractId)
            ->where('active', true)
            ->orderByDesc('id')
            ->get()
            ->first(fn (RdoConfiguracao $configuration) => $this->configurationContainsObra($configuration, $obraId));
    }

    private function configurationContainsObra(RdoConfiguracao $configuration, int $obraId): bool
    {
        if (! $obraId) {
            return true;
        }

        return $configuration->obras
            ->pluck('id')
            ->push($configuration->obra_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->contains($obraId);
    }

    private function isValidRdaDate(RdoConfiguracao $configuration, string $date): bool
    {
        $day = CarbonImmutable::parse($date);
        $weekdays = collect($configuration->generation_weekdays ?: [1, 2, 3, 4, 5])->map(fn ($weekday) => (int) $weekday);
        $withinStart = ! $configuration->start_date || $date >= $configuration->start_date->format('Y-m-d');
        $withinEnd = ! $configuration->end_date || $date <= $configuration->end_date->format('Y-m-d');

        return $withinStart && $withinEnd && $weekdays->contains($day->dayOfWeek);
    }

    private function rdoFor(Tenant $tenant, RdoConfiguracao $configuration, string $date): ?RdoDiario
    {
        return RdoDiario::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $configuration->contract_id)
            ->where('rdo_configuracao_id', $configuration->id)
            ->whereDate('reference_date', $date)
            ->first();
    }

    private function rdoFillWindowOpen(RdoDiario $rdo): bool
    {
        $rdo->loadMissing('configuracao');
        $timezone = $rdo->configuracao?->timezone ?: config('app.timezone');

        if ($rdo->reopened_until && CarbonImmutable::now($timezone)->lte(CarbonImmutable::parse($rdo->reopened_until))) {
            return true;
        }

        $deadlineDays = max(1, (int) ($rdo->configuracao?->submission_deadline_days ?? 7));
        $deadline = CarbonImmutable::parse($rdo->reference_date)->addDays($deadlineDays)->endOfDay();

        return CarbonImmutable::now($timezone)->lte($deadline);
    }

    private function configurationPayload(?RdoConfiguracao $configuration): ?array
    {
        return $configuration ? [
            'id' => $configuration->id,
            'start_date' => $configuration->start_date?->format('d/m/Y'),
            'end_date' => $configuration->end_date?->format('d/m/Y'),
            'submission_deadline_days' => $configuration->submission_deadline_days,
            'copy_previous_day' => $configuration->copy_previous_day,
            'require_photos' => $configuration->require_photos,
            'generation_weekdays' => $configuration->generation_weekdays,
        ] : null;
    }

    private function apontamentoPayload(Tenant $tenant, RdaApontamento $rda): array
    {
        return [
            'id' => $rda->id,
            'code' => 'RDA-'.$rda->reference_date?->format('Ymd').'-'.$rda->id,
            'reference_date' => $rda->reference_date?->format('Y-m-d'),
            'reference_date_formatted' => $rda->reference_date?->format('d/m/Y'),
            'status' => $rda->status,
            'status_label' => $rda->status === 'publicado' ? 'Publicado' : 'Rascunho',
            'published_at' => $rda->published_at?->format('d/m/Y H:i'),
            'dados' => $rda->dados ?: $this->emptyData(),
            'contract' => $rda->contract ? ['id' => $rda->contract->id, 'label' => "{$rda->contract->code} - {$rda->contract->name}"] : null,
            'obra' => $rda->obra ? ['id' => $rda->obra->id, 'label' => "{$rda->obra->codigo} - {$rda->obra->nome}"] : null,
            'rdo' => $rda->rdo ? [
                'id' => $rda->rdo->id,
                'code' => $rda->rdo->code,
                'url' => route('tenant.diario-obra.rdo.show', [$tenant->slug, $rda->rdo->id]),
            ] : null,
            'calendar_url' => route('tenant.diario-obra.rda.index', [
                'tenant' => $tenant->slug,
                'contract_id' => $rda->contract_id,
                'obra_id' => $rda->obra_id,
                'month' => $rda->reference_date?->format('Y-m'),
            ]),
            'store_url' => route('tenant.diario-obra.rda.store', $tenant->slug),
            'update_url' => route('tenant.diario-obra.rda.update', [$tenant->slug, $rda->id]),
            'publish_url' => route('tenant.diario-obra.rda.publish', [$tenant->slug, $rda->id]),
            'available_obras' => $rda->configuracao?->obras
                ? $rda->configuracao->obras->map(fn ($obra) => ['id' => $obra->id, 'label' => "{$obra->codigo} - {$obra->nome}"])->values()
                : [],
        ];
    }

    private function emptyData(): array
    {
        return [
            'clima' => [
                'manha' => '',
                'tarde' => '',
                'noite' => '',
                'precipitacao_manha_mm' => '',
                'precipitacao_tarde_mm' => '',
                'precipitacao_noite_mm' => '',
                'dia_impraticavel' => false,
            ],
            'atividades' => [],
            'mao_obra' => [],
            'equipamentos' => [],
            'subcontratadas' => [],
            'fotos' => $this->emptyPhotoData(),
        ];
    }

    private function normalizeData(array $data): array
    {
        return [
            'clima' => [
                'manha' => $this->normalizeWeatherSituation(data_get($data, 'clima.manha', '')),
                'tarde' => $this->normalizeWeatherSituation(data_get($data, 'clima.tarde', '')),
                'noite' => $this->normalizeWeatherSituation(data_get($data, 'clima.noite', '')),
                'precipitacao_manha_mm' => data_get($data, 'clima.precipitacao_manha_mm', ''),
                'precipitacao_tarde_mm' => data_get($data, 'clima.precipitacao_tarde_mm', ''),
                'precipitacao_noite_mm' => data_get($data, 'clima.precipitacao_noite_mm', ''),
                'dia_impraticavel' => (bool) data_get($data, 'clima.dia_impraticavel', false),
            ],
            'atividades' => collect($data['atividades'] ?? [])
                ->map(fn ($item) => [
                    'titulo' => trim((string) ($item['titulo'] ?? '')),
                    'ocorrencia' => trim((string) ($item['ocorrencia'] ?? '')),
                ])
                ->filter(fn ($item) => $item['titulo'] !== '' || $item['ocorrencia'] !== '')
                ->values()
                ->all(),
            'mao_obra' => $this->normalizeResourceItems($data['mao_obra'] ?? []),
            'equipamentos' => $this->normalizeResourceItems($data['equipamentos'] ?? []),
            'subcontratadas' => $this->normalizeResourceItems($data['subcontratadas'] ?? []),
            'fotos' => $data['fotos'] ?? $this->emptyPhotoData(),
        ];
    }

    private function normalizeResourceItems(array $items): array
    {
        return collect($items)
            ->map(fn ($item) => [
                'cadastro_id' => isset($item['cadastro_id']) ? (int) $item['cadastro_id'] : null,
                'descricao' => trim((string) ($item['descricao'] ?? '')),
                'quantidade' => (float) ($item['quantidade'] ?? 0),
            ])
            ->filter(fn ($item) => $item['cadastro_id'] || $item['descricao'] !== '' || $item['quantidade'] > 0)
            ->values()
            ->all();
    }

    private function normalizeWeatherSituation(mixed $value): string
    {
        if (is_array($value)) {
            $value = collect($value)->filter()->first();
        }

        return trim((string) ($value ?? ''));
    }

    private function catalogs(Tenant $tenant): array
    {
        return [
            'mao_obra' => RdoMaoObraCadastro::query()
                ->where('tenant_id', $tenant->id)
                ->where('active', true)
                ->orderBy('tipo')
                ->orderBy('descricao')
                ->get(['id', 'descricao', 'tipo', 'unidade'])
                ->map(fn (RdoMaoObraCadastro $item) => [
                    'id' => $item->id,
                    'label' => $item->descricao,
                    'meta' => ucfirst($item->tipo).' · '.$item->unidade,
                ]),
            'equipamentos' => RdoEquipamentoCadastro::query()
                ->where('tenant_id', $tenant->id)
                ->where('active', true)
                ->orderBy('descricao')
                ->get(['id', 'codigo', 'descricao', 'unidade', 'propriedade'])
                ->map(fn (RdoEquipamentoCadastro $item) => [
                    'id' => $item->id,
                    'label' => trim(($item->codigo ? "{$item->codigo} - " : '').$item->descricao),
                    'meta' => ucfirst($item->propriedade).' · '.$item->unidade,
                ]),
            'subcontratadas' => RdoSubcontratadaCadastro::query()
                ->where('tenant_id', $tenant->id)
                ->where('active', true)
                ->orderBy('razao_social')
                ->get(['id', 'razao_social', 'nome_fantasia', 'cnpj'])
                ->map(fn (RdoSubcontratadaCadastro $item) => [
                    'id' => $item->id,
                    'label' => $item->nome_fantasia ?: $item->razao_social,
                    'meta' => $item->cnpj,
                ]),
        ];
    }

    private function emptyPhotoData(): array
    {
        return ['arquivos' => [], 'novas_fotos' => [], 'ordem_fotos' => []];
    }

    /**
     * @param  array<int, UploadedFile>  $uploads
     */
    private function preparePhotoData(Tenant $tenant, RdaApontamento $rda, array $data, array $uploads): array
    {
        $existing = collect($data['arquivos'] ?? [])
            ->filter(fn ($photo) => ! empty($photo['path']))
            ->keyBy('path');

        $newMetadata = array_values($data['novas_fotos'] ?? []);
        foreach ($uploads as $index => $upload) {
            $meta = $newMetadata[$index] ?? [];
            $path = $upload->store("tenant-{$tenant->id}/rda/{$rda->id}/fotos", 'public');
            $existing->put($path, [
                'path' => $path,
                'nome' => $upload->getClientOriginalName(),
                'comment' => $meta['comment'] ?? '',
                'legenda' => $meta['comment'] ?? '',
                'uploaded_at' => now()->toDateTimeString(),
            ]);
        }

        $ordered = [];
        foreach ($data['ordem_fotos'] ?? [] as $key) {
            if (str_starts_with((string) $key, 'existing:')) {
                $path = substr((string) $key, strlen('existing:'));
                if ($existing->has($path)) {
                    $ordered[] = $existing->pull($path);
                }
            }
        }

        $ordered = [...$ordered, ...array_values($existing->all())];

        return [
            'arquivos' => collect($ordered)->values()->map(fn ($photo, $index) => [
                ...$photo,
                'position' => $index + 1,
            ])->all(),
            'novas_fotos' => [],
            'ordem_fotos' => collect($ordered)->map(fn ($photo) => 'existing:'.$photo['path'])->values()->all(),
        ];
    }
}

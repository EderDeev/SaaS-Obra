<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\BoletimMedicao;
use App\Models\Empresa;
use App\Models\FolhaRosto;
use App\Models\FolhaRostoAnalise;
use App\Models\FolhaRostoAnaliseResponsavel;
use App\Models\FolhaRostoFluxoHistorico;
use App\Models\FolhaRostoItem;
use App\Models\Contract;
use App\Models\MedicaoItem;
use App\Models\Obra;
use App\Models\OrdemServico;
use App\Models\OrdemServicoItem;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use App\Models\User;
use App\Notifications\FolhaRostoSubmittedForAnalysisNotification;
use App\Notifications\FolhaRostoFlowChangedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FolhaRostoController extends Controller
{
    private const CONSUMING_STATUSES = ['aberta', 'rascunho', 'retornada', 'analise_fiscal', 'analise_qualidade', 'analise_medicao', 'analisada'];

    private const ANALYSIS_STAGES = [
        'fiscal' => [
            'status' => 'analise_fiscal',
            'title' => 'Análise do fiscal por frente de serviço',
            'label' => 'Fiscal',
        ],
        'qualidade' => [
            'status' => 'analise_qualidade',
            'title' => 'Análise da qualidade por frente de serviço',
            'label' => 'Qualidade',
        ],
        'medicao' => [
            'status' => 'analise_medicao',
            'title' => 'Análise da medição por frente de serviço',
            'label' => 'Medição',
        ],
    ];

    public function index(Request $request, Tenant $tenant): Response
    {
        $boletim = $this->resolveBoletim($request, $tenant);
        $selectedContractId = $boletim?->contract_id
            ?: ($request->integer('contract_id') ?: $tenant->contracts()->orderBy('code')->value('id'));

        $contracts = $tenant->contracts()
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $ordens = OrdemServico::query()
            ->where('tenant_id', $tenant->id)
            ->when($selectedContractId, fn ($query) => $query->where('contract_id', $selectedContractId))
            ->whereIn('status', ['aprovada', 'em_execucao', 'concluida'])
            ->with([
                'contract:id,code,name',
                'obra:id,codigo,nome',
                'creator:id,name',
            ])
            ->withCount([
                'folhasRosto as folhas_rosto_total' => fn ($query) => $query
                    ->when($boletim, fn ($query) => $query->where('boletim_medicao_id', $boletim->id)),
                'folhasRosto as folhas_rosto_abertas' => fn ($query) => $query
                    ->when($boletim, fn ($query) => $query->where('boletim_medicao_id', $boletim->id))
                    ->whereIn('status', self::CONSUMING_STATUSES),
            ])
            ->latest('id')
            ->get()
            ->map(fn (OrdemServico $ordem): array => [
                'id' => $ordem->id,
                'codigo' => $ordem->codigo,
                'titulo' => $ordem->titulo,
                'status' => $ordem->status,
                'custo_previsto' => (float) $ordem->custo_previsto,
                'folhas_rosto_total' => $ordem->folhas_rosto_total,
                'folhas_rosto_abertas' => $ordem->folhas_rosto_abertas,
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
                'solicitante' => $ordem->creator?->name,
            ])
            ->groupBy(fn (array $ordem): string => (string) ($ordem['obra']['id'] ?? 'sem-obra'))
            ->map(fn ($items): array => [
                'obra' => $items->first()['obra'],
                'ordens' => $items->values(),
            ])
            ->values();

        return Inertia::render('Tenant/Medicao/FolhaRosto/Index', [
            'selectedContractId' => $selectedContractId,
            'contracts' => $contracts,
            'grupos' => $ordens,
            'boletim' => $boletim ? $this->serializeBoletim($boletim) : null,
        ]);
    }

    public function show(Request $request, Tenant $tenant, OrdemServico $ordem): Response
    {
        $this->ensureTenantOrdem($tenant, $ordem);
        $boletim = $this->resolveBoletim($request, $tenant);

        if ($boletim) {
            abort_unless((int) $boletim->contract_id === (int) $ordem->contract_id, 404);
        }

        abort_unless(in_array($ordem->status, ['aprovada', 'em_execucao', 'concluida'], true), 404);

        $ordem->load([
            'contract:id,code,name',
            'obra:id,codigo,nome',
            'creator:id,name,email',
            'itens.medicaoItem:id,item,codigo,descricao,unidade,quantidade_prevista,valor_total',
            'itens.folhaRostoItens.folhaRosto:id,status',
            'folhasRosto' => fn ($query) => $query
                ->when($boletim, fn ($query) => $query->where('boletim_medicao_id', $boletim->id))
                ->with([
                    'boletimMedicao:id,codigo,periodo,tipo,status',
                    'construtoraEmpresa:id,nome,sigla',
                    'creator:id,name,email',
                    'fluxoHistoricos' => fn ($query) => $query
                        ->with('user:id,name,email')
                        ->where('acao', 'retornar_construtora')
                        ->latest('id'),
                    'itens.ordemServicoItem.medicaoItem:id,item,codigo,descricao,unidade',
                ])
                ->latest('id'),
        ]);

        $boletinsAbertos = BoletimMedicao::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $ordem->contract_id)
            ->where('status', 'aberto_lancamento')
            ->orderByDesc('periodo')
            ->orderByDesc('id')
            ->get(['id', 'codigo', 'periodo', 'tipo', 'status'])
            ->map(fn (BoletimMedicao $item): array => $this->serializeBoletim($item))
            ->values();

        $construtoras = $this->construtoraOptions($tenant, (int) $ordem->contract_id);

        return Inertia::render('Tenant/Medicao/FolhaRosto/Show', [
            'boletim' => $boletim ? $this->serializeBoletim($boletim) : null,
            'boletinsAbertos' => $boletinsAbertos,
            'construtoras' => $construtoras,
            'ordem' => [
                'id' => $ordem->id,
                'codigo' => $ordem->codigo,
                'titulo' => $ordem->titulo,
                'descricao' => $ordem->descricao,
                'status' => $ordem->status,
                'contract' => $ordem->contract ? [
                    'code' => $ordem->contract->code,
                    'name' => $ordem->contract->name,
                ] : null,
                'obra' => $ordem->obra ? [
                    'codigo' => $ordem->obra->codigo,
                    'nome' => $ordem->obra->nome,
                ] : null,
                'solicitante' => $ordem->creator ? [
                    'name' => $ordem->creator->name,
                    'email' => $ordem->creator->email,
                ] : null,
                'can_create' => in_array($ordem->status, ['aprovada', 'em_execucao'], true)
                    && (! $boletim || $boletim->status === 'aberto_lancamento'),
                'itens' => $ordem->itens->map(fn (OrdemServicoItem $item): array => $this->serializeItem($item))->values(),
                'folhas_rosto' => $ordem->folhasRosto->map(fn (FolhaRosto $folha): array => [
                    'id' => $folha->id,
                    'codigo' => $folha->codigo,
                    'comentario' => $folha->comentario,
                    'status' => $folha->status,
                    'boletim' => $folha->boletimMedicao
                        ? $this->serializeBoletim($folha->boletimMedicao)
                        : null,
                    'construtora' => $folha->construtoraEmpresa ? [
                        'id' => $folha->construtoraEmpresa->id,
                        'nome' => $folha->construtoraEmpresa->nome,
                        'sigla' => $folha->construtoraEmpresa->sigla,
                    ] : null,
                    'created_at' => $folha->created_at?->format('d/m/Y H:i'),
                    'dias_retornada' => $this->returnedAgeInDays($folha),
                    'motivo_retorno' => $this->latestReturnReason($folha),
                    'responsavel_retorno' => $this->latestReturnResponsible($folha),
                    'creator' => $folha->creator?->name,
                    'valor_total' => (float) $folha->itens->sum('valor_pleiteado'),
                    'memoria_calculo' => $folha->memoria_calculo_path ? [
                        'nome' => $folha->memoria_calculo_nome_original,
                        'size' => $folha->memoria_calculo_size,
                        'download_url' => route('tenant.medicao.folha-rosto.memoria.download', [
                            $tenant,
                            $folha,
                        ]),
                    ] : null,
                    'itens' => $folha->itens->map(fn ($item): array => [
                        'id' => $item->id,
                        'ordem_servico_item_id' => $item->ordem_servico_item_id,
                        'item' => $item->ordemServicoItem?->medicaoItem?->item,
                        'codigo' => $item->ordemServicoItem?->medicaoItem?->codigo,
                        'descricao' => $item->ordemServicoItem?->medicaoItem?->descricao,
                        'unidade' => $item->ordemServicoItem?->medicaoItem?->unidade,
                        'quantidade_pleiteada' => (float) $item->quantidade_pleiteada,
                        'valor_pleiteado' => (float) $item->valor_pleiteado,
                        'precisa_analise_topografica' => (bool) $item->precisa_analise_topografica,
                        'precisa_analise_qualidade' => (bool) $item->precisa_analise_qualidade,
                    ])->values(),
                ])->values(),
            ],
        ]);
    }

    public function store(Request $request, Tenant $tenant, OrdemServico $ordem): RedirectResponse
    {
        $this->ensureTenantOrdem($tenant, $ordem);

        if (! in_array($ordem->status, ['aprovada', 'em_execucao'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Somente OS aprovadas ou em execução podem receber uma Folha de Rosto.',
            ]);
        }

        $validated = $request->validate([
            'comentario' => ['required', 'string', 'min:5'],
            'boletim_medicao_id' => ['required', 'integer'],
            'construtora_empresa_id' => ['required', 'integer'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.ordem_servico_item_id' => ['required', 'integer'],
            'itens.*.quantidade_pleiteada' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'itens.*.precisa_analise_topografica' => ['nullable', 'boolean'],
            'itens.*.precisa_analise_qualidade' => ['nullable', 'boolean'],
            'memoria_calculo' => ['required', 'file', 'mimes:zip', 'max:30720'],
        ]);

        $requested = collect($validated['itens'])
            ->keyBy(fn (array $item): int => (int) $item['ordem_servico_item_id']);
        $boletim = $this->resolveBoletim($request, $tenant);

        if (! $boletim) {
            throw ValidationException::withMessages([
                'boletim_medicao_id' => 'Selecione um BM aberto para lançamento.',
            ]);
        }

        abort_unless((int) $boletim->contract_id === (int) $ordem->contract_id, 404);

        if ($boletim->status !== 'aberto_lancamento') {
            throw ValidationException::withMessages([
                'boletim_medicao_id' => 'O envio de Folhas de Rosto está pausado para este boletim.',
            ]);
        }

        $construtora = $this->resolveConstrutora($tenant, (int) $ordem->contract_id, (int) $validated['construtora_empresa_id']);

        $storedPath = null;

        try {
            DB::transaction(function () use ($request, $tenant, $ordem, $validated, $requested, $boletim, $construtora, &$storedPath): void {
            OrdemServico::query()->whereKey($ordem->id)->lockForUpdate()->firstOrFail();

            $items = OrdemServicoItem::query()
                ->where('ordem_servico_id', $ordem->id)
                ->whereIn('id', $requested->keys())
                ->with(['folhaRostoItens.folhaRosto'])
                ->get();

            if ($items->count() !== $requested->count()) {
                throw ValidationException::withMessages([
                    'itens' => 'Um ou mais itens não pertencem à OS selecionada.',
                ]);
            }

            foreach ($items as $item) {
                $requestedQuantity = (float) $requested[$item->id]['quantidade_pleiteada'];
                $consumed = $this->consumedQuantity($item);
                $available = max(0, (float) $item->quantidade_solicitada - $consumed);

                if ($requestedQuantity > $available + 0.000001) {
                    throw ValidationException::withMessages([
                        "itens.{$item->id}" => "A quantidade pleiteada do item {$item->medicaoItem?->item} supera o saldo disponível.",
                    ]);
                }
            }

            $next = FolhaRosto::withTrashed()
                ->where('ordem_servico_id', $ordem->id)
                ->max('sequencial') + 1;

            $folha = FolhaRosto::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $ordem->contract_id,
                'obra_id' => $ordem->obra_id,
                'ordem_servico_id' => $ordem->id,
                'boletim_medicao_id' => $boletim?->id,
                'construtora_empresa_id' => $construtora->id,
                'created_by_id' => $request->user()?->id,
                'codigo' => "{$ordem->codigo}-FR-".str_pad((string) $next, 3, '0', STR_PAD_LEFT),
                'sequencial' => $next,
                'comentario' => $validated['comentario'],
                'status' => 'rascunho',
            ]);

            $file = $request->file('memoria_calculo');
            $storedPath = $file->store(
                "tenant-{$tenant->id}/medicao/folhas-rosto/fr-{$folha->id}/memoria-calculo",
                'public'
            );

            $folha->forceFill([
                'memoria_calculo_path' => $storedPath,
                'memoria_calculo_nome_original' => $file->getClientOriginalName(),
                'memoria_calculo_mime_type' => $file->getClientMimeType(),
                'memoria_calculo_size' => $file->getSize() ?: 0,
            ])->save();

            foreach ($items as $item) {
                $quantity = (float) $requested[$item->id]['quantidade_pleiteada'];
                $baseQuantity = (float) $item->quantidade_solicitada;
                $value = $baseQuantity > 0
                    ? ((float) $item->valor_previsto / $baseQuantity) * $quantity
                    : 0;

                $folha->itens()->create([
                    'ordem_servico_item_id' => $item->id,
                    'quantidade_pleiteada' => $quantity,
                    'valor_pleiteado' => round($value, 2),
                    'precisa_analise_topografica' => (bool) ($requested[$item->id]['precisa_analise_topografica'] ?? false),
                    'precisa_analise_qualidade' => (bool) ($requested[$item->id]['precisa_analise_qualidade'] ?? false),
                ]);
            }
            });
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::disk('public')->delete($storedPath);
            }

            throw $exception;
        }

        return back()->with('success', 'Folha de Rosto criada com sucesso.');
    }

    public function update(Request $request, Tenant $tenant, FolhaRosto $folha): RedirectResponse
    {
        $this->ensureTenantFolha($tenant, $folha);

        if (! in_array($folha->status, ['rascunho', 'retornada'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Somente Folhas de Rosto em rascunho ou retornadas podem ser editadas.',
            ]);
        }

        $validated = $request->validate([
            'comentario' => ['required', 'string', 'min:5'],
            'boletim_medicao_id' => ['required', 'integer'],
            'construtora_empresa_id' => ['required', 'integer'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.ordem_servico_item_id' => ['required', 'integer'],
            'itens.*.quantidade_pleiteada' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'itens.*.precisa_analise_topografica' => ['nullable', 'boolean'],
            'itens.*.precisa_analise_qualidade' => ['nullable', 'boolean'],
            'memoria_calculo' => ['nullable', 'file', 'mimes:zip', 'max:30720'],
        ]);

        $ordem = OrdemServico::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($folha->ordem_servico_id);
        $boletim = $this->resolveBoletim($request, $tenant);

        if (! $boletim || (int) $boletim->contract_id !== (int) $ordem->contract_id) {
            throw ValidationException::withMessages([
                'boletim_medicao_id' => 'Selecione um BM válido para esta OS.',
            ]);
        }

        if ($boletim->status !== 'aberto_lancamento' && (int) $boletim->id !== (int) $folha->boletim_medicao_id) {
            throw ValidationException::withMessages([
                'boletim_medicao_id' => 'O BM selecionado não está aberto para lançamento.',
            ]);
        }

        $construtora = $this->resolveConstrutora(
            $tenant,
            (int) $ordem->contract_id,
            (int) $validated['construtora_empresa_id']
        );
        $requested = collect($validated['itens'])
            ->keyBy(fn (array $item): int => (int) $item['ordem_servico_item_id']);
        $newPath = null;
        $oldPath = $folha->memoria_calculo_path;

        try {
            DB::transaction(function () use (
                $request,
                $tenant,
                $folha,
                $ordem,
                $boletim,
                $construtora,
                $validated,
                $requested,
                &$newPath
            ): void {
                $lockedFolha = FolhaRosto::query()->whereKey($folha->id)->lockForUpdate()->firstOrFail();
                $existingItems = $lockedFolha->itens()->get()->keyBy('ordem_servico_item_id');
                $items = OrdemServicoItem::query()
                    ->where('ordem_servico_id', $ordem->id)
                    ->whereIn('id', $requested->keys())
                    ->with(['folhaRostoItens.folhaRosto', 'medicaoItem:id,item'])
                    ->get();

                if ($items->count() !== $requested->count()) {
                    throw ValidationException::withMessages([
                        'itens' => 'Um ou mais itens não pertencem à OS selecionada.',
                    ]);
                }

                foreach ($items as $item) {
                    $quantity = (float) $requested[$item->id]['quantidade_pleiteada'];
                    $currentQuantity = (float) ($existingItems->get($item->id)?->quantidade_pleiteada ?? 0);
                    $available = max(
                        0,
                        (float) $item->quantidade_solicitada - $this->consumedQuantity($item) + $currentQuantity
                    );

                    if ($quantity > $available + 0.000001) {
                        throw ValidationException::withMessages([
                            "itens.{$item->id}" => "A quantidade pleiteada do item {$item->medicaoItem?->item} supera o saldo disponível.",
                        ]);
                    }
                }

                $lockedFolha->forceFill([
                    'boletim_medicao_id' => $boletim->id,
                    'construtora_empresa_id' => $construtora->id,
                    'comentario' => $validated['comentario'],
                ]);

                if ($request->hasFile('memoria_calculo')) {
                    $file = $request->file('memoria_calculo');
                    $newPath = $file->store(
                        "tenant-{$tenant->id}/medicao/folhas-rosto/fr-{$lockedFolha->id}/memoria-calculo",
                        'public'
                    );
                    $lockedFolha->forceFill([
                        'memoria_calculo_path' => $newPath,
                        'memoria_calculo_nome_original' => $file->getClientOriginalName(),
                        'memoria_calculo_mime_type' => $file->getClientMimeType(),
                        'memoria_calculo_size' => $file->getSize() ?: 0,
                    ]);
                }

                $lockedFolha->save();
                $lockedFolha->itens()
                    ->whereNotIn('ordem_servico_item_id', $requested->keys())
                    ->delete();

                foreach ($items as $item) {
                    $quantity = (float) $requested[$item->id]['quantidade_pleiteada'];
                    $baseQuantity = (float) $item->quantidade_solicitada;
                    $value = $baseQuantity > 0
                        ? ((float) $item->valor_previsto / $baseQuantity) * $quantity
                        : 0;

                    $lockedFolha->itens()->updateOrCreate(
                        ['ordem_servico_item_id' => $item->id],
                        [
                            'quantidade_pleiteada' => $quantity,
                            'valor_pleiteado' => round($value, 2),
                            'precisa_analise_topografica' => (bool) ($requested[$item->id]['precisa_analise_topografica'] ?? false),
                            'precisa_analise_qualidade' => (bool) ($requested[$item->id]['precisa_analise_qualidade'] ?? false),
                        ]
                    );
                }
            });
        } catch (\Throwable $exception) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }

            throw $exception;
        }

        if ($newPath && $oldPath && $oldPath !== $newPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return back()->with('success', 'Rascunho da Folha de Rosto atualizado com sucesso.');
    }

    public function submitAnalysis(Request $request, Tenant $tenant, FolhaRosto $folha): RedirectResponse
    {
        $this->ensureTenantFolha($tenant, $folha);

        if (! in_array($folha->status, ['rascunho', 'retornada'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Somente Folhas de Rosto em rascunho podem ser enviadas para análise.',
            ]);
        }

        $folha->loadMissing(['tenant', 'contract', 'obra', 'ordemServico', 'boletimMedicao']);

        $responsaveis = $this->responsaveisDaAnalise($tenant, $folha, 'fiscal');

        if ($responsaveis->isEmpty()) {
            throw ValidationException::withMessages([
                'responsaveis' => 'Cadastre ao menos um responsável fiscal antes de enviar para análise.',
            ]);
        }

        DB::transaction(function () use ($folha): void {
            FolhaRosto::query()->whereKey($folha->id)->lockForUpdate()->firstOrFail()
                ->forceFill([
                    'status' => 'analise_fiscal',
                    'submitted_for_analysis_at' => now(),
                ])
                ->save();
        });

        $actor = $request->user();
        if ($actor) {
            $folha->refresh();
            $responsaveis->each(fn (User $user) => $user->notify(
                new FolhaRostoSubmittedForAnalysisNotification($folha, $actor, 'fiscal')
            ));
        }

        return back()->with('success', 'Folha de Rosto enviada para análise fiscal. Responsáveis notificados.');
    }

    public function analisarPleito(Request $request, Tenant $tenant): Response
    {
        $selectedContractId = $request->integer('contract_id') ?: $tenant->contracts()->orderBy('code')->value('id');

        $contracts = $tenant->contracts()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->name,
            ]);

        $folhas = FolhaRosto::query()
            ->where('tenant_id', $tenant->id)
            ->when($selectedContractId, fn ($query) => $query->where('contract_id', $selectedContractId))
            ->whereIn('status', array_merge(
                collect(self::ANALYSIS_STAGES)->pluck('status')->all(),
                ['analisada']
            ))
            ->with('obra:id,codigo,nome')
            ->get([
                'id',
                'obra_id',
                'status',
            ]);

        $cards = collect(self::ANALYSIS_STAGES)
            ->map(fn (array $config, string $stage): array => [
                'key' => $stage,
                'title' => $config['title'],
                'status' => $config['status'],
                'rows' => $this->analysisSummaryRows($folhas->where('status', $config['status'])),
                'total' => $folhas->where('status', $config['status'])->count(),
            ])
            ->values()
            ->push([
                'key' => 'analisada',
                'title' => 'FRs analisadas por frente de serviço',
                'status' => 'analisada',
                'rows' => $this->analysisSummaryRows($folhas->where('status', 'analisada')),
                'total' => $folhas->where('status', 'analisada')->count(),
            ]);

        return Inertia::render('Tenant/Medicao/AnalisarPleito/Index', [
            'selectedContractId' => $selectedContractId,
            'contracts' => $contracts,
            'cards' => $cards,
        ]);
    }

    public function analisarPleitoGrupo(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'contract_id' => ['required', Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id)],
            'status' => ['required', 'string', Rule::in(array_merge(
                collect(self::ANALYSIS_STAGES)->pluck('status')->all(),
                ['analisada']
            ))],
            'obra_id' => ['nullable'],
        ]);

        $folhas = FolhaRosto::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $validated['contract_id'])
            ->where('status', $validated['status'])
            ->when(
                ($validated['obra_id'] ?? null) === 'sem-obra',
                fn ($query) => $query->whereNull('obra_id'),
                fn ($query) => $query->when($validated['obra_id'] ?? null, fn ($subquery, $obraId) => $subquery->where('obra_id', $obraId))
            )
            ->with([
                'obra:id,codigo,nome',
                'boletimMedicao:id,codigo,periodo,tipo,status',
                'ordemServico:id,codigo,titulo',
                'construtoraEmpresa:id,nome,sigla',
                'creator:id,name',
            ])
            ->get([
                'id',
                'tenant_id',
                'contract_id',
                'obra_id',
                'ordem_servico_id',
                'boletim_medicao_id',
                'construtora_empresa_id',
                'created_by_id',
                'codigo',
                'comentario',
                'status',
                'submitted_for_analysis_at',
                'created_at',
            ]);

        return response()->json([
            'boletins' => $this->analysisBoletins($tenant, $folhas),
        ]);
    }

    public function analisarPleitoFolha(Tenant $tenant, FolhaRosto $folha): JsonResponse
    {
        $this->ensureTenantFolha($tenant, $folha);

        $folha->load($this->analysisFolhaRelations());

        return response()->json([
            'folha' => $this->serializeAnalysisFolha($tenant, $folha),
        ]);
    }

    public function responsaveisAnalise(Request $request, Tenant $tenant): Response
    {
        $responsaveis = FolhaRostoAnaliseResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->with(['user:id,name,email,avatar_url'])
            ->orderBy('etapa')
            ->latest('id')
            ->get()
            ->filter(fn (FolhaRostoAnaliseResponsavel $responsavel): bool => $responsavel->user !== null)
            ->groupBy('user_id')
            ->map(function (Collection $userResponsaveis): array {
                $first = $userResponsaveis->first();

                return [
                    'user' => [
                        'id' => $first->user->id,
                        'name' => $first->user->name,
                        'email' => $first->user->email,
                        'avatar_url' => $first->user->avatar_url,
                    ],
                    'etapas' => $userResponsaveis
                        ->map(fn (FolhaRostoAnaliseResponsavel $responsavel): array => [
                            'id' => $responsavel->id,
                            'etapa' => $responsavel->etapa,
                            'etapa_label' => self::ANALYSIS_STAGES[$responsavel->etapa]['label'] ?? ucfirst($responsavel->etapa),
                        ])
                        ->sortBy('etapa_label')
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy(fn (array $responsavel): string => $responsavel['user']['name'])
            ->values();

        return Inertia::render('Tenant/Medicao/AnalisarPleito/Responsaveis', [
            'users' => $this->tenantUserOptions($tenant),
            'etapas' => collect(self::ANALYSIS_STAGES)
                ->map(fn (array $config, string $key): array => ['value' => $key, 'label' => $config['label']])
                ->values(),
            'responsaveis' => $responsaveis,
        ]);
    }

    public function storeResponsavelAnalise(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'etapa' => ['required', Rule::in(array_keys(self::ANALYSIS_STAGES))],
        ]);

        abort_unless(
            $tenant->memberships()
                ->where('user_id', $validated['user_id'])
                ->where('status', 'active')
                ->exists(),
            422,
            'O usuário selecionado não possui vínculo ativo com este tenant.'
        );

        $responsavel = FolhaRostoAnaliseResponsavel::withTrashed()->firstOrNew([
            'tenant_id' => $tenant->id,
            'user_id' => $validated['user_id'],
            'etapa' => $validated['etapa'],
        ]);

        $responsavel->fill([
            'created_by_id' => $request->user()?->id,
            'status' => 'active',
        ]);

        if ($responsavel->trashed()) {
            $responsavel->restore();
        }

        $responsavel->save();

        return back()->with('success', 'Responsável de análise cadastrado com sucesso.');
    }

    public function destroyResponsavelAnalise(Tenant $tenant, FolhaRostoAnaliseResponsavel $responsavel): RedirectResponse
    {
        abort_unless((int) $responsavel->tenant_id === (int) $tenant->id, 404);

        $responsavel->forceFill(['status' => 'inactive'])->save();
        $responsavel->delete();

        return back()->with('success', 'Responsável removido da análise.');
    }

    public function storeAnalise(Request $request, Tenant $tenant, FolhaRosto $folha): RedirectResponse
    {
        $this->ensureTenantFolha($tenant, $folha);

        $validated = $request->validate([
            'setores' => ['required', 'array'],
            'setores.*.comentario_geral' => ['nullable', 'string'],
            'setores.*.itens' => ['nullable', 'array'],
            'setores.*.itens.*.quantidade_aprovada' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'setores.*.itens.*.comentario' => ['nullable', 'string'],
        ]);

        $folha->loadMissing([
            'itens.ordemServicoItem.folhaRostoItens.folhaRosto:id,status',
            'itens.ordemServicoItem.medicaoItem:id,item',
        ]);
        $itemIds = $folha->itens->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $itensById = $folha->itens->keyBy('id');

        DB::transaction(function () use ($request, $folha, $validated, $itemIds, $itensById): void {
            $currentSetor = $this->analysisSectorForStatus($folha->status);

            if (! $currentSetor || ! isset($validated['setores'][$currentSetor])) {
                throw ValidationException::withMessages([
                    'setores' => 'Esta Folha de Rosto não está em uma etapa de análise válida.',
                ]);
            }

            foreach ([$currentSetor => $validated['setores'][$currentSetor]] as $setor => $payload) {

                $analise = FolhaRostoAnalise::updateOrCreate(
                    [
                        'folha_rosto_id' => $folha->id,
                        'setor' => $setor,
                    ],
                    [
                        'user_id' => $request->user()?->id,
                        'comentario_geral' => $payload['comentario_geral'] ?? null,
                    ]
                );

                foreach (($payload['itens'] ?? []) as $folhaRostoItemId => $itemPayload) {
                    $folhaRostoItemId = (int) $folhaRostoItemId;

                    if (! in_array($folhaRostoItemId, $itemIds, true)) {
                        continue;
                    }

                    $quantity = isset($itemPayload['quantidade_aprovada']) && $itemPayload['quantidade_aprovada'] !== ''
                        ? (float) $itemPayload['quantidade_aprovada']
                        : null;

                    if ($quantity !== null) {
                        $folhaRostoItem = $itensById->get($folhaRostoItemId);
                        $ordemItem = $folhaRostoItem?->ordemServicoItem;
                        $baseQuantity = (float) ($ordemItem?->quantidade_solicitada ?? 0);
                        $consumed = $ordemItem ? $this->consumedQuantity($ordemItem) : 0;
                        $saldo = max(0, $baseQuantity - $consumed + (float) $folhaRostoItem->quantidade_pleiteada);

                        if ($quantity > $saldo + 0.000001) {
                            throw ValidationException::withMessages([
                                "setores.{$setor}.itens.{$folhaRostoItemId}.quantidade_aprovada" => "A quantidade aprovada do item {$ordemItem?->medicaoItem?->item} supera o saldo disponível.",
                            ]);
                        }
                    }

                    $analise->itens()->updateOrCreate(
                        [
                            'folha_rosto_item_id' => $folhaRostoItemId,
                            'setor' => $setor,
                        ],
                        [
                            'quantidade_aprovada' => $quantity,
                            'comentario' => $itemPayload['comentario'] ?? null,
                        ]
                    );
                }
            }
        });

        return back()->with('success', 'Análise do pleito salva com sucesso.');
    }

    public function moveAnalysisFlow(Request $request, Tenant $tenant, FolhaRosto $folha): RedirectResponse
    {
        $this->ensureTenantFolha($tenant, $folha);

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['fiscal', 'qualidade', 'medicao', 'finalizar', 'retornar_construtora'])],
            'motivo' => ['nullable', 'string'],
        ]);

        if (! $this->analysisSectorForStatus($folha->status)) {
            throw ValidationException::withMessages([
                'action' => 'Esta Folha de Rosto nÃ£o estÃ¡ em uma etapa de anÃ¡lise vÃ¡lida.',
            ]);
        }

        $action = $validated['action'];
        $motivo = trim((string) ($validated['motivo'] ?? ''));

        if ($action === 'retornar_construtora' && $motivo === '') {
            throw ValidationException::withMessages([
                'motivo' => 'Informe o motivo para retornar a FR para a construtora.',
            ]);
        }

        if ($folha->status === 'analisada' && ! $this->folhaBoletimEstaAberto($folha)) {
            throw ValidationException::withMessages([
                'action' => 'Esta FR já foi finalizada e só pode retornar enquanto o BM estiver aberto para lançamento.',
            ]);
        }

        $nextStatus = $this->nextStatusForAnalysisAction($folha->status, $action);

        if (! $nextStatus) {
            throw ValidationException::withMessages([
                'action' => 'A movimentaÃ§Ã£o solicitada nÃ£o Ã© permitida para a etapa atual.',
            ]);
        }

        $actor = $request->user();

        DB::transaction(function () use ($folha, $actor, $action, $motivo, $nextStatus): void {
            $currentStatus = $folha->status;

            FolhaRosto::query()
                ->whereKey($folha->id)
                ->lockForUpdate()
                ->firstOrFail()
                ->forceFill(['status' => $nextStatus])
                ->save();

            FolhaRostoFluxoHistorico::create([
                'folha_rosto_id' => $folha->id,
                'user_id' => $actor?->id,
                'status_origem' => $currentStatus,
                'status_destino' => $nextStatus,
                'acao' => $action,
                'motivo' => $motivo !== '' ? $motivo : null,
            ]);

            $folha->forceFill(['status' => $nextStatus]);
        });

        if ($actor) {
            $freshFolha = $folha->fresh(['tenant', 'contract', 'obra', 'ordemServico', 'creator']);
            $this->notifyFlowChange($tenant, $freshFolha, $actor, $action, $motivo !== '' ? $motivo : null);
        }

        return back()->with('success', 'Fluxo da Folha de Rosto atualizado com sucesso.');
    }

    public function downloadMemoria(Tenant $tenant, FolhaRosto $folha): StreamedResponse
    {
        abort_unless((int) $folha->tenant_id === (int) $tenant->id, 404);
        abort_unless($folha->memoria_calculo_path, 404);
        abort_unless(Storage::disk('public')->exists($folha->memoria_calculo_path), 404);

        return Storage::disk('public')->download(
            $folha->memoria_calculo_path,
            $folha->memoria_calculo_nome_original ?: "{$folha->codigo}-memoria-calculo.zip",
            ['Content-Type' => $folha->memoria_calculo_mime_type ?: 'application/zip']
        );
    }

    private function serializeItem(OrdemServicoItem $item): array
    {
        $total = (float) $item->quantidade_solicitada;
        $consumed = $this->consumedQuantity($item);
        $available = max(0, $total - $consumed);
        $percentage = $total > 0 ? min(100, ($consumed / $total) * 100) : 0;

        return [
            'id' => $item->id,
            'item' => $item->medicaoItem?->item,
            'codigo' => $item->medicaoItem?->codigo,
            'descricao' => $item->medicaoItem?->descricao,
            'unidade' => $item->medicaoItem?->unidade,
            'quantidade_total' => $total,
            'quantidade_consumida' => $consumed,
            'quantidade_disponivel' => $available,
            'percentual_consumido' => $percentage,
            'valor_previsto' => (float) $item->valor_previsto,
        ];
    }

    private function consumedQuantity(OrdemServicoItem $item): float
    {
        return (float) $item->folhaRostoItens
            ->filter(fn ($claim) => $claim->folhaRosto && in_array($claim->folhaRosto->status, self::CONSUMING_STATUSES, true))
            ->sum('quantidade_pleiteada');
    }

    private function ensureTenantOrdem(Tenant $tenant, OrdemServico $ordem): void
    {
        abort_unless((int) $ordem->tenant_id === (int) $tenant->id, 404);
    }

    private function ensureTenantFolha(Tenant $tenant, FolhaRosto $folha): void
    {
        abort_unless((int) $folha->tenant_id === (int) $tenant->id, 404);
    }

    private function resolveBoletim(Request $request, Tenant $tenant): ?BoletimMedicao
    {
        $boletimId = $request->integer('boletim_id') ?: $request->integer('boletim_medicao_id');

        if (! $boletimId) {
            return null;
        }

        return BoletimMedicao::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($boletimId)
            ->firstOrFail();
    }

    private function resolveConstrutora(Tenant $tenant, int $contractId, int $empresaId): Empresa
    {
        $construtoraTipoId = TipoEmpresa::query()->where('nome', 'construtora')->value('id');

        $query = Empresa::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contractId)
            ->whereKey($empresaId);

        if ($construtoraTipoId) {
            $query->where('tipo_empresa_id', $construtoraTipoId);
        }

        $empresa = $query->first();

        if (! $empresa) {
            throw ValidationException::withMessages([
                'construtora_empresa_id' => 'Selecione uma construtora vinculada ao contrato da OS.',
            ]);
        }

        return $empresa;
    }

    private function construtoraOptions(Tenant $tenant, int $contractId): array
    {
        $construtoraTipoId = TipoEmpresa::query()->where('nome', 'construtora')->value('id');

        return Empresa::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contractId)
            ->when($construtoraTipoId, fn ($query) => $query->where('tipo_empresa_id', $construtoraTipoId))
            ->orderBy('nome')
            ->get(['id', 'nome', 'sigla'])
            ->map(fn (Empresa $empresa): array => [
                'id' => $empresa->id,
                'nome' => $empresa->nome,
                'sigla' => $empresa->sigla,
            ])
            ->values()
            ->all();
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

    private function tenantUserOptions(Tenant $tenant): array
    {
        return User::query()
            ->whereIn('id', $tenant->memberships()
                ->where('status', 'active')
                ->select('user_id'))
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

    /**
     * @return Collection<int, User>
     */
    private function responsaveisDaAnalise(Tenant $tenant, FolhaRosto $folha, string $etapa): Collection
    {
        return User::query()
            ->whereIn('id', FolhaRostoAnaliseResponsavel::query()
                ->where('tenant_id', $tenant->id)
                ->where('etapa', $etapa)
                ->where('status', 'active')
                ->select('user_id'))
            ->orderBy('name')
            ->get();
    }

    private function nextStatusForAnalysisAction(string $currentStatus, string $action): ?string
    {
        if ($action === 'retornar_construtora') {
            return 'retornada';
        }

        return match ($currentStatus) {
            'analise_fiscal' => match ($action) {
                'qualidade' => 'analise_qualidade',
                'medicao' => 'analise_medicao',
                default => null,
            },
            'analise_qualidade' => match ($action) {
                'fiscal' => 'analise_fiscal',
                'medicao' => 'analise_medicao',
                default => null,
            },
            'analise_medicao' => match ($action) {
                'fiscal' => 'analise_fiscal',
                'qualidade' => 'analise_qualidade',
                'finalizar' => 'analisada',
                default => null,
            },
            'analisada' => match ($action) {
                'fiscal' => 'analise_fiscal',
                'qualidade' => 'analise_qualidade',
                'medicao' => 'analise_medicao',
                default => null,
            },
            default => null,
        };
    }

    private function folhaBoletimEstaAberto(FolhaRosto $folha): bool
    {
        $status = $folha->boletimMedicao()->value('status');

        return $status === 'aberto_lancamento';
    }

    private function notifyFlowChange(Tenant $tenant, FolhaRosto $folha, User $actor, string $action, ?string $motivo = null): void
    {
        $destination = match ($action) {
            'fiscal' => 'fiscal',
            'qualidade' => 'qualidade',
            'medicao' => 'medicao',
            'retornar_construtora' => 'construtora',
            'finalizar' => 'analisada',
            default => $action,
        };

        $users = match ($action) {
            'fiscal', 'qualidade', 'medicao' => $this->responsaveisDaAnalise($tenant, $folha, $action),
            'retornar_construtora' => $this->usuariosConstrutoraFolha($tenant, $folha),
            'finalizar' => $this->usuariosConclusaoFolha($tenant, $folha),
            default => collect(),
        };

        $users
            ->unique('id')
            ->reject(fn (User $user): bool => (int) $user->id === (int) $actor->id)
            ->each(fn (User $user) => $user->notify(new FolhaRostoFlowChangedNotification(
                $folha,
                $actor,
                $action,
                $destination,
                $motivo,
            )));
    }

    private function usuariosConstrutoraFolha(Tenant $tenant, FolhaRosto $folha): Collection
    {
        $userIds = DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->where('empresa_id', $folha->construtora_empresa_id)
            ->where('status', 'active')
            ->pluck('user_id');

        $users = User::query()
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get();

        if ($users->isNotEmpty()) {
            return $users;
        }

        return $folha->creator ? collect([$folha->creator]) : collect();
    }

    private function usuariosConclusaoFolha(Tenant $tenant, FolhaRosto $folha): Collection
    {
        return collect([$folha->creator])
            ->filter()
            ->merge($this->responsaveisDaAnalise($tenant, $folha, 'medicao'))
            ->unique('id')
            ->values();
    }

    private function analysisSummaryRows(Collection $folhas): array
    {
        return $folhas
            ->groupBy(fn (FolhaRosto $folha): string => (string) ($folha->obra_id ?? 'sem-obra'))
            ->map(function (Collection $items): array {
                $first = $items->first();

                return [
                    'obra_id' => $first->obra_id ?? 'sem-obra',
                    'codigo' => $first->obra?->codigo ?? '-',
                    'obra' => $first->obra?->nome ?? 'Sem obra',
                    'total' => $items->count(),
                ];
            })
            ->sortBy('codigo')
            ->values()
            ->all();
    }

    private function analysisAgeInDays(FolhaRosto $folha): int
    {
        $submittedAt = $folha->submitted_for_analysis_at ?? $folha->created_at;

        return $submittedAt ? (int) max(0, $submittedAt->copy()->startOfDay()->diffInDays(now()->startOfDay())) : 0;
    }

    private function returnedAgeInDays(FolhaRosto $folha): ?int
    {
        if ($folha->status !== 'retornada') {
            return null;
        }

        $returnedAt = $this->latestReturnHistory($folha)?->created_at;

        $returnedAt ??= $folha->updated_at;

        return $returnedAt ? (int) max(0, $returnedAt->copy()->startOfDay()->diffInDays(now()->startOfDay())) : null;
    }

    private function latestReturnReason(FolhaRosto $folha): ?string
    {
        if ($folha->status !== 'retornada') {
            return null;
        }

        $motivo = $this->latestReturnHistory($folha)?->motivo;

        return $motivo !== null && trim($motivo) !== '' ? trim($motivo) : null;
    }

    private function latestReturnResponsible(FolhaRosto $folha): ?array
    {
        if ($folha->status !== 'retornada') {
            return null;
        }

        $user = $this->latestReturnHistory($folha)?->user;

        return $user ? [
            'name' => $user->name,
            'email' => $user->email,
        ] : null;
    }

    private function latestReturnHistory(FolhaRosto $folha): ?FolhaRostoFluxoHistorico
    {
        if ($folha->relationLoaded('fluxoHistoricos')) {
            return $folha->fluxoHistoricos
                ->where('acao', 'retornar_construtora')
                ->sortByDesc('created_at')
                ->first();
        }

        return $folha->fluxoHistoricos()
            ->where('acao', 'retornar_construtora')
            ->latest('id')
            ->first();
    }

    private function analysisBoletins(Tenant $tenant, Collection $folhas): array
    {
        return $folhas
            ->groupBy(fn (FolhaRosto $folha): string => (string) ($folha->boletim_medicao_id ?? 'sem-bm'))
            ->map(function (Collection $folhasBm) use ($tenant): array {
                $firstFolha = $folhasBm->first();
                $boletim = $firstFolha->boletimMedicao;

                return [
                    'id' => $boletim?->id,
                    'codigo' => $boletim?->codigo ?? 'Sem BM',
                    'periodo' => $boletim?->periodo?->format('m/y'),
                    'tipo_label' => $boletim ? $this->serializeBoletim($boletim)['tipo_label'] : null,
                    'total' => $folhasBm->count(),
                    'folhas' => $folhasBm
                        ->sortByDesc('id')
                        ->map(fn (FolhaRosto $folha): array => [
                            'id' => $folha->id,
                            'codigo' => $folha->codigo,
                            'comentario' => $folha->comentario,
                            'status' => $folha->status,
                            'active_sector' => $this->analysisSectorForStatus($folha->status),
                            'active_sector_label' => $this->analysisSectorLabelForStatus($folha->status),
                            'created_at' => $folha->created_at?->format('d/m/Y H:i'),
                            'data_envio' => $folha->created_at?->format('d/m/Y H:i'),
                            'dias_em_analise' => $this->analysisAgeInDays($folha),
                            'valor_total' => (float) $folha->itens()->sum('valor_pleiteado'),
                            'ordem' => $folha->ordemServico ? [
                                'id' => $folha->ordemServico->id,
                                'codigo' => $folha->ordemServico->codigo,
                                'titulo' => $folha->ordemServico->titulo,
                            ] : null,
                            'construtora' => $folha->construtoraEmpresa ? [
                                'nome' => $folha->construtoraEmpresa->nome,
                                'sigla' => $folha->construtoraEmpresa->sigla,
                            ] : null,
                            'creator' => $folha->creator?->name,
                            'url' => $folha->ordemServico
                                ? route('tenant.medicao.folha-rosto.show', [
                                    $tenant,
                                    $folha->ordemServico,
                                    'boletim_id' => $folha->boletim_medicao_id,
                                ])
                                : null,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->sortByDesc('id')
            ->values()
            ->all();
    }

    private function analysisFolhaRelations(): array
    {
        return [
            'obra:id,codigo,nome',
            'boletimMedicao:id,codigo,periodo,tipo,status',
            'ordemServico:id,codigo,titulo,gerenciadora_empresa_id,project_document_id',
            'ordemServico.gerenciadoraEmpresa:id,nome,sigla',
            'ordemServico.projectDocument:id,tenant_id,contract_id,obra_id,disciplina_id,project_phase_id,title,code,status',
            'ordemServico.projectDocument.openRncs:id,tenant_id,project_document_id,sequence_number,sequence_year,opened_at,status',
            'ordemServico.projectDocument.latestApprovedVersion',
            'ordemServico.projectDocument.latestVersion',
            'ordemServico.projectDocuments:id,tenant_id,contract_id,obra_id,disciplina_id,project_phase_id,title,code,status',
            'ordemServico.projectDocuments.openRncs:id,tenant_id,project_document_id,sequence_number,sequence_year,opened_at,status',
            'ordemServico.projectDocuments.latestApprovedVersion',
            'ordemServico.projectDocuments.latestVersion',
            'construtoraEmpresa:id,nome,sigla',
            'creator:id,name',
            'analises.itens',
            'itens.analises',
            'itens.ordemServicoItem.medicaoItem.reajusteIndice.indice.competencias',
            'itens.ordemServicoItem.folhaRostoItens.folhaRosto:id,status',
        ];
    }

    private function serializeAnalysisFolha(Tenant $tenant, FolhaRosto $folha): array
    {
        return [
            'id' => $folha->id,
            'codigo' => $folha->codigo,
            'comentario' => $folha->comentario,
            'status' => $folha->status,
            'active_sector' => $this->analysisSectorForStatus($folha->status),
            'active_sector_label' => $this->analysisSectorLabelForStatus($folha->status),
            'created_at' => $folha->created_at?->format('d/m/Y H:i'),
            'data_envio' => ($folha->submitted_for_analysis_at ?? $folha->created_at)?->format('d/m/Y H:i'),
            'dias_em_analise' => $this->analysisAgeInDays($folha),
            'valor_total' => (float) $folha->itens->sum('valor_pleiteado'),
            'memoria_calculo' => $folha->memoria_calculo_path ? [
                'nome' => $folha->memoria_calculo_nome_original,
                'size' => $folha->memoria_calculo_size,
                'download_url' => route('tenant.medicao.folha-rosto.memoria.download', [
                    $tenant,
                    $folha,
                ]),
            ] : null,
            'analises' => $this->serializeAnalises($folha),
            'itens' => $folha->itens
                ->map(fn (FolhaRostoItem $item): array => $this->serializeAnalysisItem($item))
                ->values()
                ->all(),
            'ordem' => $folha->ordemServico ? [
                'id' => $folha->ordemServico->id,
                'codigo' => $folha->ordemServico->codigo,
                'titulo' => $folha->ordemServico->titulo,
                'projeto' => $this->serializeOrdemProjeto($tenant, $folha->ordemServico),
                'projetos' => $this->serializeOrdemProjetos($tenant, $folha->ordemServico),
            ] : null,
            'gerenciadora' => $folha->ordemServico?->gerenciadoraEmpresa ? [
                'nome' => $folha->ordemServico->gerenciadoraEmpresa->nome,
                'sigla' => $folha->ordemServico->gerenciadoraEmpresa->sigla,
            ] : null,
            'construtora' => $folha->construtoraEmpresa ? [
                'nome' => $folha->construtoraEmpresa->nome,
                'sigla' => $folha->construtoraEmpresa->sigla,
            ] : null,
            'creator' => $folha->creator?->name,
            'url' => $folha->ordemServico
                ? route('tenant.medicao.folha-rosto.show', [
                    $tenant,
                    $folha->ordemServico,
                    'boletim_id' => $folha->boletim_medicao_id,
                ])
                : null,
        ];
    }

    private function serializeOrdemProjeto(Tenant $tenant, OrdemServico $ordem): ?array
    {
        return $this->serializeOrdemProjetos($tenant, $ordem)[0] ?? null;
    }

    private function serializeOrdemProjetos(Tenant $tenant, OrdemServico $ordem): array
    {
        $documents = $ordem->relationLoaded('projectDocuments') && $ordem->projectDocuments->isNotEmpty()
            ? $ordem->projectDocuments
            : collect($ordem->projectDocument ? [$ordem->projectDocument] : []);

        return $documents
            ->map(function ($document) use ($tenant): array {
                $version = $document->latestApprovedVersion ?: $document->latestVersion;
                $openRncs = $document->openRncs;
                $firstOpenRnc = $openRncs->first();

                return [
                    'id' => $document->id,
                    'codigo' => $document->code,
                    'titulo' => $document->title,
                    'status' => $document->status,
                    'open_rncs_count' => $openRncs->count(),
                    'first_open_rnc' => $firstOpenRnc ? [
                        'id' => $firstOpenRnc->id,
                        'numero' => $firstOpenRnc->formatted_number,
                        'url' => route('tenant.qualidade.rnc.show', [$tenant, $firstOpenRnc]),
                    ] : null,
                    'url' => $version
                        ? route('tenant.projects.viewer', [$tenant, $version]).'?workspace=visualizar&origin=medicao'
                        : route('tenant.projects.index', $tenant),
                ];
            })
            ->values()
            ->all();
    }

    private function serializeAnalysisItem(FolhaRostoItem $item): array
    {
        $ordemItem = $item->ordemServicoItem;
        $medicaoItem = $ordemItem?->medicaoItem;
        $baseQuantity = (float) ($ordemItem?->quantidade_solicitada ?? 0);
        $precoP0 = $baseQuantity > 0
            ? (float) ($ordemItem?->valor_previsto ?? 0) / $baseQuantity
            : 0;
        $consumed = $ordemItem ? $this->consumedQuantity($ordemItem) : 0;
        $saldo = max(0, $baseQuantity - $consumed + (float) $item->quantidade_pleiteada);

        return [
            'id' => $item->id,
            'item' => $medicaoItem?->item,
            'codigo' => $medicaoItem?->codigo,
            'descricao' => $medicaoItem?->descricao,
            'unidade' => $medicaoItem?->unidade,
            'preco_p0' => round($precoP0, 6),
            'preco_reajustado' => $this->adjustedValue($precoP0, $medicaoItem),
            'saldo' => $saldo,
            'quantidade_pleiteada' => (float) $item->quantidade_pleiteada,
            'valor_pleiteado' => (float) $item->valor_pleiteado,
            'precisa_analise_topografica' => (bool) $item->precisa_analise_topografica,
            'precisa_analise_qualidade' => (bool) $item->precisa_analise_qualidade,
            'analises' => $item->analises
                ->mapWithKeys(fn ($analise) => [
                    $analise->setor => [
                        'quantidade_aprovada' => $analise->quantidade_aprovada !== null ? (float) $analise->quantidade_aprovada : '',
                        'comentario' => $analise->comentario ?? '',
                    ],
                ])
                ->all(),
        ];
    }

    private function serializeAnalises(FolhaRosto $folha): array
    {
        return collect(self::ANALYSIS_STAGES)
            ->mapWithKeys(fn (array $config, string $setor): array => [
                $setor => [
                    'label' => $config['label'],
                    'comentario_geral' => $folha->analises->firstWhere('setor', $setor)?->comentario_geral ?? '',
                ],
            ])
            ->all();
    }

    private function analysisSectorForStatus(?string $status): ?string
    {
        return match ($status) {
            'analise_fiscal' => 'fiscal',
            'analise_qualidade' => 'qualidade',
            'analise_medicao' => 'medicao',
            'analisada' => 'analisada',
            default => null,
        };
    }

    private function analysisSectorLabelForStatus(?string $status): ?string
    {
        $sector = $this->analysisSectorForStatus($status);

        if ($sector === 'analisada') {
            return 'Finalizada';
        }

        return $sector ? self::ANALYSIS_STAGES[$sector]['label'] : null;
    }

    private function adjustedValue(float $baseValue, ?MedicaoItem $item): float
    {
        return round($baseValue * (1 + ($this->adjustmentPercentage($item) / 100)), 6);
    }

    private function adjustmentPercentage(?MedicaoItem $item): float
    {
        $indice = $item?->reajusteIndice?->indice;

        if (! $indice || (float) $indice->indice_base <= 0) {
            return 0.0;
        }

        $latestCompetencia = $indice->competencias
            ->sortByDesc('competencia')
            ->first();

        $currentIndex = $latestCompetencia
            ? (float) $latestCompetencia->valor_indice
            : (float) $indice->indice_atual;

        return (($currentIndex - (float) $indice->indice_base) / (float) $indice->indice_base) * 100;
    }

    private function serializeBoletim(BoletimMedicao $boletim): array
    {
        return [
            'id' => $boletim->id,
            'codigo' => $boletim->codigo,
            'periodo' => $boletim->periodo?->format('Y-m-d'),
            'periodo_formatado' => $boletim->periodo?->format('d/m/Y'),
            'tipo' => $boletim->tipo,
            'tipo_label' => match ($boletim->tipo) {
                'reequilibrio' => 'Reequilíbrio',
                'contingencia' => 'Contingência',
                default => 'Normal',
            },
            'status' => $boletim->status,
            'status_label' => match ($boletim->status) {
                'aberto_lancamento' => 'Aberto para lançamento',
                'congelado' => 'Congelado',
                'finalizado' => 'Finalizado',
                default => $boletim->status,
            },
        ];
    }
}

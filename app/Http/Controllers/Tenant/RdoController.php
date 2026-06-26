<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Obra;
use App\Models\RdoAnalise;
use App\Models\RdoConfiguracao;
use App\Models\RdoDiario;
use App\Models\RdoEquipamentoCadastro;
use App\Models\RdoMaoObraCadastro;
use App\Models\RdoResponsavel;
use App\Models\RdoSecaoRegistro;
use App\Models\RdoSubcontratadaCadastro;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\RdoFlowChangedNotification;
use App\Services\RdoDailyGenerator;
use App\Services\RdoPdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RdoController extends Controller
{
    public function calendar(Request $request, Tenant $tenant): Response
    {
        $filters = $request->validate([
            'contract_id' => ['nullable', 'integer'],
            'obra_id' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $contracts = $this->contracts($tenant);
        $selectedContractId = (int) ($filters['contract_id'] ?? $contracts->first()?->id ?? 0);
        $obras = $this->obras($tenant, $selectedContractId);
        $selectedObraId = (int) ($filters['obra_id'] ?? $obras->first()?->id ?? 0);
        $month = CarbonImmutable::createFromFormat('Y-m', $filters['month'] ?? now()->format('Y-m'))->startOfMonth();

        $configuration = RdoConfiguracao::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $selectedContractId)
            ->where(fn ($query) => $query
                ->where('obra_id', $selectedObraId)
                ->orWhereHas('obras', fn ($obrasQuery) => $obrasQuery->whereKey($selectedObraId)))
            ->first();

        $rdos = RdoDiario::query()
            ->with(['configuracao.obras:id,nome,codigo', 'secoes', 'signatureRequests'])
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $selectedContractId)
            ->when($configuration, fn ($query) => $query->where('rdo_configuracao_id', $configuration->id))
            ->when(! $configuration, fn ($query) => $query->whereRaw('1 = 0'))
            ->whereBetween('reference_date', [$month->startOfMonth(), $month->endOfMonth()])
            ->orderBy('reference_date')
            ->get()
            ->map(fn (RdoDiario $rdo): array => $this->rdoPayload($tenant, $rdo));

        return Inertia::render('Tenant/Rdo/Calendar', [
            'contracts' => $contracts,
            'obras' => $obras,
            'filters' => [
                'contract_id' => $selectedContractId ?: null,
                'obra_id' => $selectedObraId ?: null,
                'month' => $month->format('Y-m'),
            ],
            'configuration' => $configuration ? [
                'id' => $configuration->id,
                'active' => $configuration->active,
                'start_date' => $configuration->start_date?->format('Y-m-d'),
                'end_date' => $configuration->end_date?->format('Y-m-d'),
                'generation_weekdays' => $configuration->generation_weekdays,
                'obra_ids' => $configuration->obras()->pluck('obras.id')->map(fn ($id) => (int) $id)->values(),
                'obras' => $configuration->obras()
                    ->orderBy('codigo')
                    ->get(['obras.id', 'codigo', 'nome']),
            ] : null,
            'copyOptions' => $configuration
                ? RdoDiario::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('rdo_configuracao_id', $configuration->id)
                    ->whereHas('secoes')
                    ->latest('reference_date')
                    ->limit(60)
                    ->get(['id', 'code', 'reference_date'])
                    ->map(fn (RdoDiario $item) => [
                        'id' => $item->id,
                        'label' => "{$item->code} - {$item->reference_date?->format('d/m/Y')}",
                    ])
                : [],
            'rdos' => $rdos,
        ]);
    }

    public function settings(Request $request, Tenant $tenant): Response
    {
        $contracts = $this->contracts($tenant);
        $selectedContractId = $request->integer('contract_id') ?: (int) ($contracts->first()?->id ?? 0);
        $obras = $this->obras($tenant, $selectedContractId);
        $selectedObraId = $request->integer('obra_id') ?: (int) ($obras->first()?->id ?? 0);

        $configuration = RdoConfiguracao::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $selectedContractId)
            ->where(fn ($query) => $query
                ->where('obra_id', $selectedObraId)
                ->orWhereHas('obras', fn ($obrasQuery) => $obrasQuery->whereKey($selectedObraId)))
            ->first();

        $users = $tenant->users()
            ->wherePivot('status', 'active')
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email']);

        return Inertia::render('Tenant/Rdo/Settings', [
            'contracts' => $contracts,
            'obras' => $obras,
            'users' => $users,
            'filters' => [
                'contract_id' => $selectedContractId ?: null,
                'obra_id' => $selectedObraId ?: null,
            ],
            'configuration' => $configuration ? [
                'id' => $configuration->id,
                'obra_ids' => $configuration->obras()->pluck('obras.id')->map(fn ($id) => (int) $id)->values(),
                'responsible_user_id' => $configuration->responsible_user_id,
                'start_date' => $configuration->start_date?->format('Y-m-d'),
                'end_date' => $configuration->end_date?->format('Y-m-d'),
                'generation_time' => substr((string) $configuration->generation_time, 0, 5),
                'timezone' => $configuration->timezone,
                'generation_weekdays' => $configuration->generation_weekdays,
                'generate_on_holidays' => $configuration->generate_on_holidays,
                'copy_previous_day' => $configuration->copy_previous_day,
                'copy_workforce' => $configuration->copy_workforce,
                'copy_equipment' => $configuration->copy_equipment,
                'copy_pending_activities' => $configuration->copy_pending_activities,
                'require_photos' => $configuration->require_photos,
                'submission_deadline_days' => $configuration->submission_deadline_days,
                'active' => $configuration->active,
            ] : null,
        ]);
    }

    public function saveSettings(Request $request, Tenant $tenant, RdoDailyGenerator $generator): RedirectResponse
    {
        $validated = $request->validate([
            'contract_id' => [
                'required',
                'integer',
                Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'configuration_id' => [
                'nullable',
                'integer',
                Rule::exists('rdo_configuracoes', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'obra_ids' => ['required', 'array', 'min:1'],
            'obra_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('obras', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'responsible_user_id' => [
                'nullable',
                'integer',
                Rule::exists('tenant_users', 'user_id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'active')),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'generation_time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone'],
            'generation_weekdays' => ['required', 'array', 'min:1'],
            'generation_weekdays.*' => ['required', 'integer', 'between:0,6', 'distinct'],
            'generate_on_holidays' => ['required', 'boolean'],
            'copy_previous_day' => ['required', 'boolean'],
            'copy_workforce' => ['required', 'boolean'],
            'copy_equipment' => ['required', 'boolean'],
            'copy_pending_activities' => ['required', 'boolean'],
            'require_photos' => ['required', 'boolean'],
            'submission_deadline_days' => ['required', 'integer', 'min:1', 'max:365'],
            'active' => ['required', 'boolean'],
        ]);

        $validObrasCount = Obra::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $validated['contract_id'])
            ->whereIn('id', $validated['obra_ids'])
            ->count();
        abort_unless($validObrasCount === count($validated['obra_ids']), 422, 'Uma ou mais obras selecionadas não pertencem ao contrato.');

        $configuration = ! empty($validated['configuration_id'])
            ? RdoConfiguracao::query()
                ->where('tenant_id', $tenant->id)
                ->findOrFail($validated['configuration_id'])
            : null;
        $primaryObraId = $configuration && in_array((int) $configuration->obra_id, $validated['obra_ids'], true)
            ? (int) $configuration->obra_id
            : (int) $validated['obra_ids'][0];

        $configurationData = collect($validated)
            ->except(['configuration_id', 'obra_ids'])
            ->merge([
                'obra_id' => $primaryObraId,
                'created_by_id' => $request->user()?->id,
            ])
            ->all();

        if ($configuration) {
            $configuration->update($configurationData);
        } else {
            $configuration = RdoConfiguracao::create([
                'tenant_id' => $tenant->id,
                ...$configurationData,
            ]);
        }

        $configuration->obras()->sync($validated['obra_ids']);

        $today = CarbonImmutable::now($configuration->timezone)->startOfDay();
        if ($configuration->active && $today->betweenIncluded($configuration->start_date, $configuration->end_date ?? $today)) {
            $generator->generateForConfiguration($configuration, $today, false, $request->user()?->id);
        }

        return redirect()
            ->route('tenant.diario-obra.rdo.settings', [
                'tenant' => $tenant->slug,
                'contract_id' => $configuration->contract_id,
                'obra_id' => $configuration->obra_id,
            ])
            ->with('success', 'Parametrização do RDO salva com sucesso.');
    }

    public function generate(Request $request, Tenant $tenant, RdoDailyGenerator $generator): RedirectResponse
    {
        $validated = $request->validate([
            'configuration_id' => [
                'required',
                'integer',
                Rule::exists('rdo_configuracoes', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'reference_date' => ['required', 'date'],
            'copy_from_rdo_id' => [
                'nullable',
                'integer',
                Rule::exists('rdo_diarios', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('rdo_configuracao_id', $request->input('configuration_id'))),
            ],
        ]);

        $configuration = RdoConfiguracao::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($validated['configuration_id']);

        $rdo = $generator->generateForConfiguration(
            $configuration,
            CarbonImmutable::parse($validated['reference_date'])->startOfDay(),
            false,
            $request->user()?->id,
        );

        if ($rdo && ! empty($validated['copy_from_rdo_id'])) {
            $source = RdoDiario::query()
                ->where('tenant_id', $tenant->id)
                ->where('rdo_configuracao_id', $configuration->id)
                ->with('secoes')
                ->findOrFail($validated['copy_from_rdo_id']);

            foreach ($source->secoes as $section) {
                RdoSecaoRegistro::create([
                    'tenant_id' => $tenant->id,
                    'rdo_diario_id' => $rdo->id,
                    'obra_id' => $section->obra_id,
                    'updated_by_id' => $request->user()?->id,
                    'secao' => $section->secao,
                    'dados' => $section->dados,
                ]);
            }

            $rdo->update(['copied_from_rdo_id' => $source->id]);
        }

        return back()->with(
            $rdo ? 'success' : 'info',
            $rdo ? 'RDO criado com sucesso.' : 'O RDO dessa data já existe ou a data não está habilitada na parametrização.',
        );
    }

    public function show(Tenant $tenant, RdoDiario $rdo): Response
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        $rdo->load([
            'contract:id,code,name,cliente_empresa_id,construtora_empresa_id,fiscalizadora_empresa_id',
            'contract.clienteEmpresa:id,nome,sigla',
            'contract.construtoraEmpresa:id,nome,sigla',
            'contract.gerenciadoraEmpresa:id,nome,sigla',
            'obra:id,nome,codigo',
            'responsible:id,name,email',
            'copiedFrom:id,code,reference_date',
            'configuracao.obras:id,nome,codigo',
            'secoes',
            'analises.user:id,name,email',
            'analises.empresa:id,nome,sigla',
            'analises.obra:id,codigo,nome',
            'signatureRequests.signers',
        ]);
        $capabilities = $this->flowCapabilities($tenant, $rdo, request()->user());

        return Inertia::render('Tenant/Rdo/Show', [
            'rdo' => $this->rdoPayload($tenant, $rdo) + [
                'contract' => [
                    ...$rdo->contract->only(['id', 'code', 'name']),
                    'cliente' => $rdo->contract->clienteEmpresa,
                    'construtora' => $rdo->contract->construtoraEmpresa,
                    'gerenciadora' => $rdo->contract->gerenciadoraEmpresa,
                ],
                'obra' => $rdo->obra,
                'obras' => $rdo->configuracao?->obras ?? [],
                'responsible' => $rdo->responsible,
                'copied_from' => $rdo->copiedFrom,
                'can_edit' => $capabilities['can_edit'],
                'editable_obra_ids' => $capabilities['editable_obra_ids'],
                'flow_actions' => $capabilities['actions'],
                'flow_obra_ids' => $capabilities['flow_obra_ids'],
                'analyses' => $rdo->analises
                    ->sortByDesc('created_at')
                    ->values()
                    ->map(fn (RdoAnalise $analysis) => [
                        'id' => $analysis->id,
                        'stage' => $analysis->etapa,
                        'stage_label' => $this->stageLabel($analysis->etapa),
                        'decision' => $analysis->decisao,
                        'decision_label' => $this->decisionLabel($analysis->decisao),
                        'comment' => $analysis->comentario,
                        'user' => $analysis->user?->only(['id', 'name', 'email']),
                        'company' => $analysis->empresa?->only(['id', 'nome', 'sigla']),
                        'obra' => $analysis->obra?->only(['id', 'codigo', 'nome']),
                        'created_at' => $analysis->created_at?->format('d/m/Y H:i'),
                    ]),
                'signature' => $this->signaturePayload($tenant, $rdo),
                'sections' => $rdo->secoes
                    ->groupBy('obra_id')
                    ->map(fn ($sections) => $sections->mapWithKeys(fn (RdoSecaoRegistro $section) => [
                        $section->secao => $section->dados,
                    ])),
            ],
            'catalogs' => [
                'mao_obra' => RdoMaoObraCadastro::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('active', true)
                    ->orderBy('tipo')
                    ->orderBy('descricao')
                    ->get(['id', 'descricao', 'tipo', 'unidade']),
                'equipamentos' => RdoEquipamentoCadastro::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('active', true)
                    ->orderBy('descricao')
                    ->get(['id', 'codigo', 'descricao', 'unidade', 'propriedade']),
                'subcontratadas' => RdoSubcontratadaCadastro::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('active', true)
                    ->orderBy('razao_social')
                    ->get(['id', 'razao_social', 'nome_fantasia']),
            ],
        ]);
    }

    public function pdf(Tenant $tenant, RdoDiario $rdo, RdoPdfRenderer $pdfRenderer): HttpResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);

        $fileName = sprintf('rdo-%s-%s.pdf', $rdo->code, $rdo->reference_date?->format('Ymd'));

        return $pdfRenderer->render($tenant, $rdo)->stream($fileName);
    }

    public function saveSection(Request $request, Tenant $tenant, RdoDiario $rdo, string $secao): RedirectResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        abort_unless(in_array($secao, [
            'clima',
            'mao_obra',
            'equipamentos',
            'atividades',
            'fotos',
            'comentarios',
        ], true), 404);
        $capabilities = $this->flowCapabilities($tenant, $rdo, $request->user());
        abort_unless($capabilities['can_edit'] && in_array((int) $request->input('obra_id'), $capabilities['editable_obra_ids'], true), 403);

        $validated = $request->validate([
            'obra_id' => ['required', 'integer'],
            'dados' => ['required', 'array'],
            'fotos' => ['nullable', 'array', 'max:20'],
            'fotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ]);

        $obraPermitida = $rdo->configuracao()
            ->whereHas('obras', fn ($query) => $query->whereKey($validated['obra_id']))
            ->exists();
        abort_unless($obraPermitida, 422, 'A obra selecionada não faz parte deste RDO.');

        $dados = $validated['dados'];
        if ($secao === 'fotos') {
            $dados = $this->preparePhotoSectionData(
                $dados,
                $request->file('fotos', []),
                $tenant,
                $rdo,
                (int) $validated['obra_id'],
            );
        }

        RdoSecaoRegistro::updateOrCreate(
            ['rdo_diario_id' => $rdo->id, 'obra_id' => $validated['obra_id'], 'secao' => $secao],
            [
                'tenant_id' => $tenant->id,
                'updated_by_id' => $request->user()?->id,
                'dados' => $dados,
            ],
        );

        return back()->with('success', 'Seção do RDO salva com sucesso.');
    }

    public function saveAllSections(Request $request, Tenant $tenant, RdoDiario $rdo): RedirectResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        $capabilities = $this->flowCapabilities($tenant, $rdo, $request->user());
        abort_unless($capabilities['can_edit'] && in_array((int) $request->input('obra_id'), $capabilities['editable_obra_ids'], true), 403);

        $validated = $request->validate([
            'obra_id' => ['required', 'integer'],
            'secoes' => ['required', 'array'],
            'secoes.clima' => ['present', 'array'],
            'secoes.mao_obra' => ['present', 'array'],
            'secoes.equipamentos' => ['present', 'array'],
            'secoes.atividades' => ['present', 'array'],
            'secoes.fotos' => ['present', 'array'],
            'secoes.comentarios' => ['present', 'array'],
            'fotos' => ['nullable', 'array', 'max:20'],
            'fotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ]);

        $obraPermitida = $rdo->configuracao()
            ->whereHas('obras', fn ($query) => $query->whereKey($validated['obra_id']))
            ->exists();
        abort_unless($obraPermitida, 422, 'A obra selecionada não faz parte deste RDO.');

        $sections = $validated['secoes'];
        $sections['fotos'] = $this->preparePhotoSectionData(
            $sections['fotos'],
            $request->file('fotos', []),
            $tenant,
            $rdo,
            (int) $validated['obra_id'],
        );

        DB::transaction(function () use ($tenant, $rdo, $request, $validated, $sections): void {
            foreach ($sections as $section => $data) {
                RdoSecaoRegistro::updateOrCreate(
                    ['rdo_diario_id' => $rdo->id, 'obra_id' => $validated['obra_id'], 'secao' => $section],
                    [
                        'tenant_id' => $tenant->id,
                        'updated_by_id' => $request->user()?->id,
                        'dados' => $data,
                    ],
                );
            }
        });

        return back()->with('success', 'Preenchimento completo da obra salvo com sucesso.');
    }

    public function changeFlow(Request $request, Tenant $tenant, RdoDiario $rdo): RedirectResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        $rdo->loadMissing(['contract', 'configuracao.obras', 'secoes']);
        $capabilities = $this->flowCapabilities($tenant, $rdo, $request->user());

        if (empty($capabilities['actions'])) {
            if (! $request->headers->has('X-Inertia')) {
                abort(422, 'O RDO ainda não está pronto para esta ação.');
            }

            throw ValidationException::withMessages([
                'action' => 'O RDO ainda não está pronto para esta ação.',
            ]);
        }

        $validated = $request->validate([
            'action' => ['required', Rule::in(array_keys($capabilities['actions']))],
            'comment' => ['nullable', 'string', 'max:5000'],
            'obra_ids' => ['nullable', 'array', 'min:1'],
            'obra_ids.*' => ['integer', Rule::in($capabilities['flow_obra_ids'])],
        ]);
        $action = $validated['action'];
        $comment = trim((string) ($validated['comment'] ?? ''));
        abort_unless(isset($capabilities['actions'][$action]), 403);

        if ($request->headers->has('X-Inertia') && (in_array($action, ['approve_with_reservations', 'return'], true) || $rdo->status === 'devolvido_construtora') && $comment === '') {
            throw ValidationException::withMessages([
                'comment' => 'Informe o comentário ou a resposta para continuar.',
            ]);
        }

        if (in_array($action, ['approve_with_reservations', 'return'], true) || $rdo->status === 'devolvido_construtora') {
            abort_if($comment === '', 422, 'Informe o comentário ou a resposta para continuar.');
        }

        $stage = $capabilities['active_stage'];
        $oldStatus = $rdo->status;
        $obraIds = array_values(array_map('intval', $validated['obra_ids'] ?? $capabilities['flow_obra_ids']));
        if ($action === 'submit') {
            $obraIds = array_values(array_map('intval', $capabilities['flow_obra_ids']));
        }
        if (empty($obraIds)) {
            if (! $request->headers->has('X-Inertia')) {
                abort(422, 'Não há frentes pendentes atribuídas a este usuário nesta etapa.');
            }

            throw ValidationException::withMessages([
                'action' => 'Não há frentes pendentes atribuídas a este usuário nesta etapa.',
            ]);
        }
        if ($action === 'submit') {
            $this->ensureReadyForSubmission($rdo, $obraIds, $request->headers->has('X-Inertia'));
        }
        abort_if(empty($obraIds), 422, 'Não há frentes pendentes atribuídas a este usuário nesta etapa.');
        $membership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->first();

        [$newStatus, $message] = DB::transaction(function () use ($tenant, $rdo, $request, $membership, $stage, $action, $comment, $oldStatus, $obraIds): array {
            $analysisIds = [];
            foreach ($obraIds as $obraId) {
                $analysisIds[] = RdoAnalise::create([
                    'tenant_id' => $tenant->id,
                    'rdo_diario_id' => $rdo->id,
                    'obra_id' => $obraId,
                    'user_id' => $request->user()->id,
                    'empresa_id' => $membership?->empresa_id,
                    'etapa' => $stage,
                    'decisao' => $action,
                    'comentario' => $comment ?: null,
                    'status_anterior' => $oldStatus,
                    'status_novo' => $oldStatus,
                ])->id;
            }

            [$nextStatus, $flowMessage] = $this->resolveStatusAfterDecisions($rdo, $stage, $action);
            if ($nextStatus !== $oldStatus) {
                RdoAnalise::query()
                    ->whereIn('id', $analysisIds)
                    ->update(['status_novo' => $nextStatus]);
            }
            $rdo->update([
                'status' => $nextStatus,
                'submitted_at' => $action === 'submit' && $nextStatus === 'em_aprovacao' ? now() : $rdo->submitted_at,
                'approved_at' => $nextStatus === 'arquivado' ? now() : null,
            ]);

            return [$nextStatus, $flowMessage];
        });

        $this->notifyFlowRecipients($tenant, $rdo->fresh('contract'), $request->user(), $newStatus, $message);

        return back()->with('success', $message);
    }

    private function preparePhotoSectionData(
        array $data,
        array $uploads,
        Tenant $tenant,
        RdoDiario $rdo,
        int $obraId,
    ): array {
        $photosByKey = [];

        foreach ($data['arquivos'] ?? [] as $photo) {
            if (empty($photo['path'])) {
                continue;
            }

            $photo['comment'] = $photo['comment'] ?? $photo['legenda'] ?? null;
            $photo['legenda'] = $photo['comment'];
            $photosByKey['existing:'.$photo['path']] = $photo;
        }

        $newMetadata = array_values($data['novas_fotos'] ?? []);
        foreach ($uploads as $index => $upload) {
            $metadata = $newMetadata[$index] ?? [];
            $clientId = $metadata['client_id'] ?? "upload-{$index}";
            $comment = $metadata['comment'] ?? null;
            $photosByKey['new:'.$clientId] = [
                'nome' => $upload->getClientOriginalName(),
                'path' => $upload->store("tenant-{$tenant->id}/rdo/{$rdo->id}/obra-{$obraId}/fotos", 'public'),
                'comment' => $comment,
                'legenda' => $comment,
            ];
        }

        $ordered = [];
        foreach ($data['ordem_fotos'] ?? [] as $key) {
            if (isset($photosByKey[$key])) {
                $ordered[] = $photosByKey[$key];
                unset($photosByKey[$key]);
            }
        }

        $ordered = [...$ordered, ...array_values($photosByKey)];
        $data['arquivos'] = collect($ordered)
            ->values()
            ->map(fn (array $photo, int $index) => [
                ...$photo,
                'position' => $index + 1,
            ])
            ->all();
        unset($data['novas_fotos'], $data['ordem_fotos'], $data['legenda']);

        return $data;
    }

    private function contracts(Tenant $tenant)
    {
        return Contract::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    private function obras(Tenant $tenant, int $contractId)
    {
        return Obra::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contractId)
            ->orderBy('codigo')
            ->orderBy('nome')
            ->get(['id', 'contract_id', 'codigo', 'nome']);
    }

    private function rdoPayload(Tenant $tenant, RdoDiario $rdo): array
    {
        $missingSubmissionObraIds = $this->missingSubmissionObraIds($rdo);
        $submissionReady = empty($missingSubmissionObraIds);
        $signatureStatus = $this->calendarSignatureStatus($rdo);

        return [
            'id' => $rdo->id,
            'code' => $rdo->code,
            'reference_date' => $rdo->reference_date?->format('Y-m-d'),
            'reference_date_formatted' => $rdo->reference_date?->format('d/m/Y'),
            'status' => $rdo->status,
            'status_label' => match ($rdo->status) {
                'em_aprovacao' => 'Aprovação da gerenciadora e do cliente',
                'devolvido_construtora' => 'Devolvido à construtora',
                'pendente_comprovacao' => 'Pendente de comprovação',
                'arquivado' => 'Aprovado e arquivado',
                default => 'Rascunho',
            },
            'generated_automatically' => $rdo->generated_automatically,
            'copied_from_rdo_id' => $rdo->copied_from_rdo_id,
            'submission_ready' => $submissionReady,
            'missing_submission_obra_ids' => $missingSubmissionObraIds,
            'signature_status' => $signatureStatus,
            'calendar_status_label' => $this->calendarStatusLabel($rdo, $signatureStatus),
            'calendar_action_label' => $this->calendarActionLabel($rdo, $submissionReady),
            'show_url' => route('tenant.diario-obra.rdo.show', [$tenant->slug, $rdo->id]),
        ];
    }

    private function signaturePayload(Tenant $tenant, RdoDiario $rdo): ?array
    {
        $signature = $rdo->signatureRequests
            ->sortByDesc('id')
            ->first();

        if (! $signature) {
            return null;
        }

        return [
            'id' => $signature->id,
            'provider' => $signature->provider,
            'status' => $signature->status,
            'status_label' => match ($signature->status) {
                'completed' => 'Assinado',
                'failed' => 'Falha no envio',
                'cancelled' => 'Cancelado',
                'sent', 'pending' => 'Aguardando assinaturas',
                default => 'Rascunho da assinatura',
            },
            'sent_at' => $signature->sent_at?->format('d/m/Y H:i'),
            'completed_at' => $signature->completed_at?->format('d/m/Y H:i'),
            'signing_url' => $signature->signing_url,
            'error_message' => $signature->error_message,
            'unsigned_download_url' => $signature->unsigned_pdf_path
                ? route('tenant.diario-obra.rdo.signatures.unsigned', [$tenant->slug, $rdo->id, $signature->id])
                : null,
            'signed_download_url' => $signature->signed_pdf_path
                ? route('tenant.diario-obra.rdo.signatures.signed', [$tenant->slug, $rdo->id, $signature->id])
                : null,
            'signers' => $signature->signers
                ->map(fn ($signer) => [
                    'id' => $signer->id,
                    'role' => $signer->role,
                    'role_label' => $this->stageLabel($signer->role),
                    'name' => $signer->name,
                    'email' => $signer->email,
                    'status' => $signer->status,
                    'status_label' => match ($signer->status) {
                        'completed' => 'Assinado',
                        'cancelled' => 'Cancelado',
                        'failed' => 'Falha',
                        default => 'Pendente',
                    },
                    'signed_at' => $signer->signed_at?->format('d/m/Y H:i'),
                    'signing_url' => $signer->signing_url,
                ])
                ->values(),
        ];
    }

    private function calendarSignatureStatus(RdoDiario $rdo): ?string
    {
        if ($rdo->status !== 'arquivado') {
            return null;
        }

        $signature = $rdo->relationLoaded('signatureRequests')
            ? $rdo->signatureRequests->sortByDesc('id')->first()
            : $rdo->signatureRequests()->latest('id')->first();

        if (! $signature) {
            return 'ready';
        }

        return $signature->status === 'completed' && $signature->signed_pdf_path
            ? 'completed'
            : 'waiting';
    }

    private function calendarStatusLabel(RdoDiario $rdo, ?string $signatureStatus): string
    {
        if ($rdo->status === 'arquivado') {
            return match ($signatureStatus) {
                'completed' => 'Assinado',
                'waiting' => 'Aguardando assinatura',
                default => 'Pronto para assinatura',
            };
        }

        return match ($rdo->status) {
            'em_aprovacao' => 'Aprovação da gerenciadora e do cliente',
            'devolvido_construtora' => 'Devolvido à construtora',
            'pendente_comprovacao' => 'Pendente de comprovação',
            default => 'Rascunho',
        };
    }

    private function calendarActionLabel(RdoDiario $rdo, bool $submissionReady): string
    {
        return match ($rdo->status) {
            'rascunho' => $submissionReady ? 'Enviar análise' : 'Preencher',
            'devolvido_construtora', 'pendente_comprovacao' => 'Corrigir',
            'em_aprovacao' => 'Análise',
            'arquivado' => 'Visualizar',
            default => 'Visualizar',
        };
    }

    private function flowCapabilities(Tenant $tenant, RdoDiario $rdo, ?User $user): array
    {
        if (! $user) {
            return ['can_edit' => false, 'editable_obra_ids' => [], 'flow_obra_ids' => [], 'active_stage' => null, 'actions' => []];
        }

        $membership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
        $isAdmin = $user->is_platform_admin || in_array($membership?->role, ['tenant_owner', 'tenant_admin'], true);
        $allObraIds = $rdo->configuracao?->obras->pluck('id')->map(fn ($id) => (int) $id)->all() ?? [];
        $stage = $this->stageForUserAndStatus($tenant, $rdo, $user, $isAdmin, $membership);
        $assignedObraIds = $stage
            ? $this->responsibleObraIds($tenant, $rdo, $user, $stage, $isAdmin, $membership)
            : [];
        $pendingObraIds = $this->pendingObraIds($rdo, $stage, $assignedObraIds);
        $editableObraIds = in_array($rdo->status, ['rascunho', 'devolvido_construtora', 'pendente_comprovacao'], true)
            ? $this->responsibleObraIds($tenant, $rdo, $user, 'construtora', $isAdmin, $membership)
            : [];
        $editableStatuses = ['rascunho', 'devolvido_construtora', 'pendente_comprovacao'];
        $actions = [];

        if ($stage === 'construtora' && in_array($rdo->status, $editableStatuses, true)) {
            $pendingObraIds = empty($this->missingSubmissionObraIds($rdo))
                ? $this->pendingObraIds($rdo, 'construtora', $allObraIds)
                : [];
        }

        if ($stage === 'construtora' && $pendingObraIds && in_array($rdo->status, $editableStatuses, true)) {
            $actions['submit'] = [
                'label' => $rdo->status === 'pendente_comprovacao' ? 'Enviar comprovação das frentes' : 'Enviar frentes para análise',
                'tone' => 'primary',
                'comment_required' => $rdo->status !== 'rascunho',
            ];
        }
        if (in_array($stage, ['gerenciadora', 'cliente'], true) && $pendingObraIds) {
            $actions += $this->reviewActions();
        }

        return [
            'can_edit' => ! empty($editableObraIds),
            'editable_obra_ids' => array_values(array_intersect($allObraIds, $editableObraIds)),
            'flow_obra_ids' => array_values($pendingObraIds),
            'active_stage' => $stage,
            'actions' => $actions,
        ];
    }

    private function reviewActions(): array
    {
        return [
            'approve' => ['label' => 'Aprovar', 'tone' => 'success', 'comment_required' => false],
            'approve_with_reservations' => ['label' => 'Aprovar com ressalvas', 'tone' => 'warning', 'comment_required' => true],
            'return' => ['label' => 'Devolver à construtora', 'tone' => 'danger', 'comment_required' => true],
        ];
    }

    private function stageForUserAndStatus(
        Tenant $tenant,
        RdoDiario $rdo,
        User $user,
        bool $isAdmin,
        ?TenantUser $membership,
    ): ?string
    {
        if ($rdo->status !== 'em_aprovacao') {
            return 'construtora';
        }

        foreach (['gerenciadora', 'cliente'] as $stage) {
            $assigned = $this->responsibleObraIds($tenant, $rdo, $user, $stage, $isAdmin, $membership);
            if ($this->pendingObraIds($rdo, $stage, $assigned)) {
                return $stage;
            }
        }

        return null;
    }

    private function responsibleObraIds(
        Tenant $tenant,
        RdoDiario $rdo,
        User $user,
        string $stage,
        bool $isAdmin,
        ?TenantUser $membership,
    ): array {
        $allObraIds = $rdo->configuracao?->obras->pluck('id')->map(fn ($id) => (int) $id)->all() ?? [];
        if ($isAdmin) {
            return $allObraIds;
        }

        $hasConfiguredResponsibilities = RdoResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $rdo->contract_id)
            ->whereIn('obra_id', $allObraIds)
            ->where('etapa', $stage)
            ->where('status', 'active')
            ->exists();

        if ($hasConfiguredResponsibilities) {
            return RdoResponsavel::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $rdo->contract_id)
                ->whereIn('obra_id', $allObraIds)
                ->where('user_id', $user->id)
                ->where('etapa', $stage)
                ->where('status', 'active')
                ->pluck('obra_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $expectedCompanyId = match ($stage) {
            'gerenciadora' => $rdo->contract?->fiscalizadora_empresa_id,
            'cliente' => $rdo->contract?->cliente_empresa_id,
            default => $rdo->contract?->construtora_empresa_id,
        };

        return $expectedCompanyId && (int) $membership?->empresa_id === (int) $expectedCompanyId
            ? $allObraIds
            : [];
    }

    private function pendingObraIds(RdoDiario $rdo, string $stage, array $assignedObraIds): array
    {
        return array_values(array_diff($assignedObraIds, $this->decidedObraIds($rdo, $stage)));
    }

    private function decidedObraIds(RdoDiario $rdo, string $stage): array
    {
        $decisions = $stage === 'construtora'
            ? ['submit']
            : ['approve', 'approve_with_reservations'];

        return RdoAnalise::query()
            ->where('rdo_diario_id', $rdo->id)
            ->where('etapa', $stage)
            ->where('created_at', '>=', $this->stageStartedAt($rdo))
            ->whereIn('decisao', $decisions)
            ->whereNotNull('obra_id')
            ->pluck('obra_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function stageStartedAt(RdoDiario $rdo)
    {
        return RdoAnalise::query()
            ->where('rdo_diario_id', $rdo->id)
            ->where('status_novo', $rdo->status)
            ->where('status_anterior', '!=', $rdo->status)
            ->latest('id')
            ->value('created_at') ?? $rdo->created_at;
    }

    private function jointApprovalCompleted(RdoDiario $rdo): bool
    {
        $allObraIds = $rdo->configuracao->obras->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach (['gerenciadora', 'cliente'] as $stage) {
            if (array_diff($allObraIds, $this->decidedObraIds($rdo, $stage))) {
                return false;
            }
        }

        return true;
    }

    private function requiredSubmissionSections(): array
    {
        return ['clima', 'mao_obra', 'equipamentos', 'atividades', 'fotos', 'comentarios'];
    }

    private function missingSubmissionObraIds(RdoDiario $rdo): array
    {
        $rdo->loadMissing(['configuracao.obras', 'secoes']);
        $requiredSections = $this->requiredSubmissionSections();

        return $rdo->configuracao?->obras
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(function (int $obraId) use ($rdo, $requiredSections): bool {
                $filled = $rdo->secoes->where('obra_id', $obraId)->pluck('secao')->all();
                if (array_diff($requiredSections, $filled)) {
                    return true;
                }

                if ($rdo->configuracao?->require_photos) {
                    $photos = $rdo->secoes->first(fn ($section) => (int) $section->obra_id === $obraId && $section->secao === 'fotos');

                    return empty($photos?->dados['arquivos']);
                }

                return false;
            })
            ->values()
            ->all() ?? [];
    }

    private function ensureReadyForSubmission(RdoDiario $rdo, array $obraIds, bool $asValidation = false): void
    {
        $requiredSections = $this->requiredSubmissionSections();

        foreach ($obraIds as $obraId) {
            $filled = $rdo->secoes->where('obra_id', $obraId)->pluck('secao')->all();
            if (array_diff($requiredSections, $filled)) {
                if (! $asValidation) {
                    abort(422, 'Preencha todas as seções das frentes selecionadas antes de enviar.');
                }

                throw ValidationException::withMessages([
                    'action' => 'Preencha todas as seções das frentes selecionadas antes de enviar.',
                ]);
            }
            abort_if(array_diff($requiredSections, $filled), 422, 'Preencha todas as seções das frentes sob sua responsabilidade antes de enviar.');

            if ($rdo->configuracao?->require_photos) {
                $photos = $rdo->secoes->first(fn ($section) => (int) $section->obra_id === $obraId && $section->secao === 'fotos');
                if (empty($photos?->dados['arquivos'])) {
                    if (! $asValidation) {
                        abort(422, 'Inclua ao menos uma foto em cada frente selecionada antes de enviar.');
                    }

                    throw ValidationException::withMessages([
                        'action' => 'Inclua ao menos uma foto em cada frente selecionada antes de enviar.',
                    ]);
                }
                abort_if(empty($photos?->dados['arquivos']), 422, 'Inclua ao menos uma foto em cada obra antes de enviar.');
            }
        }
    }

    private function resolveStatusAfterDecisions(RdoDiario $rdo, string $stage, string $action): array
    {
        if ($action === 'return') {
            return ['devolvido_construtora', 'RDO devolvido à construtora para ajustes em uma ou mais frentes.'];
        }

        $allObraIds = $rdo->configuracao->obras->pluck('id')->map(fn ($id) => (int) $id)->all();
        $decidedObraIds = $this->decidedObraIds($rdo, $stage);
        if (array_diff($allObraIds, $decidedObraIds)) {
            return [$rdo->status, 'Decisão registrada. O RDO aguarda as demais frentes desta etapa.'];
        }

        if ($stage === 'construtora') {
            return ['em_aprovacao', $rdo->status === 'pendente_comprovacao'
                ? 'Comprovações enviadas para aprovação conjunta da gerenciadora e do cliente.'
                : 'Todas as frentes foram enviadas para aprovação conjunta da gerenciadora e do cliente.'];
        }

        if (! $this->jointApprovalCompleted($rdo)) {
            return [$rdo->status, 'Decisão registrada. O RDO aguarda os demais pareceres da gerenciadora e do cliente.'];
        }

        $hasReservations = RdoAnalise::query()
            ->where('rdo_diario_id', $rdo->id)
            ->whereIn('etapa', ['gerenciadora', 'cliente'])
            ->where('created_at', '>=', $this->stageStartedAt($rdo))
            ->where('decisao', 'approve_with_reservations')
            ->exists();

        return $hasReservations
            ? ['pendente_comprovacao', 'A aprovação conjunta foi concluída com ressalvas; a construtora deve enviar as comprovações.']
            : ['arquivado', 'Gerenciadora e cliente aprovaram todas as frentes; o RDO foi arquivado.'];
    }

    private function notifyFlowRecipients(Tenant $tenant, RdoDiario $rdo, User $actor, string $newStatus, string $message): void
    {
        $stage = match ($newStatus) {
            'em_aprovacao' => 'aprovacao_conjunta',
            'devolvido_construtora', 'pendente_comprovacao' => 'construtora',
            default => null,
        };
        $users = $stage
            ? User::query()
                ->whereHas('rdoResponsabilidades', fn ($query) => $query
                    ->where('rdo_responsaveis.tenant_id', $tenant->id)
                    ->where('rdo_responsaveis.contract_id', $rdo->contract_id)
                    ->when($stage === 'aprovacao_conjunta',
                        fn ($query) => $query->whereIn('rdo_responsaveis.etapa', ['gerenciadora', 'cliente']),
                        fn ($query) => $query->where('rdo_responsaveis.etapa', $stage))
                    ->where('rdo_responsaveis.status', 'active'))
                ->get()
            : collect();

        if ($users->isEmpty()) {
            $companyIds = match ($newStatus) {
                'em_aprovacao' => [$rdo->contract?->fiscalizadora_empresa_id, $rdo->contract?->cliente_empresa_id],
                'devolvido_construtora', 'pendente_comprovacao' => [$rdo->contract?->construtora_empresa_id],
                'arquivado' => [$rdo->contract?->construtora_empresa_id, $rdo->contract?->fiscalizadora_empresa_id],
                default => [],
            };
            $users = User::query()
                ->whereHas('tenantMemberships', fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'active')
                    ->whereIn('empresa_id', array_filter($companyIds)))
                ->get();
        }

        $users->where('id', '!=', $actor->id)
            ->each(fn (User $user) => $user->notify(new RdoFlowChangedNotification($rdo, $tenant, $actor, $message)));
    }

    private function companyLogoDataUri(?object $empresa): ?string
    {
        if (! $empresa?->logo_path || ! Storage::disk('public')->exists($empresa->logo_path)) {
            return null;
        }

        return $this->containedPdfImageDataUri(Storage::disk('public')->path($empresa->logo_path), 180, 62);
    }

    private function containedPdfImageDataUri(string $sourcePath, int $targetCanvasWidth, int $targetCanvasHeight): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg') || ! function_exists('imagecopyresampled')) {
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($sourcePath));

        if (! $source) {
            return null;
        }

        $sourceWidth = max(imagesx($source), 1);
        $sourceHeight = max(imagesy($source), 1);
        $canvas = imagecreatetruecolor($targetCanvasWidth, $targetCanvasHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        imagefill($canvas, 0, 0, $white);

        $scale = min($targetCanvasWidth / $sourceWidth, $targetCanvasHeight / $sourceHeight);
        $targetWidth = (int) round($sourceWidth * $scale);
        $targetHeight = (int) round($sourceHeight * $scale);
        $targetX = (int) floor(($targetCanvasWidth - $targetWidth) / 2);
        $targetY = (int) floor(($targetCanvasHeight - $targetHeight) / 2);

        imagecopyresampled($canvas, $source, $targetX, $targetY, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        return $jpeg ? 'data:image/jpeg;base64,'.base64_encode($jpeg) : null;
    }

    private function stageLabel(string $stage): string
    {
        return match ($stage) {
            'gerenciadora' => 'Gerenciadora',
            'cliente' => 'Cliente',
            'assinatura' => 'Assinatura do RDO',
            default => 'Construtora',
        };
    }

    private function decisionLabel(string $decision): string
    {
        return match ($decision) {
            'approve' => 'Aprovado',
            'approve_with_reservations' => 'Aprovado com ressalvas',
            'return' => 'Devolvido',
            default => 'Enviado para análise',
        };
    }
}

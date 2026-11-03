<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Obra;
use App\Models\RdoAnalise;
use App\Models\RdoConfiguracao;
use App\Models\RdoDiario;
use App\Models\RdoDivergencia;
use App\Models\RdoEquipamentoCadastro;
use App\Models\RdoFiscalizacaoRegistro;
use App\Models\RdoMaoObraCadastro;
use App\Models\RdoResponsavel;
use App\Models\RdoSecaoRegistro;
use App\Models\RdoSubcontratadaCadastro;
use App\Models\RdoVersao;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\RdoFlowChangedNotification;
use App\Services\RdoDailyGenerator;
use App\Services\RdoPdfRenderer;
use App\Support\DiarioObraPermissions;
use App\Support\RdoWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RdoController extends Controller
{
    private bool $savingWorksBatch = false;
    private array $batchFiles = [];

    public function saveWorksSections(Request $request, Tenant $tenant, RdoDiario $rdo): RedirectResponse
    {
        return $this->saveWorksBatch($request, $tenant, $rdo, false);
    }

    public function saveWorksInspections(Request $request, Tenant $tenant, RdoDiario $rdo): RedirectResponse
    {
        return $this->saveWorksBatch($request, $tenant, $rdo, true);
    }

    private function saveWorksBatch(Request $request, Tenant $tenant, RdoDiario $rdo, bool $inspection): RedirectResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        $validated = $request->validate(['obras' => ['required', 'array', 'min:1', 'max:200'], 'obras.*' => ['required', 'array'], 'obras.*.obra_id' => ['required', 'integer', 'distinct']]);
        $this->savingWorksBatch = true;
        $this->batchFiles = [];
        try {
            DB::transaction(function () use ($request, $tenant, $rdo, $inspection, $validated) {
                $this->lockCurrentDiary($rdo, !$inspection);
                foreach ($request->input('obras', []) as $index => $data) {
                    $child = Request::createFrom($request);
                    $day = !$inspection && $request->exists('is_holiday') ? ['is_holiday' => $request->input('is_holiday')] : [];
                    $child->request->replace([...$data, ...$day, 'filling_party' => $request->input('filling_party')]);
                    $child->files->replace($request->file("obras.{$index}", []));
                    $child->setJson(new \Symfony\Component\HttpFoundation\InputBag([...$data, ...$day, 'filling_party' => $request->input('filling_party')]));
                    $rdo->unsetRelation('secoes');
                    try {
                        if ($inspection) $this->saveInspection($child, $tenant, $rdo);
                        else $this->saveAllSections($child, $tenant, $rdo);
                    } catch (ValidationException $error) {
                        $name = $rdo->configuracao->obras->firstWhere('id', (int) $data['obra_id'])?->codigo ?? $data['obra_id'];
                        throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $key) => ["obras.{$data['obra_id']}.{$key}" => array_map(fn ($message) => "Obra {$name}: {$message}", $messages)])->all());
                    }
                }
                if (!$inspection) $this->completeSoloRdo($tenant, $rdo, $request->user());
            });
        } catch (\Throwable $error) {
            foreach ($this->batchFiles as [$disk, $path]) Storage::disk($disk)->delete($path);
            throw $error;
        } finally {
            $this->savingWorksBatch = false;
            $this->batchFiles = [];
        }
        return back()->with('success', 'Todos os preenchimentos alterados foram salvos.');
    }

    public function tourPreview(Request $request, Tenant $tenant): Response
    {
        $screen = $request->string('screen')->toString();
        $allowedScreens = [
            'settings',
            'catalogs',
            'responsibles',
            'calendar',
            'consolidation',
            'approval',
            'signature',
            'dashboard',
        ];

        return Inertia::render('Tenant/Rdo/TourPreview', [
            'tenant' => $tenant,
            'screen' => in_array($screen, $allowedScreens, true) ? $screen : 'settings',
        ]);
    }

    public function dashboard(Request $request, Tenant $tenant): Response
    {
        $filters = $request->validate([
            'contract_id' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $contracts = $this->contracts($tenant, $request->user(), DiarioObraPermissions::DASHBOARD);
        $selectedContractId = (int) ($filters['contract_id'] ?? $contracts->first()?->id ?? 0);
        $month = CarbonImmutable::createFromFormat('Y-m', $filters['month'] ?? now()->format('Y-m'))->startOfMonth();

        $rdos = RdoDiario::query()
            ->with(['configuracao.obras:id,codigo,nome', 'secoes:id,rdo_diario_id,obra_id,secao,filling_party', 'signatureRequests:id,rdo_diario_id,status,completed_at,signed_pdf_path'])
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $selectedContractId)
            ->whereBetween('reference_date', [$month->startOfMonth(), $month->endOfMonth()])
            ->orderBy('reference_date')
            ->get();

        $statusLabels = [
            'rascunho' => 'Rascunho',
            'em_analise_construtora' => 'Na construtora',
            'em_analise_gerenciadora' => 'Na gerenciadora',
            'em_aprovacao_cliente' => 'No cliente',
            'devolvido_construtora' => 'Retornado',
            'pendente_comprovacao' => 'Com ressalvas',
            'pronto_assinatura' => 'Aguardando envio',
            'aguardando_assinatura' => 'Aguardando assinatura',
            'assinado' => 'Assinado',
        ];

        $statusCounts = collect($statusLabels)
            ->map(fn () => 0)
            ->all();

        $daily = [];
        for ($day = $month->startOfMonth(); $day->lte($month->endOfMonth()); $day = $day->addDay()) {
            $daily[$day->format('Y-m-d')] = [
                'date' => $day->format('d/m'),
                'criados' => 0,
                'enviados' => 0,
                'aprovados' => 0,
            ];
        }

        $obraStats = [];
        $completionTotal = 0;

        $rdos->each(function (RdoDiario $rdo) use (&$statusCounts, &$daily, &$obraStats, &$completionTotal): void {
            $signatureStatus = $this->calendarSignatureStatus($rdo);
            $dashboardStatus = $rdo->status === 'arquivado'
                ? ($signatureStatus === 'completed' ? 'assinado' : ($signatureStatus === 'waiting' ? 'aguardando_assinatura' : 'pronto_assinatura'))
                : $rdo->status;

            $statusCounts[$dashboardStatus] = ($statusCounts[$dashboardStatus] ?? 0) + 1;

            $dateKey = $rdo->reference_date?->format('Y-m-d');
            if ($dateKey && isset($daily[$dateKey])) {
                $daily[$dateKey]['criados']++;
                if ($rdo->submitted_at) {
                    $daily[$dateKey]['enviados']++;
                }
                if ($rdo->status === 'arquivado') {
                    $daily[$dateKey]['aprovados']++;
                }
            }

            $progress = $this->rdoCompletionPercent($rdo);
            $completionTotal += $progress;

            $obras = $rdo->configuracao?->obras ?? collect([$rdo->obra])->filter();
            $obras->each(function (Obra $obra) use (&$obraStats, $rdo, $progress): void {
                $key = (string) $obra->id;
                $obraStats[$key] ??= [
                    'id' => $obra->id,
                    'label' => trim(($obra->codigo ? "{$obra->codigo} - " : '').$obra->nome),
                    'total' => 0,
                    'em_analise' => 0,
                    'aprovados' => 0,
                    'progress_sum' => 0,
                ];

                $obraStats[$key]['total']++;
                $obraStats[$key]['progress_sum'] += $progress;
                if (RdoWorkflow::isReview($rdo->status)) {
                    $obraStats[$key]['em_analise']++;
                }
                if ($rdo->status === 'arquivado') {
                    $obraStats[$key]['aprovados']++;
                }
            });
        });

        $total = $rdos->count();
        $submitted = $rdos->whereNotNull('submitted_at')->count();
        $approved = $rdos->where('status', 'arquivado')->count();

        $recent = $rdos
            ->sortByDesc('reference_date')
            ->take(8)
            ->map(fn (RdoDiario $rdo) => [
                'id' => $rdo->id,
                'code' => $rdo->code,
                'reference_date' => $rdo->reference_date?->format('d/m/Y'),
                'status' => $rdo->status,
                'status_label' => $this->calendarStatusLabel($rdo, $this->calendarSignatureStatus($rdo)),
                'progress' => $this->rdoCompletionPercent($rdo),
                'url' => route('tenant.diario-obra.rdo.show', [$tenant->slug, $rdo->id]),
            ])
            ->values();

        return Inertia::render('Tenant/Rdo/Dashboard', [
            'contracts' => $contracts,
            'filters' => [
                'contract_id' => $selectedContractId ?: null,
                'month' => $month->format('Y-m'),
            ],
            'dashboard' => [
                'cards' => [
                    'total' => $total,
                    'submitted' => $submitted,
                    'approved' => $approved,
                    'returned' => $rdos->where('status', 'devolvido_construtora')->count(),
                    'average_completion' => $total > 0 ? round($completionTotal / $total) : 0,
                ],
                'charts' => [
                    'status' => collect($statusCounts)
                        ->map(fn (int $value, string $key) => ['name' => $statusLabels[$key] ?? $key, 'value' => $value])
                        ->filter(fn (array $item) => $item['value'] > 0)
                        ->values(),
                    'daily' => array_values($daily),
                    'obras' => collect($obraStats)
                        ->map(fn (array $item) => [
                            'label' => $item['label'],
                            'total' => $item['total'],
                            'em_analise' => $item['em_analise'],
                            'aprovados' => $item['aprovados'],
                            'completion' => $item['total'] > 0 ? round($item['progress_sum'] / $item['total']) : 0,
                        ])
                        ->sortByDesc('total')
                        ->take(10)
                        ->values(),
                ],
                'recent' => $recent,
            ],
        ]);
    }

    public function calendar(Request $request, Tenant $tenant): Response
    {
        $filters = $request->validate([
            'contract_id' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $contracts = $this->contracts($tenant, $request->user(), DiarioObraPermissions::VIEW);
        $selectedContractId = (int) ($filters['contract_id'] ?? $contracts->first()?->id ?? 0);
        $obras = $this->obras($tenant, $selectedContractId);
        $month = CarbonImmutable::createFromFormat('Y-m', ! empty($filters['date_from'] ?? null) ? substr($filters['date_from'], 0, 7) : ($filters['month'] ?? now()->format('Y-m')))->startOfMonth();
        $dateFrom = ! empty($filters['date_from']) ? CarbonImmutable::parse($filters['date_from'])->startOfDay() : $month->startOfMonth();
        $dateTo = ! empty($filters['date_to']) ? CarbonImmutable::parse($filters['date_to'])->endOfDay() : $month->endOfMonth();
        if ($dateTo->lt($dateFrom)) {
            [$dateFrom, $dateTo] = [$dateTo->startOfDay(), $dateFrom->endOfDay()];
        }

        $configuration = RdoConfiguracao::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $selectedContractId)
            ->where('active', true)
            ->orderByDesc('id')
            ->first();

        $rdos = RdoDiario::query()
            ->with(['configuracao.obras:id,nome,codigo', 'secoes', 'signatureRequests', 'analises' => fn ($query) => $query->where('decisao', 'approve_with_reservations')->select(['id', 'rdo_diario_id', 'obra_id', 'decisao', 'detalhes'])])
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $selectedContractId)
            ->when($configuration, fn ($query) => $query->where('rdo_configuracao_id', $configuration->id))
            ->when(! $configuration, fn ($query) => $query->whereRaw('1 = 0'))
            ->whereBetween('reference_date', [$dateFrom, $dateTo])
            ->orderBy('reference_date')
            ->get()
            ->map(fn (RdoDiario $rdo): array => $this->rdoPayload($tenant, $rdo, $request->user()));

        return Inertia::render('Tenant/Rdo/Calendar', [
            'contracts' => $contracts,
            'obras' => $obras,
            'filters' => [
                'contract_id' => $selectedContractId ?: null,
                'month' => $month->format('Y-m'),
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
            ],
            'configuration' => $configuration ? [
                'id' => $configuration->id,
                'workflow' => $configuration->workflow ?? RdoWorkflow::DEFAULT,
                'pdf_model_name' => collect(\App\Support\RdoPdfTemplates::catalog())->firstWhere('value', \App\Support\RdoPdfTemplates::forContract($tenant, $selectedContractId)['layout'])['name'],
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
            'can_generate' => $selectedContractId > 0 && DiarioObraPermissions::can(
                $request->user(),
                $tenant,
                DiarioObraPermissions::SETTINGS,
                $contracts->firstWhere('id', $selectedContractId)
            ),
            'batch_download_url' => route('tenant.diario-obra.rdo.batch-pdf', [
                'tenant' => $tenant->slug,
                'contract_id' => $selectedContractId ?: null,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
            ]),
        ]);
    }

    public function batchPdf(Request $request, Tenant $tenant, RdoPdfRenderer $pdfRenderer)
    {
        abort_unless(class_exists(\ZipArchive::class), 500, 'A extensão ZIP do PHP não está habilitada.');

        $filters = $request->validate([
            'contract_id' => ['required', 'integer'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d'],
        ]);

        $dateFrom = CarbonImmutable::parse($filters['date_from'])->startOfDay();
        $dateTo = CarbonImmutable::parse($filters['date_to'])->endOfDay();
        if ($dateTo->lt($dateFrom)) {
            [$dateFrom, $dateTo] = [$dateTo->startOfDay(), $dateFrom->endOfDay()];
        }

        $rdos = RdoDiario::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', (int) $filters['contract_id'])
            ->whereBetween('reference_date', [$dateFrom, $dateTo])
            ->orderBy('reference_date')
            ->get();

        abort_if($rdos->isEmpty(), 404, 'Nenhum RDO encontrado para o período informado.');

        $zipPath = storage_path('app/tmp/rdo-lote-'.$tenant->id.'-'.now()->format('YmdHis').'.zip');
        if (! is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0775, true);
        }

        $zip = new \ZipArchive();
        abort_unless($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true, 500, 'Não foi possível criar o arquivo ZIP.');

        foreach ($rdos as $rdo) {
            $fileName = sprintf('%s-%s.pdf', preg_replace('/[^A-Za-z0-9_\-]/', '-', $rdo->code), $rdo->reference_date?->format('Ymd'));
            $zip->addFromString($fileName, $pdfRenderer->render($tenant, $rdo)->output());
        }

        $zip->close();

        $downloadName = sprintf('rdos-%s-a-%s.zip', $dateFrom->format('Ymd'), $dateTo->format('Ymd'));

        return response()->download($zipPath, $downloadName)->deleteFileAfterSend(true);
    }

    public function reopen(Request $request, Tenant $tenant, RdoDiario $rdo): RedirectResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        $rdo->loadMissing('configuracao');
        abort_unless($this->isRdoReopenable($rdo), 422, 'Este RDO não está apto para reabertura.');

        $timezone = $rdo->configuracao?->timezone ?: config('app.timezone');
        $reopenedUntil = CarbonImmutable::now($timezone)->addDay()->endOfDay();

        $rdo->update([
            'reopened_at' => now(),
            'reopened_until' => $reopenedUntil,
            'reopened_by_id' => $request->user()?->id,
        ]);

        return back()->with('success', 'RDO reaberto para preenchimento até '.$reopenedUntil->format('d/m/Y H:i').'.');
    }

    public function settings(Request $request, Tenant $tenant): Response
    {
        $contracts = $this->contracts($tenant, $request->user(), DiarioObraPermissions::SETTINGS);
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
            'documentConfiguration' => \App\Support\RdoPdfTemplates::forContract($tenant, $selectedContractId),
            'documentLogos' => $selectedContractId ? collect(app(\App\Services\RdoDocumentLogos::class)->current($tenant, $tenant->contracts()->findOrFail($selectedContractId)))->map(fn ($logo, $role) => $logo ? ['name' => $logo['name'] ?? '', 'url' => route('tenant.diario-obra.rdo.settings.logo', [$tenant->slug, $selectedContractId, $role])] : null) : [],
            'documentStyles' => \App\Support\RdoPdfTemplates::styles(),
            'documentTemplates' => \App\Support\RdoPdfTemplates::catalog(),
            'contracts' => $contracts,
            'obras' => $obras,
            'users' => $users,
            'reportCompanies' => $tenant->empresas()->where('contract_id', $selectedContractId)->orderBy('nome')->get(['id', 'nome', 'sigla']),
            'filters' => [
                'contract_id' => $selectedContractId ?: null,
                'obra_id' => $selectedObraId ?: null,
            ],
            'configuration' => $configuration ? [
                'id' => $configuration->id,
                'report_company_id' => $configuration->report_company_id,
                'workflow' => $configuration->workflow ?? RdoWorkflow::DEFAULT,
                'obra_ids' => $configuration->obras()->pluck('obras.id')->map(fn ($id) => (int) $id)->values(),
                'responsible_user_id' => $configuration->responsible_user_id,
                'start_date' => $configuration->start_date?->format('Y-m-d'),
                'end_date' => $configuration->end_date?->format('Y-m-d'),
                'generation_time' => RdoConfiguracao::GENERATION_TIME,
                'timezone' => $configuration->timezone,
                'generation_weekdays' => $configuration->generation_weekdays,
                'generate_on_holidays' => $configuration->generate_on_holidays,
                'copy_previous_day' => $configuration->copy_previous_day,
                'copy_workforce' => $configuration->copy_workforce,
                'copy_equipment' => $configuration->copy_equipment,
                'copy_pending_activities' => $configuration->copy_pending_activities,
                'require_photos' => $configuration->require_photos,
                'digital_signature_enabled' => $configuration->digital_signature_enabled,
                'submission_deadline_days' => $configuration->submission_deadline_days,
                'active' => $configuration->active,
            ] : null,
        ]);
    }

    public function previewDocument(Request $request, Tenant $tenant, \App\Services\RdoPdfPreview $preview, RdoPdfRenderer $renderer): HttpResponse
    {
        $data = $request->validate([
            ...\App\Services\RdoDocumentLogos::rules(),
            'contract_id' => ['required', 'integer'],
            'obra_id' => ['nullable', 'integer'],
            'model' => ['required', Rule::in(array_keys(RdoWorkflow::MODELS))],
            'layout' => ['required', Rule::in(array_keys(\App\Support\RdoPdfTemplates::VIEWS))],
            'title' => ['required', 'string', 'max:100'],
            'title_color' => ['sometimes', 'required', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'theme' => ['sometimes', 'required', Rule::in(array_keys(\App\Support\RdoPdfTemplates::STYLES))],
            'color' => [Rule::requiredIf(!$request->has('theme')), 'nullable', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'report_company_id' => ['nullable', 'integer', Rule::exists('empresas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->where('contract_id', $request->integer('contract_id')))],
            'require_photos' => ['sometimes', 'boolean'],
            'company_name' => ['nullable', 'string', 'max:200'],
        ]);
        $contract = $tenant->contracts()->with(['clienteEmpresa', 'gerenciadoraEmpresa', 'construtoraEmpresa'])->findOrFail($data['contract_id']);
        abort_unless(DiarioObraPermissions::can($request->user(), $tenant, DiarioObraPermissions::SETTINGS, $contract), 403);
        $obras = $tenant->obras()->where('contract_id', $contract->id);
        $obra = !empty($data['obra_id']) ? $obras->findOrFail($data['obra_id']) : $obras->first();
        if (!empty($data['report_company_id'])) $data['company_name'] = $tenant->empresas()->where('contract_id', $contract->id)->findOrFail($data['report_company_id'])->nome;
        if (!\App\Support\RdoPdfTemplates::supportsWorkflow($data['layout'], RdoWorkflow::forModel($data['model']))) throw ValidationException::withMessages(['layout' => 'Este layout utiliza o fluxo Gerenciadora + Cliente.']);
        $rdo = $preview->make($tenant, $contract, $data['model'], [...$data, 'logos' => app(\App\Services\RdoDocumentLogos::class)->apply($request, $tenant, $contract, false)], $obra, $request->user());
        $response = $renderer->render($tenant, $rdo, $preview)->stream('rdo-'.$data['model'].'.pdf');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    public function documentLogo(Request $request, Tenant $tenant, Contract $contract, string $role): HttpResponse
    {
        abort_unless((int) $contract->tenant_id === (int) $tenant->id, 404);
        abort_unless(DiarioObraPermissions::can($request->user(), $tenant, DiarioObraPermissions::SETTINGS, $contract), 403);
        abort_unless(array_key_exists($role, RdoWorkflow::PARTIES), 404);
        $service = app(\App\Services\RdoDocumentLogos::class);
        $bytes = $service->bytes($service->current($tenant, $contract)[$role] ?? null);
        abort_unless($bytes, 404);
        return response($bytes, 200, ['Content-Type' => getimagesizefromstring($bytes)['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function saveSettings(Request $request, Tenant $tenant, RdoDailyGenerator $generator): RedirectResponse
    {
        $validated = $request->validate([
            ...\App\Services\RdoDocumentLogos::rules(),
            'workflow' => ['sometimes', 'required', 'array:model,fill_party,fill_parties,review_parties,client_comment_only'],
            'workflow.model' => ['sometimes', 'required', Rule::in(array_keys(RdoWorkflow::MODELS))],
            'document_configuration' => ['sometimes', 'required', 'array:layout,title,color,title_color,company_name,theme'],
            'document_configuration.layout' => ['required_with:document_configuration', Rule::in(array_keys(\App\Support\RdoPdfTemplates::VIEWS))],
            'document_configuration.company_name' => ['nullable', 'string', 'max:200'],
            'document_configuration.title' => ['required_with:document_configuration', 'string', 'max:100'],
            'document_configuration.title_color' => ['sometimes', 'required', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'document_configuration.theme' => ['sometimes', 'required', Rule::in(array_keys(\App\Support\RdoPdfTemplates::STYLES))],
            'document_configuration.color' => [Rule::requiredIf($request->has('document_configuration') && !$request->has('document_configuration.theme')), 'nullable', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'workflow.fill_party' => [Rule::requiredIf($request->has('workflow') && !$request->has('workflow.fill_parties') && !$request->has('workflow.model')), Rule::in(RdoWorkflow::FILLING_PARTIES)],
            'workflow.fill_parties' => ['sometimes', 'required', 'array', 'min:1', 'max:2'],
            'workflow.fill_parties.*' => ['required', 'string', 'distinct', Rule::in(RdoWorkflow::FILLING_PARTIES)],
            'workflow.client_comment_only' => ['sometimes', 'boolean'],
            'workflow.review_parties' => [$request->has('workflow') && !$request->has('workflow.model') ? 'present' : 'sometimes', 'array', 'max:2'],
            'workflow.review_parties.*' => ['required', 'string', 'distinct', Rule::in(array_keys(RdoWorkflow::PARTIES)), Rule::notIn($request->has('workflow.fill_parties') ? [] : [$request->input('workflow.fill_party')])],
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
            'report_company_id' => ['nullable', 'integer', Rule::exists('empresas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->where('contract_id', $request->integer('contract_id')))],
            'responsible_user_id' => [
                'nullable',
                'integer',
                Rule::exists('tenant_users', 'user_id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'active')),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'timezone' => ['required', 'timezone'],
            'generation_weekdays' => ['required', 'array', 'min:1'],
            'generation_weekdays.*' => ['required', 'integer', 'between:0,6', 'distinct'],
            'generate_on_holidays' => ['sometimes', 'boolean'],
            'copy_previous_day' => ['required', 'boolean'],
            'copy_workforce' => ['required', 'boolean'],
            'copy_equipment' => ['required', 'boolean'],
            'copy_pending_activities' => ['required', 'boolean'],
            'require_photos' => ['required', 'boolean'],
            'digital_signature_enabled' => ['required', 'boolean'],
            'submission_deadline_days' => ['required', 'integer', 'min:1', 'max:365'],
            'active' => ['required', 'boolean'],
        ]);

        $validated['generation_weekdays'] = array_map('intval', $validated['generation_weekdays']);

        if (isset($validated['workflow']['model'])) {
            $validated['workflow'] = RdoWorkflow::forModel($validated['workflow']['model']);
        } elseif (isset($validated['workflow']['fill_parties'])) {
            $validated['workflow']['client_comment_only'] = true;
        }

        $layout = $validated['document_configuration']['layout'] ?? \App\Support\RdoPdfTemplates::forContract($tenant, $validated['contract_id'])['layout'];
        if (!\App\Support\RdoPdfTemplates::supportsWorkflow($layout, $validated['workflow'] ?? RdoWorkflow::DEFAULT)) throw ValidationException::withMessages(['document_configuration.layout' => 'Este layout utiliza o fluxo Gerenciadora + Cliente.']);

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
            ->except(['configuration_id', 'obra_ids', 'document_configuration', ...collect(array_keys(RdoWorkflow::PARTIES))->flatMap(fn ($role) => ['logo_'.$role, 'remove_logo_'.$role])->all()])
            ->merge([
                'obra_id' => $primaryObraId,
                'created_by_id' => $request->user()?->id,
                'generation_time' => RdoConfiguracao::GENERATION_TIME,
            ])
            ->all();

        DB::transaction(function () use (&$configuration, $configurationData, $validated, $tenant, $request, $generator): void {
            $tenant->contracts()->whereKey($validated['contract_id'])->lockForUpdate()->firstOrFail();
            if ($configuration) {
                $configuration->update($configurationData);
            } else {
                $configuration = RdoConfiguracao::create([
                    'tenant_id' => $tenant->id,
                    ...$configurationData,
                ]);
            }

            if (isset($validated['document_configuration'])) {
                $contract = $tenant->contracts()->findOrFail($validated['contract_id']);
                $logoService = app(\App\Services\RdoDocumentLogos::class);
                $logoService->freezeContract($tenant, $contract);
                $validated['document_configuration']['logos'] = $logoService->apply($request, $tenant, $contract, true);
                \App\Models\RdoPdfSetting::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'contract_id' => $validated['contract_id']],
                    ['configuration' => \App\Support\RdoPdfTemplates::configuration($validated['document_configuration'])],
                );
            }
            $configuration->obras()->sync($validated['obra_ids']);

            $today = CarbonImmutable::now($configuration->timezone)->startOfDay();
            if ($configuration->active && $today->betweenIncluded($configuration->start_date, $configuration->end_date ?? $today)) {
                $generator->generateForConfiguration($configuration, $today, false, $request->user()?->id);
            }

        });

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
                RdoSecaoRegistro::updateOrCreate(
                    ['rdo_diario_id' => $rdo->id, 'obra_id' => $section->obra_id, 'secao' => $section->secao, 'filling_party' => $section->filling_party ?? 'construtora'],
                    ['tenant_id' => $tenant->id, 'updated_by_id' => $request->user()?->id, 'dados' => $section->dados],
                );
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
            'versoes.createdBy:id,name,email',
            'fiscalizacaoRegistros.user:id,name,email',
            'fiscalizacaoRegistros.empresa:id,nome,sigla',
            'fiscalizacaoRegistros.obra:id,nome,codigo',
            'divergencias.obra:id,nome,codigo',
            'divergencias.createdBy:id,name,email',
            'divergencias.respondedBy:id,name,email',
            'divergencias.concludedBy:id,name,email',
            'divergencias.clientDecidedBy:id,name,email',
            'signatureRequests.signers',
        ]);
        $capabilities = $this->flowCapabilities($tenant, $rdo, request()->user());

        $referencedIds = function (string $section, string $key) use ($rdo) {
            return $rdo->secoes->where('secao', $section)->flatMap(fn ($row) => array_keys($row->dados[$key] ?? []))->unique()->values()->all();
        };
        $historical = function (string $model, array $ids, array $columns) use ($tenant, $rdo) {
            return $model::withTrashed()->where('tenant_id', $tenant->id)
                ->where(fn ($q) => $q->where('contract_id', $rdo->contract_id)->orWhereNull('contract_id'))
                ->whereIn('id', $ids)->get($columns);
        };

        return Inertia::render('Tenant/Rdo/Show', [
            'rdo' => $this->rdoPayload($tenant, $rdo, request()->user(), $capabilities['filling_party']) + [
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
                'manager_forward_ready' => $capabilities['manager_forward_ready'] ?? false,
                'active_stage' => $capabilities['active_stage'],
                'can_manage_inspection' => $capabilities['can_manage_inspection'],
                'can_create_divergence' => $capabilities['can_create_divergence'],
                'can_respond_divergence' => $capabilities['can_respond_divergence'],
                'can_conclude_divergence' => $capabilities['can_conclude_divergence'],
                'can_decide_divergence' => $capabilities['can_decide_divergence'],
                'approval_comments' => $this->approvalCommentsByStage($rdo),
                'inspection_records' => $this->inspectionPayload($tenant, $rdo),
                'divergences' => $this->divergencePayload($rdo),
                'versions' => $this->versionPayload($rdo),
                'digital_signature_enabled' => (bool) ($rdo->configuracao?->digital_signature_enabled ?? true),
                'can_manage_signatures' => DiarioObraPermissions::can(
                    request()->user(),
                    $tenant,
                    DiarioObraPermissions::SIGNATURES,
                    $rdo->contract
                ),
                'signature' => $this->signaturePayload($tenant, $rdo),
                'sections_by_party' => $rdo->secoes->groupBy('filling_party')->map(fn ($records) => $records->groupBy('obra_id')->map(fn ($items) => $items->mapWithKeys(fn ($row) => [$row->secao => $row->dados]))),
                'sections' => RdoWorkflow::sections($rdo, $capabilities['filling_party'])
                    ->groupBy('obra_id')
                    ->map(fn ($sections) => $sections->mapWithKeys(fn (RdoSecaoRegistro $section) => [
                        $section->secao => $section->dados,
                    ])),
            ],
            'record_catalogs' => [
                'mao_obra' => $historical(RdoMaoObraCadastro::class, $referencedIds('mao_obra', 'efetivos'), ['id', 'descricao', 'tipo', 'unidade']),
                'equipamentos' => $historical(RdoEquipamentoCadastro::class, $referencedIds('equipamentos', 'registros'), ['id', 'descricao', 'codigo', 'unidade']),
                'subcontratadas' => $historical(RdoSubcontratadaCadastro::class, $referencedIds('mao_obra', 'subcontratadas'), ['id', 'razao_social', 'nome_fantasia']),
            ],
            'catalogs' => [
                'mao_obra' => RdoMaoObraCadastro::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $rdo->contract_id)
                    ->where('active', true)
                    ->orderBy('tipo')
                    ->orderBy('descricao')
                    ->get(['id', 'descricao', 'tipo', 'unidade']),
                'equipamentos' => RdoEquipamentoCadastro::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $rdo->contract_id)
                    ->where('active', true)
                    ->orderBy('descricao')
                    ->get(['id', 'codigo', 'descricao', 'unidade', 'propriedade']),
                'subcontratadas' => RdoSubcontratadaCadastro::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $rdo->contract_id)
                    ->where('active', true)
                    ->orderBy('razao_social')
                    ->get(['id', 'razao_social', 'nome_fantasia']),
            ],
        ]);
    }

    public function pdf(Request $request, Tenant $tenant, RdoDiario $rdo, RdoPdfRenderer $pdfRenderer): HttpResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);

        $rdo->loadMissing('configuracao.obras');
        $parties = RdoWorkflow::parties($rdo);
        $obras = $rdo->configuracao?->obras ?? collect([$rdo->obra])->filter();
        $export = $request->validate([
            'stage' => ['sometimes', 'required', Rule::in($parties)],
            'obra_ids' => ['sometimes', 'required', 'array', 'min:1'],
            'obra_ids.*' => ['required', 'integer', 'distinct', Rule::in($obras->pluck('id')->all())],
        ]);
        $fileName = sprintf('rdo-%s-%s%s.pdf', $rdo->code, $rdo->reference_date?->format('Ymd'), isset($export['stage']) ? '-ate-'.$export['stage'] : '');

        if ($request->boolean('viewer')) {
            $pdfUrl = route('tenant.diario-obra.rdo.pdf', [$tenant->slug, $rdo->id]);
            if ($export) $pdfUrl .= '?'.http_build_query($export);
            return response()->view('pdf.viewer', ['title' => $rdo->code.' · RDO', 'pdfUrl' => $pdfUrl])
                ->header('Cache-Control', 'private, no-store');
        }

        return $pdfRenderer->render($tenant, $rdo, null, $export)->stream($fileName);
    }

    public function history(Request $request, Tenant $tenant, RdoDiario $rdo): JsonResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        $limit = min(max($request->integer('limit', 8), 5), 20);

        $rdo->loadMissing(['responsible:id,name,email', 'copiedFrom:id,code,reference_date']);

        $events = collect([
            [
                'id' => 'rdo-created-'.$rdo->id,
                'type' => 'created',
                'tone' => 'neutral',
                'title' => $rdo->generated_automatically ? 'RDO gerado automaticamente' : 'RDO criado manualmente',
                'description' => $rdo->copiedFrom
                    ? 'Dados copiados de '.$rdo->copiedFrom->code.' - '.$rdo->copiedFrom->reference_date?->format('d/m/Y')
                    : null,
                'actor' => $rdo->responsible?->name,
                'company' => null,
                'obra' => null,
                'comment' => null,
                'created_at' => $rdo->created_at,
            ],
        ]);

        $analysisEvents = RdoAnalise::query()
            ->with(['user:id,name,email', 'empresa:id,nome,sigla', 'obra:id,codigo,nome'])
            ->where('tenant_id', $tenant->id)
            ->where('rdo_diario_id', $rdo->id)
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (RdoAnalise $analysis) => [
                'id' => 'analysis-'.$analysis->id,
                'type' => 'flow',
                'tone' => match ($analysis->decisao) {
                    'approve' => 'success',
                    'approve_with_reservations' => 'warning',
                    'return' => 'danger',
                    default => 'primary',
                },
                'title' => $this->stageLabel($analysis->etapa).' · '.$this->decisionLabel($analysis->decisao, $analysis->etapa === 'cliente' && RdoWorkflow::clientCommentOnly($rdo)),
                'description' => $analysis->status_anterior !== $analysis->status_novo
                    ? RdoWorkflow::statusLabel($rdo, $analysis->status_anterior).' → '.RdoWorkflow::statusLabel($rdo, $analysis->status_novo)
                    : null,
                'actor' => $analysis->user?->name,
                'company' => $analysis->empresa?->nome,
                'obra' => $analysis->obra ? "{$analysis->obra->codigo} - {$analysis->obra->nome}" : null,
                'comment' => $analysis->comentario,
                'created_at' => $analysis->created_at,
            ]);

        $signatureEvents = $rdo->signatureRequests()
            ->with(['requestedBy:id,name,email', 'signers'])
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->flatMap(function ($signature) {
                $items = collect();

                $items->push([
                    'id' => 'signature-request-'.$signature->id,
                    'type' => 'signature',
                    'tone' => $signature->status === 'failed' ? 'danger' : 'primary',
                    'title' => 'RDO enviado para assinatura',
                    'description' => $signature->provider ? 'Provedor: '.ucfirst($signature->provider) : null,
                    'actor' => $signature->requestedBy?->name,
                    'company' => null,
                    'obra' => null,
                    'comment' => $signature->error_message,
                    'created_at' => $signature->sent_at ?: $signature->created_at,
                ]);

                foreach ($signature->signers as $signer) {
                    if (! $signer->signed_at) {
                        continue;
                    }

                    $items->push([
                        'id' => 'signature-signer-'.$signer->id,
                        'type' => 'signature',
                        'tone' => 'success',
                        'title' => $this->stageLabel($signer->role).' assinou o RDO',
                        'description' => $signer->email,
                        'actor' => $signer->name,
                        'company' => null,
                        'obra' => null,
                        'comment' => null,
                        'created_at' => $signer->signed_at,
                    ]);
                }

                if ($signature->completed_at) {
                    $items->push([
                        'id' => 'signature-completed-'.$signature->id,
                        'type' => 'signature',
                        'tone' => $signature->signed_pdf_path ? 'success' : 'warning',
                        'title' => $signature->signed_pdf_path ? 'PDF assinado disponível' : 'Assinatura concluída',
                        'description' => $signature->signed_pdf_path ? 'Documento final pronto para download.' : 'Aguardando arquivo final do provedor.',
                        'actor' => null,
                        'company' => null,
                        'obra' => null,
                        'comment' => null,
                        'created_at' => $signature->completed_at,
                    ]);
                }

                return $items;
            });

        $versionEvents = RdoVersao::query()
            ->with('createdBy:id,name,email')
            ->where('tenant_id', $tenant->id)
            ->where('rdo_diario_id', $rdo->id)
            ->latest('version_number')
            ->limit($limit)
            ->get()
            ->map(fn (RdoVersao $version) => [
                'id' => 'version-'.$version->id,
                'type' => 'version',
                'tone' => 'primary',
                'title' => 'Versão V'.str_pad((string) $version->version_number, 2, '0', STR_PAD_LEFT).' apresentada',
                'description' => $version->trigger === 'initial_submission' ? 'Envio inicial — '.RdoWorkflow::PARTIES[RdoWorkflow::fillParty($rdo)] : 'Reenvio após ajustes ou comprovações',
                'actor' => $version->createdBy?->name,
                'company' => null,
                'obra' => null,
                'comment' => null,
                'created_at' => $version->submitted_at ?: $version->created_at,
            ]);
        $inspectionEvents = RdoFiscalizacaoRegistro::query()
            ->with(['user:id,name,email', 'empresa:id,nome,sigla', 'obra:id,codigo,nome'])
            ->where('tenant_id', $tenant->id)
            ->where('rdo_diario_id', $rdo->id)
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (RdoFiscalizacaoRegistro $record) => [
                'id' => 'inspection-'.$record->id,
                'type' => 'inspection',
                'tone' => $record->status === 'concluido' ? 'success' : 'warning',
                'title' => 'Fiscalização V'.str_pad((string) $record->version_number, 2, '0', STR_PAD_LEFT).' · '.($record->status === 'concluido' ? 'Concluída' : 'Rascunho'),
                'description' => $record->resumo_fiscalizacao,
                'actor' => $record->user?->name,
                'company' => $record->empresa?->nome,
                'obra' => $record->obra ? "{$record->obra->codigo} - {$record->obra->nome}" : null,
                'comment' => $record->orientacoes,
                'created_at' => $record->submitted_at ?: $record->updated_at,
            ]);
        $divergenceEvents = RdoDivergencia::query()
            ->with(['createdBy:id,name,email', 'obra:id,codigo,nome'])
            ->where('tenant_id', $tenant->id)
            ->where('rdo_diario_id', $rdo->id)
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (RdoDivergencia $item) => [
                'id' => 'divergence-'.$item->id,
                'type' => 'divergence',
                'tone' => in_array($item->status, ['resolvida', 'decidida_cliente'], true) ? 'success' : 'warning',
                'title' => 'Divergência · '.$item->titulo,
                'description' => $item->topico.' · '.$this->divergenceStatusLabel($item->status),
                'actor' => $item->createdBy?->name,
                'company' => null,
                'obra' => $item->obra ? "{$item->obra->codigo} - {$item->obra->nome}" : null,
                'comment' => $item->comentario_cliente ?: $item->conclusao_gerenciadora ?: $item->resposta_construtora,
                'created_at' => $item->updated_at,
            ]);

        $events = $events
            ->merge($analysisEvents)
            ->merge($versionEvents)
            ->merge($inspectionEvents)
            ->merge($divergenceEvents)
            ->merge($signatureEvents)
            ->filter(fn (array $event) => $event['created_at'] !== null)
            ->sortByDesc('created_at')
            ->values();
        $totalEvents = $events->count();
        $events = $events
            ->take($limit)
            ->map(fn (array $event) => [
                ...$event,
                'created_at' => $event['created_at']?->format('d/m/Y H:i'),
                'date_group' => $event['created_at']?->isToday()
                    ? 'Hoje'
                    : ($event['created_at']?->isYesterday() ? 'Ontem' : $event['created_at']?->format('d/m/Y')),
            ]);

        return response()->json([
            'events' => $events->values(),
            'total' => $totalEvents,
            'limit' => $limit,
        ]);
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
        $this->ensureFillWindowOpen($rdo, $request->headers->has('X-Inertia'));

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
        if ($secao === 'atividades') \Illuminate\Support\Facades\Validator::make(['dados' => $dados], ['dados.atividades.*.assunto' => ['sometimes', 'string', Rule::in(array_keys(\App\Support\RdoPdfTemplates::ACTIVITY_SUBJECTS))]])->validate();
        \App\Support\RdoCatalogs::validate($rdo, [$secao => $dados]);
        if ($secao === 'fotos') {
            $dados = $this->preparePhotoSectionData(
                $dados,
                $request->file('fotos', []),
                $tenant,
                $rdo,
                (int) $validated['obra_id'],
                $capabilities['filling_party'],
            );
        }

        DB::transaction(function () use ($rdo, $validated, $secao, $dados, $capabilities, $tenant, $request) {
            $this->lockCurrentDiary($rdo, true);
            RdoSecaoRegistro::updateOrCreate(
                ['rdo_diario_id' => $rdo->id, 'obra_id' => $validated['obra_id'], 'secao' => $secao, 'filling_party' => $capabilities['filling_party']],
                [
                    'tenant_id' => $tenant->id,
                    'updated_by_id' => $request->user()?->id,
                    'dados' => $dados,
                ],
            );
            if (!$this->savingWorksBatch) $this->completeSoloRdo($tenant, $rdo, $request->user());
        });

        return back()->with('success', 'Seção do RDO salva com sucesso.');
    }

    public function saveAllSections(Request $request, Tenant $tenant, RdoDiario $rdo): RedirectResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        $capabilities = $this->flowCapabilities($tenant, $rdo, $request->user());
        abort_unless($capabilities['can_edit'] && in_array((int) $request->input('obra_id'), $capabilities['editable_obra_ids'], true), 403);
        $this->ensureFillWindowOpen($rdo, $request->headers->has('X-Inertia'));

        $validated = $request->validate([
            'obra_id' => ['required', 'integer'],
            'is_holiday' => ['sometimes', 'nullable', 'boolean'],
            'secoes' => ['required', 'array'],
            'secoes.clima' => ['nullable', 'array'],
            'secoes.mao_obra' => ['nullable', 'array'],
            'secoes.equipamentos' => ['nullable', 'array'],
            'secoes.atividades' => ['nullable', 'array'],
            'secoes.fotos' => ['nullable', 'array'],
            'secoes.comentarios' => ['nullable', 'array'],
            'fotos' => ['nullable', 'array', 'max:20'],
            'fotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ]);

        $obraPermitida = $rdo->configuracao()
            ->whereHas('obras', fn ($query) => $query->whereKey($validated['obra_id']))
            ->exists();
        abort_unless($obraPermitida, 422, 'A obra selecionada não faz parte deste RDO.');

        $sections = array_replace([
            'clima' => [],
            'mao_obra' => [],
            'equipamentos' => [],
            'atividades' => [],
            'fotos' => [],
            'comentarios' => [],
        ], $validated['secoes']);
        \Illuminate\Support\Facades\Validator::make(['secoes' => $sections], ['secoes.atividades.atividades.*.assunto' => ['sometimes', 'string', Rule::in(array_keys(\App\Support\RdoPdfTemplates::ACTIVITY_SUBJECTS))]])->validate();
        \App\Support\RdoCatalogs::validate($rdo, $sections);
        $sections['fotos'] = $this->preparePhotoSectionData(
            $sections['fotos'],
            $request->file('fotos', []),
            $tenant,
            $rdo,
            (int) $validated['obra_id'],
            $capabilities['filling_party'],
        );

        DB::transaction(function () use ($tenant, $rdo, $request, $validated, $sections, $capabilities): void {
            $this->lockCurrentDiary($rdo, true);
            if (array_key_exists('is_holiday', $validated)) $rdo->update(['is_holiday' => $validated['is_holiday']]);
            foreach ($sections as $section => $data) {
                RdoSecaoRegistro::updateOrCreate(
                    ['rdo_diario_id' => $rdo->id, 'obra_id' => $validated['obra_id'], 'secao' => $section, 'filling_party' => $capabilities['filling_party']],
                    [
                        'tenant_id' => $tenant->id,
                        'updated_by_id' => $request->user()?->id,
                        'dados' => $data,
                    ],
                );
            }
            if (!$this->savingWorksBatch) $this->completeSoloRdo($tenant, $rdo, $request->user());
        });

        return back()->with('success', RdoWorkflow::savesWithoutApproval($rdo) && $rdo->status === 'arquivado' ? 'RDO salvo sem fluxo de aprovação.' : 'Preenchimento completo da obra salvo com sucesso.');
    }

    public function changeFlow(Request $request, Tenant $tenant, RdoDiario $rdo): RedirectResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        $rdo->loadMissing(['contract', 'configuracao.obras', 'secoes']);
        $requiredPermission = $request->input('action') === 'submit'
            ? DiarioObraPermissions::FILL_RDO
            : DiarioObraPermissions::REVIEW_RDO;
        abort_unless(DiarioObraPermissions::can($request->user(), $tenant, $requiredPermission, $rdo->contract), 403);
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
            'return_to' => [Rule::requiredIf(fn () => $capabilities['active_stage'] === 'cliente' && $request->input('action') === 'return'), 'nullable', Rule::in(array_values(array_unique([...RdoWorkflow::fillParties($rdo), ...array_intersect(['gerenciadora'], RdoWorkflow::reviews($rdo))])))],
            'comment' => ['nullable', 'string', 'max:5000'],
            'comments_by_obra' => ['nullable', 'array'],
            'comments_by_obra.*' => ['nullable', 'string', 'max:5000'],
            'reservations_by_obra' => ['nullable', 'array'],
            'reservation_obra_ids' => ['sometimes', 'array'],
            'reservation_obra_ids.*' => ['integer', 'distinct', Rule::in($capabilities['flow_obra_ids'])],
            'reservations_by_obra.*.obligation' => ['nullable', 'string', 'max:5000'],
            'reservations_by_obra.*.responsible' => ['nullable', 'string', 'max:180'],
            'reservations_by_obra.*.due_date' => ['nullable', 'date'],
            'obra_ids' => ['nullable', 'array', 'min:1'],
            'obra_ids.*' => ['integer', Rule::in($capabilities['flow_obra_ids'])],
            'divergencia' => ['nullable', 'array'],
            'divergencia.obra_id' => ['nullable', 'integer', Rule::in($capabilities['flow_obra_ids'])],
            'divergencia.topico' => ['required_with:divergencia', 'string', 'max:80'],
            'divergencia.titulo' => ['required_with:divergencia', 'string', 'max:180'],
            'divergencia.versao_construtora' => ['required_with:divergencia', 'string', 'max:10000'],
            'divergencia.versao_gerenciadora' => ['required_with:divergencia', 'string', 'max:5000'],
        ]);
        $action = $validated['action'];
        $returnTo = $validated['return_to'] ?? null;
        $comment = trim((string) ($validated['comment'] ?? ''));
        $commentsByObra = collect($validated['comments_by_obra'] ?? [])
            ->mapWithKeys(fn ($value, $key) => [(int) $key => trim((string) $value)])
            ->all();
        $reservationsByObra = collect($validated['reservations_by_obra'] ?? [])
            ->mapWithKeys(fn ($value, $key) => [(int) $key => [
                'obligation' => trim((string) ($value['obligation'] ?? '')),
                'responsible' => trim((string) ($value['responsible'] ?? '')),
                'due_date' => $value['due_date'] ?? null,
            ]])
            ->all();
        abort_unless(isset($capabilities['actions'][$action]), 403);

        $stage = $capabilities['active_stage'];
        $oldStatus = $rdo->status;
        $obraIds = array_values(array_map('intval', $validated['obra_ids'] ?? $capabilities['flow_obra_ids']));
        $divergence = $validated['divergencia'] ?? null;
        if ($divergence && ($action !== 'return' || !$capabilities['can_create_divergence'])) {
            throw ValidationException::withMessages(['divergencia' => 'A divergência deve acompanhar a devolução do RDO pela gerenciadora.']);
        }
        if ($divergence && ((!isset($divergence['obra_id']) && count($obraIds) > 1) || (isset($divergence['obra_id']) && !in_array((int) $divergence['obra_id'], $obraIds, true)))) {
            throw ValidationException::withMessages(['divergencia.obra_id' => 'Informe a obra relacionada à divergência. Todas as obras do RDO serão devolvidas juntas.']);
        }
        if ($action === 'submit') {
            $obraIds = array_values(array_map('intval', $capabilities['flow_obra_ids']));
        }
        if (RdoWorkflow::isReview($rdo->status) && in_array($action, ['approve', 'approve_with_reservations', 'return'], true)) {
            $allPending = $this->pendingObraIds($rdo, $stage, $rdo->configuracao->obras->pluck('id')->map(fn ($id) => (int) $id)->all());
            if (array_diff($allPending, $obraIds) || array_diff($obraIds, $allPending)) {
                throw ValidationException::withMessages(['obra_ids' => 'Encaminhe ou devolva todas as obras do RDO juntas. Não é permitido movimentar apenas parte das obras.']);
            }
            if ($stage === 'gerenciadora' && $action !== 'return') $this->ensureInspectionCompleted($rdo, $allPending);
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
        $isReviewStage = RdoWorkflow::isReview($rdo->status);
        $requiresComment = $isReviewStage
            || in_array($action, ['approve_with_reservations', 'return'], true)
            || ($action === 'submit' && $rdo->status !== 'rascunho');
        if ($requiresComment) {
            $missingCommentObraIds = $isReviewStage
                ? array_values(array_filter($obraIds, fn (int $obraId) => ($commentsByObra[$obraId] ?? '') === ''))
                : [];

            if ($request->headers->has('X-Inertia') && ($isReviewStage ? ! empty($missingCommentObraIds) : $comment === '')) {
                throw ValidationException::withMessages([
                    $isReviewStage ? 'comments_by_obra' : 'comment' => $isReviewStage
                        ? 'Informe o parecer das frentes selecionadas para continuar.'
                        : 'Informe o comentário ou a resposta para continuar.',
                ]);
            }

            abort_if($isReviewStage ? ! empty($missingCommentObraIds) : $comment === '', 422, 'Informe o comentário ou a resposta para continuar.');
        }
        $reservationObraIds = $action === 'approve_with_reservations' ? array_map('intval', $validated['reservation_obra_ids'] ?? $obraIds) : [];
        if ($action === 'approve_with_reservations') {
            if (!$reservationObraIds || array_diff($reservationObraIds, $obraIds)) throw ValidationException::withMessages(['reservation_obra_ids' => 'Selecione as obras que possuem ressalvas.']);
            $missingReservationObraIds = array_values(array_filter($reservationObraIds, function (int $obraId) use ($reservationsByObra): bool {
                $reservation = $reservationsByObra[$obraId] ?? [];

                return empty($reservation['obligation']) || empty($reservation['responsible']) || empty($reservation['due_date']);
            }));
            if ($missingReservationObraIds) {
                throw ValidationException::withMessages([
                    'reservations_by_obra' => 'Informe obrigação, responsável e prazo para cada frente aprovada com ressalvas.',
                ]);
            }
        }
        if ($stage === 'gerenciadora' && in_array($action, ['approve', 'approve_with_reservations'], true)) {
            $this->ensureInspectionCompleted($rdo, $obraIds);
            $this->ensureManagerDivergencesResolved($rdo);
        }
        if ($stage === 'cliente' && !RdoWorkflow::clientCommentOnly($rdo) && in_array($action, ['approve', 'approve_with_reservations'], true)) {
            $this->ensureClientDivergencesDecided($rdo);
        }
        abort_if(empty($obraIds), 422, 'Não há frentes pendentes atribuídas a este usuário nesta etapa.');
        $membership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->first();

        [$newStatus, $message] = DB::transaction(function () use ($tenant, $rdo, $request, $membership, $stage, $action, $comment, $commentsByObra, $reservationsByObra, $oldStatus, $obraIds, $divergence, $returnTo, $reservationObraIds): array {
            $this->lockCurrentDiary($rdo);
            if ($stage === 'gerenciadora' && in_array($action, ['approve', 'approve_with_reservations'], true)) {
                $this->ensureInspectionCompleted($rdo, $obraIds);
                $this->ensureManagerDivergencesResolved($rdo);
            }
            if ($divergence) {
                $currentCapabilities = $this->flowCapabilities($tenant, $rdo, $request->user());
                abort_unless($rdo->status === $oldStatus && $currentCapabilities['can_create_divergence'] && in_array($obraIds[0], $currentCapabilities['flow_obra_ids'], true), 422, 'Esta frente não está mais disponível para devolução. Atualize a página.');
                RdoDivergencia::create([
                    'topico' => $divergence['topico'], 'titulo' => $divergence['titulo'],
                    'versao_construtora' => $divergence['versao_construtora'], 'versao_gerenciadora' => $divergence['versao_gerenciadora'],
                    'tenant_id' => $tenant->id, 'rdo_diario_id' => $rdo->id,
                    'obra_id' => $divergence['obra_id'] ?? $obraIds[0], 'created_by_id' => $request->user()->id,
                    'version_number' => max(1, (int) $rdo->current_version), 'status' => 'aberta',
                ]);
            }
            if ($action === 'submit') {
                $rdo->unsetRelation('secoes')->load('secoes');
                $this->ensureReadyForSubmission($rdo, $obraIds, $request->headers->has('X-Inertia'));
            }
            if ($action === 'submit') {
                $nextVersion = (int) $rdo->current_version + 1;
                $rdo->update(['current_version' => $nextVersion]);
                $this->createSubmissionSnapshot($tenant, $rdo, $request->user(), $oldStatus, $nextVersion);
            }
            $rdoVersion = max(1, (int) $rdo->current_version);
            $analysisIds = [];
            foreach ($obraIds as $obraId) {
                $obraComment = RdoWorkflow::isReview($rdo->status)
                    ? ($commentsByObra[$obraId] ?? '')
                    : $comment;

                $analysisIds[] = RdoAnalise::create([
                    'tenant_id' => $tenant->id,
                    'rdo_diario_id' => $rdo->id,
                    'obra_id' => $obraId,
                    'user_id' => $request->user()->id,
                    'empresa_id' => $membership?->empresa_id,
                    'etapa' => $stage,
                    'decisao' => $action === 'approve_with_reservations' && !in_array($obraId, $reservationObraIds, true) ? 'approve' : $action,
                    'comentario' => $obraComment !== '' ? $obraComment : null,
                    'detalhes' => [
                        'rdo_version' => $rdoVersion,
                        'return_to' => $action === 'return' ? $returnTo : null,
                        'reservation' => $action === 'approve_with_reservations' && in_array($obraId, $reservationObraIds, true)
                            ? ($reservationsByObra[$obraId] ?? null)
                            : null,
                    ],
                    'status_anterior' => $oldStatus,
                    'status_novo' => $oldStatus,
                ])->id;
            }

            [$nextStatus, $flowMessage] = $stage === 'cliente' && $action === 'return' && $returnTo === 'gerenciadora' && in_array('gerenciadora', RdoWorkflow::reviews($rdo), true)
                ? ['em_analise_gerenciadora', 'RDO devolvido integralmente para revisão da gerenciadora.']
                : $this->resolveStatusAfterDecisions($rdo, $stage, $action);
            if ($nextStatus !== $oldStatus) {
                RdoAnalise::query()
                    ->whereIn('id', $analysisIds)
                    ->update(['status_novo' => $nextStatus]);
            }
            $rdo->update([
                'status' => $nextStatus,
                'submitted_at' => $action === 'submit' ? now() : $rdo->submitted_at,
                'approved_at' => $nextStatus === 'arquivado' ? now() : null,
            ]);

            return [$nextStatus, $flowMessage];
        });

        $this->notifyFlowRecipients($tenant, $rdo->fresh('contract'), $request->user(), $newStatus, $message);

        return back()->with('success', $message);
    }

    public function saveInspection(Request $request, Tenant $tenant, RdoDiario $rdo): RedirectResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        $rdo->loadMissing(['contract', 'configuracao.obras']);
        $capabilities = $this->flowCapabilities($tenant, $rdo, $request->user());
        abort_unless($capabilities['can_manage_inspection'], 403);
        $validated = $request->validate([
            'obra_id' => ['required', 'integer', Rule::in($capabilities['flow_obra_ids'])],
            'resumo_fiscalizacao' => ['required_without:atividades', 'nullable', 'string', 'max:10000'],
            'atividades' => ['sometimes', 'array', 'min:1', 'max:50'],
            'atividades.*.titulo' => ['required', 'string', 'max:255'],
            'atividades.*.ocorrencia' => ['required', 'string', 'max:10000'],
            'comentario' => ['nullable', 'string', 'max:10000'],
            'clima' => ['nullable', 'array:manha,tarde,noite,precipitacao_manha_mm,precipitacao_tarde_mm,precipitacao_noite_mm,observacoes,dia_impraticavel'],
            'clima.manha' => ['nullable', Rule::in(['ensolarado', 'nublado', 'chuvoso', 'nao_aplicavel'])],
            'clima.tarde' => ['nullable', Rule::in(['ensolarado', 'nublado', 'chuvoso', 'nao_aplicavel'])],
            'clima.noite' => ['nullable', Rule::in(['ensolarado', 'nublado', 'chuvoso', 'nao_aplicavel'])],
            'clima.precipitacao_manha_mm' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'clima.precipitacao_tarde_mm' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'clima.precipitacao_noite_mm' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'clima.observacoes' => ['nullable', 'string', 'max:10000'],
            'clima.dia_impraticavel' => ['nullable', 'boolean'],
            'servicos_atestados' => ['nullable', 'string', 'max:10000'],
            'ocorrencias' => ['nullable', 'string', 'max:10000'],
            'orientacoes' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(['rascunho', 'concluido'])],
            'evidencias' => ['nullable', 'array', 'max:10'],
            'evidencias.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,zip,txt', 'max:15360'],
            'evidencias_metadados' => ['nullable', 'array', 'max:10'],
            'evidencias_metadados.*.comment' => ['nullable', 'string', 'max:2000'],
            'evidencias_comentarios' => ['nullable', 'array', 'max:10'],
            'evidencias_comentarios.*' => ['nullable', 'string', 'max:2000'],
            'evidencias_ordem' => ['sometimes', 'array', 'max:10'],
            'evidencias_ordem.*' => ['string', 'distinct', 'regex:/^(existing|new):[0-9]+$/'],
        ]);
        $createdFiles = [];
        try {
            DB::transaction(function () use ($tenant, $rdo, $request, $validated, &$createdFiles) {
                $this->lockCurrentDiary($rdo);
                $current = $this->flowCapabilities($tenant, $rdo, $request->user());
                abort_unless($current['can_manage_inspection'] && in_array((int) $validated['obra_id'], $current['flow_obra_ids'], true), 422, 'Esta frente não está mais disponível para análise. Atualize a página.');
                $record = RdoFiscalizacaoRegistro::query()->firstOrNew([
                    'tenant_id' => $tenant->id, 'rdo_diario_id' => $rdo->id, 'obra_id' => (int) $validated['obra_id'], 'version_number' => max(1, (int) $rdo->current_version),
                ]);
                $evidence = collect($record->evidencias ?? [])->mapWithKeys(fn ($item, $index) => ["existing:{$index}" => [
                    ...$item, 'comment' => $validated['evidencias_comentarios'][$index] ?? ($item['comment'] ?? ''),
                ]]);
                $uploads = $request->file('evidencias', []);
                $order = $validated['evidencias_ordem'] ?? [...$evidence->keys()->all(), ...array_map(fn ($index) => "new:{$index}", array_keys($uploads))];
                $allowedKeys = [...$evidence->keys()->all(), ...array_map(fn ($index) => "new:{$index}", array_keys($uploads))];
                if (array_diff($order, $allowedKeys) || array_diff(array_map(fn ($index) => "new:{$index}", array_keys($uploads)), $order)) {
                    throw ValidationException::withMessages(['evidencias_ordem' => 'A lista de evidências mudou. Atualize a página e tente novamente.']);
                }
                $evidence = $evidence->only($order);
                if ($evidence->count() + count($uploads) > 10 || $evidence->sum('size') + collect($uploads)->sum(fn ($file) => $file->getSize()) > 50 * 1024 * 1024) {
                    throw ValidationException::withMessages(['evidencias' => 'Cada frente aceita até 10 evidências e 50 MB no total por versão.']);
                }
                foreach ($uploads as $index => $upload) {
                    $path = $upload->store("rdo/{$tenant->id}/{$rdo->id}/frente-{$validated['obra_id']}/fiscalizacao/v{$record->version_number}", 'local');
                    $createdFiles[] = $path;
                    if ($this->savingWorksBatch) $this->batchFiles[] = ['local', $path];
                    $evidence->put("new:{$index}", ['disk' => 'local', 'name' => $upload->getClientOriginalName(), 'path' => $path,
                        'mime_type' => $upload->getMimeType(), 'size' => $upload->getSize(), 'uploaded_at' => now()->toIso8601String(),
                        'comment' => $validated['evidencias_metadados'][$index]['comment'] ?? '']);
                }
                $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->first();
                $record->fill(['user_id' => $request->user()->id, 'empresa_id' => $membership?->empresa_id,
                    'atividades' => $validated['atividades'] ?? $record->atividades,
                    'clima' => $validated['clima'] ?? $record->clima,
                    'comentario' => $validated['comentario'] ?? (array_key_exists('comentario', $validated) ? null : $record->comentario),
                    'resumo_fiscalizacao' => isset($validated['atividades']) ? collect($validated['atividades'])->map(fn ($item) => $item['titulo']."\n".$item['ocorrencia'])->push(!empty($validated['comentario']) ? "Comentário\n".$validated['comentario'] : null)->filter()->implode("\n\n") : $validated['resumo_fiscalizacao'], 'servicos_atestados' => $validated['servicos_atestados'] ?? null,
                    'ocorrencias' => $validated['ocorrencias'] ?? null, 'orientacoes' => $validated['orientacoes'] ?? null,
                    'evidencias' => collect($order)->map(fn ($key) => $evidence->get($key))->values()->all(), 'status' => $validated['status'], 'submitted_at' => $validated['status'] === 'concluido' ? now() : null])->save();
            });
        } catch (\Throwable $exception) {
            foreach ($createdFiles as $path) Storage::disk('local')->delete($path);
            throw $exception;
        }
        return back()->with('success', $validated['status'] === 'concluido' ? 'Observações e evidências da frente registradas.' : 'Rascunho das observações da frente salvo.');
    }

    public function inspectionEvidence(Request $request, Tenant $tenant, RdoDiario $rdo, RdoFiscalizacaoRegistro $inspection, int $index)
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id && (int) $inspection->tenant_id === (int) $tenant->id && (int) $inspection->rdo_diario_id === (int) $rdo->id, 404);
        abort_unless(DiarioObraPermissions::can($request->user(), $tenant, DiarioObraPermissions::VIEW, $rdo->contract), 403);
        $file = $inspection->evidencias[$index] ?? null;
        $disk = $file['disk'] ?? 'public';
        abort_unless($file && in_array($disk, ['local', 'public'], true) && str_starts_with($file['path'] ?? '', "rdo/{$tenant->id}/{$rdo->id}/") && Storage::disk($disk)->exists($file['path']), 404);
        $inline = !$request->boolean('download') && in_array($file['mime_type'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true);
        $headers = ['Content-Type' => $file['mime_type'] ?? 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        return $inline ? Storage::disk($disk)->response($file['path'], $file['name'], $headers, 'inline') : Storage::disk($disk)->download($file['path'], $file['name'], $headers);
    }

    public function storeDivergence(Request $request, Tenant $tenant, RdoDiario $rdo): RedirectResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        $rdo->loadMissing(['contract', 'configuracao.obras']);
        $capabilities = $this->flowCapabilities($tenant, $rdo, $request->user());
        abort_unless($capabilities['can_create_divergence'], 403);

        $validated = $request->validate([
            'obra_id' => ['required', 'integer', Rule::in($capabilities['flow_obra_ids'])],
            'topico' => ['required', 'string', 'max:80'],
            'titulo' => ['required', 'string', 'max:180'],
            'versao_construtora' => ['required', 'string', 'max:10000'],
            'versao_gerenciadora' => ['required', 'string', 'max:10000'],
        ]);

        RdoDivergencia::create([
            'tenant_id' => $tenant->id,
            'rdo_diario_id' => $rdo->id,
            'obra_id' => $validated['obra_id'],
            'created_by_id' => $request->user()->id,
            'version_number' => max(1, (int) $rdo->current_version),
            'topico' => $validated['topico'],
            'titulo' => $validated['titulo'],
            'versao_construtora' => $validated['versao_construtora'],
            'versao_gerenciadora' => $validated['versao_gerenciadora'],
            'status' => 'aberta',
        ]);

        return back()->with('success', 'Divergência registrada sem alterar o relato da construtora.');
    }

    public function updateDivergence(
        Request $request,
        Tenant $tenant,
        RdoDiario $rdo,
        RdoDivergencia $divergencia,
    ): RedirectResponse {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $divergencia->tenant_id === (int) $tenant->id && (int) $divergencia->rdo_diario_id === (int) $rdo->id, 404);
        $rdo->loadMissing(['contract', 'configuracao.obras']);
        $capabilities = $this->flowCapabilities($tenant, $rdo, $request->user());
        $validated = $request->validate([
            'action' => ['required', Rule::in(['respond', 'conclude', 'client_decide'])],
            'response' => ['nullable', 'string', 'max:10000'],
            'conclusion' => ['nullable', 'string', 'max:10000'],
            'outcome' => ['nullable', Rule::in(['resolvida', 'aguarda_cliente'])],
            'client_decision' => ['nullable', Rule::in(['aceita_gerenciadora', 'aceita_construtora', 'aceita_com_ressalvas'])],
            'client_comment' => ['nullable', 'string', 'max:10000'],
        ]);

        if ($validated['action'] === 'respond') {
            abort_unless($capabilities['can_respond_divergence'] && $divergencia->status === 'aberta', 403);
            abort_if(trim((string) ($validated['response'] ?? '')) === '', 422, 'Informe a resposta da construtora.');
            $divergencia->update([
                'status' => 'respondida',
                'responded_by_id' => $request->user()->id,
                'resposta_construtora' => trim($validated['response']),
                'responded_at' => now(),
            ]);

            return back()->with('success', 'Resposta da construtora registrada na divergência.');
        }

        if ($validated['action'] === 'conclude') {
            abort_unless($capabilities['can_conclude_divergence'] && $divergencia->status === 'respondida', 403);
            abort_if(trim((string) ($validated['conclusion'] ?? '')) === '' || empty($validated['outcome']), 422, 'Informe a conclusão e o encaminhamento da divergência.');
            $divergencia->update([
                'status' => $validated['outcome'],
                'concluded_by_id' => $request->user()->id,
                'conclusao_gerenciadora' => trim($validated['conclusion']),
                'concluded_at' => now(),
            ]);

            return back()->with('success', $validated['outcome'] === 'resolvida'
                ? 'Divergência concluída pela gerenciadora.'
                : 'Divergência encaminhada para decisão do cliente.');
        }

        abort_unless($capabilities['can_decide_divergence'] && $divergencia->status === 'aguarda_cliente', 403);
        abort_if(empty($validated['client_decision']), 422, 'Selecione a decisão do cliente.');
        $divergencia->update([
            'status' => 'decidida_cliente',
            'client_decided_by_id' => $request->user()->id,
            'decisao_cliente' => $validated['client_decision'],
            'comentario_cliente' => trim((string) ($validated['client_comment'] ?? '')) ?: null,
            'client_decided_at' => now(),
        ]);

        return back()->with('success', 'Decisão do cliente registrada na divergência.');
    }

    private function createSubmissionSnapshot(
        Tenant $tenant,
        RdoDiario $rdo,
        User $user,
        string $statusBeforeSubmission,
        int $versionNumber,
    ): void {
        $rdo->loadMissing(['configuracao.obras', 'secoes']);
        $sections = RdoWorkflow::sections($rdo, RdoWorkflow::fillParty($rdo))
            ->groupBy('obra_id')
            ->map(fn ($items, $obraId) => [
                'obra_id' => (int) $obraId,
                'sections' => $items->mapWithKeys(fn (RdoSecaoRegistro $section) => [
                    $section->secao => $section->dados,
                ])->all(),
            ])
            ->values()
            ->all();

        RdoVersao::create([
            'tenant_id' => $tenant->id,
            'rdo_diario_id' => $rdo->id,
            'created_by_id' => $user->id,
            'version_number' => $versionNumber,
            'trigger' => $statusBeforeSubmission === 'rascunho' ? 'initial_submission' : 'resubmission',
            'status' => $statusBeforeSubmission,
            'sections_snapshot' => [
                'is_holiday' => $rdo->is_holiday,
                'reference_date' => $rdo->reference_date?->format('Y-m-d'),
                'obra_ids' => $rdo->configuracao?->obras->pluck('id')->map(fn ($id) => (int) $id)->all() ?? [],
                'sections_by_obra' => $sections,
                'workflow' => RdoWorkflow::definition($rdo),
                'sections_by_party' => $rdo->secoes->groupBy('filling_party')->map(fn ($records) => $records->groupBy('obra_id')->map(fn ($items) => $items->mapWithKeys(fn ($section) => [$section->secao => $section->dados])))->all(),
            ],
            'submitted_at' => now(),
        ]);
    }

    private function ensureInspectionCompleted(RdoDiario $rdo, array $obraIds): void
    {
        $completedObraIds = RdoFiscalizacaoRegistro::query()
            ->where('rdo_diario_id', $rdo->id)
            ->where('version_number', max(1, (int) $rdo->current_version))
            ->where('status', 'concluido')
            ->whereIn('obra_id', $obraIds)
            ->pluck('obra_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($missing = array_values(array_diff($obraIds, $completedObraIds))) {
            throw ValidationException::withMessages([
                'action' => 'Conclua o registro independente da fiscalização nas frentes selecionadas antes de emitir o parecer.',
                'inspection_obra_ids' => $missing,
            ]);
        }
    }

    private function ensureManagerDivergencesResolved(RdoDiario $rdo): void
    {
        $hasPending = RdoDivergencia::query()
            ->where('rdo_diario_id', $rdo->id)
            ->whereIn('status', ['aberta', 'respondida'])
            ->exists();

        if ($hasPending) {
            throw ValidationException::withMessages([
                'action' => 'Conclua as divergências respondidas ou devolva o RDO à construtora antes de aprovar.',
            ]);
        }
    }

    private function ensureClientDivergencesDecided(RdoDiario $rdo): void
    {
        if (RdoDivergencia::query()->where('rdo_diario_id', $rdo->id)->where('status', 'aguarda_cliente')->exists()) {
            throw ValidationException::withMessages([
                'action' => 'Registre a decisão do cliente nas divergências pendentes antes de concluir o parecer.',
            ]);
        }
    }

    private function inspectionPayload(Tenant $tenant, RdoDiario $rdo): array
    {
        return $rdo->fiscalizacaoRegistros
            ->sortByDesc('version_number')
            ->map(fn (RdoFiscalizacaoRegistro $record) => [
                'id' => $record->id,
                'obra_id' => (int) $record->obra_id,
                'obra' => $record->obra?->only(['id', 'codigo', 'nome']),
                'version_number' => (int) $record->version_number,
                'is_current_version' => (int) $record->version_number === max(1, (int) $rdo->current_version),
                'summary' => $record->resumo_fiscalizacao,
                'activities' => $record->atividades ?? [],
                'weather' => $record->clima,
                'comment' => $record->comentario,
                'services_attested' => $record->servicos_atestados,
                'occurrences' => $record->ocorrencias,
                'guidance' => $record->orientacoes,
                'status' => $record->status,
                'status_label' => $record->status === 'concluido' ? 'Concluído' : 'Rascunho',
                'user' => $record->user?->only(['id', 'name', 'email']),
                'company' => $record->empresa?->only(['id', 'nome', 'sigla']),
                'submitted_at' => $record->submitted_at?->format('d/m/Y H:i'),
                'evidence' => collect($record->evidencias ?? [])->map(fn (array $item, int $index) => [
                    'name' => $item['name'], 'mime_type' => $item['mime_type'] ?? '', 'size' => $item['size'] ?? 0, 'comment' => $item['comment'] ?? '',
                    'url' => route('tenant.diario-obra.rdo.inspection.evidence', [$tenant->slug, $rdo->id, $record->id, $index]),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function divergencePayload(RdoDiario $rdo): array
    {
        return $rdo->divergencias
            ->sortByDesc('created_at')
            ->map(fn (RdoDivergencia $item) => [
                'id' => $item->id,
                'obra_id' => (int) $item->obra_id,
                'obra' => $item->obra?->only(['id', 'codigo', 'nome']),
                'version_number' => (int) $item->version_number,
                'topic' => $item->topico,
                'title' => $item->titulo,
                'contractor_version' => $item->versao_construtora,
                'manager_version' => $item->versao_gerenciadora,
                'status' => $item->status,
                'status_label' => match ($item->status) {
                    'respondida' => 'Respondida pela construtora',
                    'resolvida' => 'Resolvida pela gerenciadora',
                    'aguarda_cliente' => 'Aguardando cliente',
                    'decidida_cliente' => 'Decidida pelo cliente',
                    default => 'Aberta',
                },
                'created_by' => $item->createdBy?->only(['id', 'name', 'email']),
                'contractor_response' => $item->resposta_construtora,
                'responded_by' => $item->respondedBy?->only(['id', 'name', 'email']),
                'responded_at' => $item->responded_at?->format('d/m/Y H:i'),
                'manager_conclusion' => $item->conclusao_gerenciadora,
                'concluded_by' => $item->concludedBy?->only(['id', 'name', 'email']),
                'concluded_at' => $item->concluded_at?->format('d/m/Y H:i'),
                'client_decision' => $item->decisao_cliente,
                'client_comment' => $item->comentario_cliente,
                'client_decided_by' => $item->clientDecidedBy?->only(['id', 'name', 'email']),
                'client_decided_at' => $item->client_decided_at?->format('d/m/Y H:i'),
                'created_at' => $item->created_at?->format('d/m/Y H:i'),
            ])
            ->values()
            ->all();
    }

    private function versionPayload(RdoDiario $rdo): array
    {
        return $rdo->versoes
            ->sortByDesc('version_number')
            ->map(fn (RdoVersao $version) => [
                'id' => $version->id,
                'number' => (int) $version->version_number,
                'trigger' => $version->trigger,
                'trigger_label' => $version->trigger === 'initial_submission' ? 'Envio inicial' : 'Reenvio após ajustes',
                'created_by' => $version->createdBy?->only(['id', 'name', 'email']),
                'submitted_at' => $version->submitted_at?->format('d/m/Y H:i'),
            ])
            ->values()
            ->all();
    }

    private function preparePhotoSectionData(
        array $data,
        array $uploads,
        Tenant $tenant,
        RdoDiario $rdo,
        int $obraId,
        string $fillingParty,
    ): array {
        $known = $rdo->secoes()->where('obra_id', $obraId)->where('filling_party', $fillingParty)->where('secao', 'fotos')->first()?->dados['arquivos'] ?? [];
        $knownPaths = array_column($known, 'path');
        $photosByKey = [];

        foreach ($data['arquivos'] ?? [] as $photo) {
            if (empty($photo['path'])) {
                continue;
            }

            if (!in_array($photo['path'], $knownPaths, true)) throw ValidationException::withMessages(['fotos' => 'Uma imagem não pertence ao preenchimento desta parte.']);
            $photo['comment'] = $photo['comment'] ?? $photo['legenda'] ?? null;
            $photo['legenda'] = $photo['comment'];
            $photosByKey['existing:'.$photo['path']] = $photo;
        }

        $newMetadata = array_values($data['novas_fotos'] ?? []);
        foreach ($uploads as $index => $upload) {
            $metadata = $newMetadata[$index] ?? [];
            $clientId = $metadata['client_id'] ?? "upload-{$index}";
            $comment = $metadata['comment'] ?? null;
            $path = $upload->store("tenant-{$tenant->id}/rdo/{$rdo->id}/obra-{$obraId}/parte-{$fillingParty}/fotos", 'public');
            if ($this->savingWorksBatch) $this->batchFiles[] = ['public', $path];
            $photosByKey['new:'.$clientId] = [
                'nome' => $upload->getClientOriginalName(),
                'path' => $path,
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

    private function contracts(Tenant $tenant, ?User $user = null, string $permission = DiarioObraPermissions::VIEW)
    {
        $query = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('code');
        $contractIds = $user ? DiarioObraPermissions::contractIdsFor($user, $tenant, $permission) : collect();

        if ($contractIds !== null) {
            $query->whereIn('id', $contractIds);
        }

        return $query->get(['id', 'code', 'name']);
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

    private function rdoCompletionPercent(RdoDiario $rdo): int
    {
        $requiredSections = ['clima', 'mao_obra', 'equipamentos', 'atividades', 'comentarios'];

        $obras = $rdo->configuracao?->obras ?? collect([$rdo->obra])->filter();
        $obraIds = $obras->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (empty($obraIds)) {
            return 0;
        }

        $filled = $rdo->secoes
            ->filter(fn (RdoSecaoRegistro $section) => in_array((int) $section->obra_id, $obraIds, true) && in_array($section->filling_party ?? 'construtora', RdoWorkflow::fillParties($rdo), true) && in_array($section->secao, $requiredSections, true))
            ->map(fn (RdoSecaoRegistro $section) => ($section->filling_party ?? 'construtora').'-'.$section->obra_id.'-'.$section->secao)
            ->unique()
            ->count();

        $expected = count($obraIds) * count($requiredSections) * count(RdoWorkflow::fillParties($rdo));

        return $expected > 0 ? min(100, (int) round(($filled / $expected) * 100)) : 0;
    }

    private function rdoPayload(Tenant $tenant, RdoDiario $rdo, ?User $user = null, ?string $fillingParty = null): array
    {
        $rdo->loadMissing(['analises' => fn ($query) => $query->where('decisao', 'approve_with_reservations')->select(['id', 'rdo_diario_id', 'obra_id', 'decisao', 'detalhes'])]);
        $reservationCount = $rdo->analises->filter(fn ($analysis) => $analysis->decisao === 'approve_with_reservations' && ((int) $rdo->current_version === 0 || (int) data_get($analysis->detalhes, 'rdo_version') === (int) $rdo->current_version))->pluck('obra_id')->unique()->count();
        $missingSubmissionObraIds = $this->missingSubmissionObraIds($rdo);
        $submissionReady = empty($missingSubmissionObraIds);
        $signatureStatus = $this->calendarSignatureStatus($rdo);
        $deadlinePayload = $this->deadlinePayload($rdo);

        return [
            'id' => $rdo->id,
            'code' => $rdo->code,
            'reference_date' => $rdo->reference_date?->format('Y-m-d'),
            'reference_date_formatted' => $rdo->reference_date?->format('d/m/Y'),
            'is_holiday' => $rdo->is_holiday,
            'status' => $rdo->status,
            'current_version' => (int) $rdo->current_version,
            'workflow' => RdoWorkflow::definition($rdo),
            'fill_party' => $fillingParty ?? RdoWorkflow::fillParty($rdo),
            'activity_subjects' => \App\Support\RdoPdfTemplates::activitySubjects($rdo),
            'fill_parties' => RdoWorkflow::fillParties($rdo),
            'participating_parties' => RdoWorkflow::parties($rdo),
            'status_label' => RdoWorkflow::statusLabel($rdo),
            'has_reservations' => $reservationCount > 0,
            'reservation_obra_count' => $reservationCount,
            'generated_automatically' => $rdo->generated_automatically,
            'copied_from_rdo_id' => $rdo->copied_from_rdo_id,
            'submission_ready' => $submissionReady,
            'missing_submission_obra_ids' => $missingSubmissionObraIds,
            'signature_status' => $signatureStatus,
            'calendar_status_label' => $this->calendarStatusLabel($rdo, $signatureStatus),
            'calendar_action_label' => $this->calendarActionLabel($rdo, $submissionReady),
            'is_reopened' => $this->isRdoReopened($rdo),
            'can_reopen' => $this->isRdoReopenable($rdo)
                && $user
                && DiarioObraPermissions::can($user, $tenant, DiarioObraPermissions::SETTINGS, $rdo->contract),
            'can_fill' => in_array($rdo->status, ['rascunho', 'devolvido_construtora', 'pendente_comprovacao'], true)
                && ! $this->isRdoReopenable($rdo)
                && $user
                && DiarioObraPermissions::can($user, $tenant, DiarioObraPermissions::FILL_RDO, $rdo->contract),
            'submission_deadline_formatted' => $this->submissionDeadline($rdo)->format('d/m/Y'),
            'deadline_counter' => $deadlinePayload['label'],
            'deadline_counter_tone' => $deadlinePayload['tone'],
            'reopened_until' => $rdo->reopened_until?->format('d/m/Y H:i'),
            'reopen_url' => route('tenant.diario-obra.rdo.reopen', [$tenant->slug, $rdo->id]),
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

        $signers = $signature->signers
            ->map(function ($signer) use ($signature): array {
                $status = $this->effectiveSignatureSignerStatus($signature, $signer);

                return [
                    'id' => $signer->id,
                    'role' => $signer->role,
                    'role_label' => $this->stageLabel($signer->role),
                    'name' => $signer->name,
                    'email' => $signer->email,
                    'status' => $status,
                    'status_label' => match ($status) {
                        'completed' => 'Assinado',
                        'cancelled' => 'Cancelado',
                        'failed' => 'Falha',
                        default => 'Pendente',
                    },
                    'signed_at' => $status === 'completed' ? $signer->signed_at?->format('d/m/Y H:i') : null,
                    'signing_url' => $signer->signing_url,
                ];
            })
            ->values();
        $allProviderSignersCompleted = $signature->provider !== 'opensign'
            || ($signers->isNotEmpty() && $signers->every(fn (array $signer): bool => $signer['status'] === 'completed'));
        $effectiveStatus = $signature->status === 'completed' && ! $allProviderSignersCompleted
            ? 'pending'
            : $signature->status;

        return [
            'id' => $signature->id,
            'provider' => $signature->provider,
            'status' => $effectiveStatus,
            'status_label' => $signature->provider === 'manual' && $signature->status === 'completed'
                ? 'Documento assinado enviado'
                : match ($effectiveStatus) {
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
            'signed_download_url' => $signature->signed_pdf_path && $allProviderSignersCompleted
                ? route('tenant.diario-obra.rdo.signatures.signed', [$tenant->slug, $rdo->id, $signature->id])
                : null,
            'signers' => $signers,
        ];
    }

    private function effectiveSignatureSignerStatus($signature, $signer): string
    {
        if ($signature->provider !== 'opensign' || $signer->status !== 'completed') {
            return $signer->status;
        }

        $payload = $signer->provider_payload ?? [];
        $status = data_get($payload, 'status')
            ?? data_get($payload, 'Status')
            ?? data_get($payload, 'signerStatus')
            ?? data_get($payload, 'signer_status')
            ?? data_get($payload, 'raw.status')
            ?? data_get($payload, 'raw.Status')
            ?? data_get($payload, 'raw.signerStatus')
            ?? data_get($payload, 'raw.signer_status');

        if ($status) {
            $normalized = str($status)->lower()->replace([' ', '-'], '_')->toString();

            return in_array($normalized, ['completed', 'complete', 'signed', 'document_signed', 'finished'], true)
                ? 'completed'
                : 'sent';
        }

        $signedAt = data_get($payload, 'signedAt')
            ?? data_get($payload, 'SignedAt')
            ?? data_get($payload, 'signed_on')
            ?? data_get($payload, 'signedOn')
            ?? data_get($payload, 'SignedOn')
            ?? data_get($payload, 'completedAt')
            ?? data_get($payload, 'CompletedAt')
            ?? data_get($payload, 'raw.signedAt')
            ?? data_get($payload, 'raw.signed_on')
            ?? data_get($payload, 'raw.signedOn')
            ?? data_get($payload, 'raw.SignedOn')
            ?? data_get($payload, 'raw.completedAt');

        if ($signedAt) {
            return 'completed';
        }

        $activity = data_get($payload, 'activity')
            ?? data_get($payload, 'Activity')
            ?? data_get($payload, 'raw.activity')
            ?? data_get($payload, 'raw.Activity');

        if ($activity) {
            $normalized = str($activity)->lower()->replace([' ', '-'], '_')->toString();

            if (in_array($normalized, ['completed', 'complete', 'signed', 'document_signed', 'finished'], true)) {
                return 'completed';
            }
        }

        $signed = data_get($payload, 'signed')
            ?? data_get($payload, 'Signed')
            ?? data_get($payload, 'isSigned')
            ?? data_get($payload, 'IsSigned')
            ?? data_get($payload, 'completed')
            ?? data_get($payload, 'Completed')
            ?? data_get($payload, 'isCompleted')
            ?? data_get($payload, 'IsCompleted')
            ?? data_get($payload, 'raw.signed')
            ?? data_get($payload, 'raw.isSigned')
            ?? data_get($payload, 'raw.completed')
            ?? data_get($payload, 'raw.isCompleted');

        return in_array($signed, [true, 1, '1', 'true', 'yes', 'sim'], true) ? 'completed' : 'sent';
    }

    private function calendarSignatureStatus(RdoDiario $rdo): ?string
    {
        if ($rdo->status !== 'arquivado') {
            return null;
        }

        $digitalSignatureEnabled = (bool) ($rdo->configuracao?->digital_signature_enabled ?? true);
        $signature = $rdo->relationLoaded('signatureRequests')
            ? $rdo->signatureRequests->sortByDesc('id')->first()
            : $rdo->signatureRequests()->latest('id')->first();

        if (! $signature) {
            return $digitalSignatureEnabled ? 'ready' : 'manual_waiting';
        }

        $allProviderSignersCompleted = true;
        if ($signature->provider === 'opensign') {
            $signature->loadMissing('signers');
            $allProviderSignersCompleted = $signature->signers->isNotEmpty()
                && $signature->signers->every(fn ($signer): bool => $this->effectiveSignatureSignerStatus($signature, $signer) === 'completed');
        }

        return $signature->status === 'completed' && $signature->signed_pdf_path && $allProviderSignersCompleted
            ? 'completed'
            : 'waiting';
    }

    private function calendarStatusLabel(RdoDiario $rdo, ?string $signatureStatus): string
    {
        if ($rdo->status === 'arquivado') {
            return match ($signatureStatus) {
                'completed' => 'Assinado',
                'waiting' => 'Aguardando assinatura',
                'manual_waiting' => 'Aguardando upload assinado',
                default => 'Pronto para assinatura',
            };
        }

        return RdoWorkflow::statusLabel($rdo);
    }

    private function calendarActionLabel(RdoDiario $rdo, bool $submissionReady): string
    {
        return match ($rdo->status) {
            'rascunho' => $submissionReady ? 'Enviar análise' : 'Preencher',
            'devolvido_construtora', 'pendente_comprovacao' => 'Corrigir',
            'em_analise_gerenciadora' => 'Fiscalizar',
            'em_analise_construtora' => 'Analisar',
            'em_aprovacao_cliente' => 'Manifestar',
            'arquivado' => 'Visualizar',
            default => 'Visualizar',
        };
    }

    public function flowCapabilities(Tenant $tenant, RdoDiario $rdo, ?User $user, ?string $party = null): array
    {
        if (! $user) {
            return [
                'can_edit' => false,
                'editable_obra_ids' => [],
                'flow_obra_ids' => [],
                'active_stage' => null,
                'actions' => [],
                'can_manage_inspection' => false,
                'can_create_divergence' => false,
                'can_respond_divergence' => false,
                'can_conclude_divergence' => false,
                'can_decide_divergence' => false,
            ];
        }

        $membership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
        $isAdmin = $user->is_platform_admin || in_array($membership?->role, ['tenant_owner', 'tenant_admin'], true);
        $rdo->loadMissing('contract');
        $canFill = DiarioObraPermissions::can($user, $tenant, DiarioObraPermissions::FILL_RDO, $rdo->contract);
        $canReview = DiarioObraPermissions::can($user, $tenant, DiarioObraPermissions::REVIEW_RDO, $rdo->contract);
        $allObraIds = $rdo->configuracao?->obras->pluck('id')->map(fn ($id) => (int) $id)->all() ?? [];
        $fillParties = RdoWorkflow::fillParties($rdo);
        $requestedParty = $party ?? request()->input('filling_party');
        if ($requestedParty !== null && (!is_string($requestedParty) || !in_array($requestedParty, $fillParties, true))) abort(422, 'Parte de preenchimento inválida para este RDO.');
        $availableParties = array_values(array_filter($fillParties, fn ($party) => $this->responsibleObraIds($tenant, $rdo, $user, $party, $isAdmin, $membership)));
        $fillingParty = $requestedParty ?? ($availableParties[0] ?? $fillParties[0]);
        $isFilling = in_array($rdo->status, RdoWorkflow::FILL_STATUSES, true);
        $stage = $isFilling ? $fillingParty : RdoWorkflow::activeParty($rdo);
        $assignedObraIds = $stage
            ? $this->responsibleObraIds($tenant, $rdo, $user, $stage, $isAdmin, $membership)
            : [];
        $pendingObraIds = $stage
            ? $this->pendingObraIds($rdo, $stage, $assignedObraIds)
            : [];
        $editableObraIds = in_array($rdo->status, ['rascunho', 'devolvido_construtora', 'pendente_comprovacao'], true)
            && $canFill
            ? $this->responsibleObraIds($tenant, $rdo, $user, $fillingParty, $isAdmin, $membership)
            : [];
        $editableStatuses = ['rascunho', 'devolvido_construtora', 'pendente_comprovacao'];
        $actions = [];

        $managerForwardReady = false;
        if ($stage === 'gerenciadora' && !$isFilling) {
            $allPending = $this->pendingObraIds($rdo, $stage, $allObraIds);
            $completed = RdoFiscalizacaoRegistro::query()->where('rdo_diario_id', $rdo->id)
                ->where('version_number', max(1, (int) $rdo->current_version))->where('status', 'concluido')
                ->pluck('obra_id')->map(fn ($id) => (int) $id)->all();
            $managerForwardReady = $canReview && !empty($allPending) && !array_diff($allPending, $pendingObraIds) && !array_diff($allPending, $completed);
        }

        if ($isFilling && in_array($rdo->status, $editableStatuses, true)) {
            $pendingObraIds = $assignedObraIds && empty($this->missingSubmissionObraIds($rdo))
                ? $allObraIds
                : [];
        }

        if ($canFill && $isFilling && $pendingObraIds && in_array($rdo->status, $editableStatuses, true)) {
            $actions['submit'] = [
                'label' => $rdo->status === 'pendente_comprovacao' ? 'Enviar comprovação das frentes' : (RdoWorkflow::reviews($rdo) ? 'Enviar frentes para análise' : 'Concluir RDO'),
                'tone' => 'primary',
                'comment_required' => false,
            ];
        }
        if ($canReview && $stage !== null && !$isFilling && $pendingObraIds) {
            $actions += $stage === 'cliente' && RdoWorkflow::clientCommentOnly($rdo)
                ? ['approve' => ['label' => 'Enviar comentário', 'tone' => 'primary', 'comment_required' => true]]
                : $this->reviewActions();
        }

        return [
            'can_edit' => ! empty($editableObraIds),
            'editable_obra_ids' => array_values(array_intersect($allObraIds, $editableObraIds)),
            'flow_obra_ids' => array_values($pendingObraIds),
            'manager_forward_ready' => $managerForwardReady,
            'filling_party' => $fillingParty,
            'active_stage' => $stage,
            'actions' => $actions,
            'can_manage_inspection' => $canReview && $stage === 'gerenciadora' && RdoWorkflow::isReview($rdo->status) && ! empty($pendingObraIds),
            'can_create_divergence' => !RdoWorkflow::clientCommentOnly($rdo) && $canReview && $stage === 'gerenciadora' && RdoWorkflow::fillParty($rdo) === 'construtora' && ((RdoWorkflow::definition($rdo)['model'] ?? null) === 'gerenciadora_construtora' || (in_array('cliente', RdoWorkflow::reviews($rdo), true) && array_search('gerenciadora', RdoWorkflow::reviews($rdo), true) < array_search('cliente', RdoWorkflow::reviews($rdo), true))) && ! empty($pendingObraIds),
            'can_respond_divergence' => $canFill && $isFilling && in_array($rdo->status, ['devolvido_construtora', 'pendente_comprovacao'], true),
            'can_conclude_divergence' => $canReview && $stage === 'gerenciadora',
            'can_decide_divergence' => !RdoWorkflow::clientCommentOnly($rdo) && $canReview && $stage === 'cliente',
        ];
    }

    private function reviewActions(): array
    {
        return [
            'approve' => ['label' => 'Aprovar', 'tone' => 'success', 'comment_required' => true],
            'approve_with_reservations' => ['label' => 'Aprovar com ressalvas', 'tone' => 'warning', 'comment_required' => true],
            'return' => ['label' => 'Rejeitar e devolver', 'tone' => 'danger', 'comment_required' => true],
        ];
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
            ->where('modulo', 'rdo')
            ->where('contract_id', $rdo->contract_id)
            ->whereIn('obra_id', $allObraIds)
            ->where('etapa', $stage)
            ->where('status', 'active')
            ->exists();

        if ($hasConfiguredResponsibilities) {
            return RdoResponsavel::query()
                ->where('tenant_id', $tenant->id)
                ->where('modulo', 'rdo')
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

    private function approvalCommentsByStage(RdoDiario $rdo): array
    {
        $query = RdoAnalise::query()
            ->with(['user:id,name'])
            ->where('rdo_diario_id', $rdo->id)
            ->whereIn('etapa', RdoWorkflow::reviews($rdo))
            ->latest('created_at');
        $comments = $this->analysesForCurrentVersion($rdo, $query)
            ->groupBy('etapa')
            ->map(fn ($items) => $items
                ->filter(fn (RdoAnalise $analysis) => filled($analysis->comentario))
                ->unique('obra_id')
                ->mapWithKeys(fn (RdoAnalise $analysis) => [
                    (int) $analysis->obra_id => [
                        'comment' => $analysis->comentario,
                        'user' => $analysis->user?->name,
                        'decision' => $this->decisionLabel($analysis->decisao, $analysis->etapa === 'cliente' && RdoWorkflow::clientCommentOnly($rdo)),
                        'created_at' => $analysis->created_at?->format('d/m/Y H:i'),
                    ],
                ])
                ->all())
            ->all();

        return [
            'construtora' => $comments['construtora'] ?? [],
            'gerenciadora' => $comments['gerenciadora'] ?? [],
            'cliente' => $comments['cliente'] ?? [],
        ];
    }

    private function decidedObraIds(RdoDiario $rdo, string $stage): array
    {
        $decisions = in_array($rdo->status, RdoWorkflow::FILL_STATUSES, true)
            ? ['submit']
            : ['approve', 'approve_with_reservations'];

        $query = RdoAnalise::query()
            ->where('rdo_diario_id', $rdo->id)
            ->where('etapa', $stage)
            ->whereIn('decisao', $decisions)
            ->whereNotNull('obra_id');

        // Returning to supervision starts a new review round without deleting earlier decisions.
        if (in_array($stage, ['gerenciadora', 'cliente'], true)) {
            $reset = RdoAnalise::where('rdo_diario_id', $rdo->id)->where('etapa', 'cliente')->where('decisao', 'return')->get()
                ->filter(fn ($row) => (int) data_get($row->detalhes, 'rdo_version') === (int) $rdo->current_version && data_get($row->detalhes, 'return_to') === 'gerenciadora')->max('id');
            if ($reset) $query->where('id', '>', $reset);
        }

        return $this->analysesForCurrentVersion($rdo, $query)
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

    private function analysesForCurrentVersion(RdoDiario $rdo, $query)
    {
        if ((int) $rdo->current_version > 0) {
            $version = (int) $rdo->current_version;

            return $query->get()->filter(
                fn (RdoAnalise $analysis): bool => (int) data_get($analysis->detalhes, 'rdo_version') === $version
            )->values();
        }

        return $query->where('created_at', '>=', $this->stageStartedAt($rdo))->get();
    }

    private function lockCurrentDiary(RdoDiario $rdo, bool $filling = false): void
    {
        $current = RdoDiario::where('tenant_id', $rdo->tenant_id)->whereKey($rdo->id)->lockForUpdate()->firstOrFail();
        if ($current->status !== $rdo->status || (int) $current->current_version !== (int) $rdo->current_version
            || ($filling && !in_array($current->status, RdoWorkflow::FILL_STATUSES, true))) {
            throw ValidationException::withMessages(['action' => 'O RDO foi atualizado. Recarregue a página antes de continuar.']);
        }
    }

    private function completeSoloRdo(Tenant $tenant, RdoDiario $rdo, User $user): void
    {
        if (!RdoWorkflow::savesWithoutApproval($rdo) || !in_array($rdo->status, RdoWorkflow::FILL_STATUSES, true)) return;
        $rdo->unsetRelation('secoes')->load('secoes');
        if ($this->missingSubmissionObraIds($rdo)) return;
        $this->ensureReadyForSubmission($rdo, $rdo->configuracao->obras->pluck('id')->all());
        $oldStatus = $rdo->status;
        $version = (int) $rdo->current_version + 1;
        $rdo->update(['current_version' => $version]);
        $this->createSubmissionSnapshot($tenant, $rdo, $user, $oldStatus, $version);
        foreach ($rdo->configuracao->obras as $obra) {
            RdoAnalise::create(['tenant_id' => $tenant->id, 'rdo_diario_id' => $rdo->id, 'obra_id' => $obra->id, 'user_id' => $user->id,
                'etapa' => 'gerenciadora', 'decisao' => 'save', 'comentario' => 'RDO salvo sem aprovação.',
                'detalhes' => ['rdo_version' => $version], 'status_anterior' => $oldStatus, 'status_novo' => 'arquivado']);
        }
        $rdo->update(['status' => 'arquivado', 'submitted_at' => now(), 'approved_at' => now()]);
    }

    private function requiredSubmissionSections(): array
    {
        return ['clima', 'mao_obra', 'equipamentos', 'atividades', 'comentarios'];
    }

    private function missingSubmissionObraIds(RdoDiario $rdo): array
    {
        $rdo->loadMissing(['configuracao.obras', 'secoes']);
        return $rdo->configuracao?->obras->pluck('id')->map(fn ($id) => (int) $id)
            ->filter(function ($obraId) use ($rdo) {
                foreach (RdoWorkflow::fillParties($rdo) as $party) if (!$this->partySubmissionReady($rdo, $obraId, $party)) return true;
                return false;
            })->values()->all() ?? [];
    }

    private function partySubmissionReady(RdoDiario $rdo, int $obraId, string $party): bool
    {
        $sections = RdoWorkflow::sections($rdo, $party)->where('obra_id', $obraId);
        if (array_diff($this->requiredSubmissionSections(), $sections->pluck('secao')->all())) return false;
        return trim((string) data_get($sections->firstWhere('secao', 'comentarios')?->dados, $party, '')) !== '';
    }

    private function ensureReadyForSubmission(RdoDiario $rdo, array $obraIds, bool $asValidation = false): void
    {
        $this->ensureSubmissionDeadline($rdo, $asValidation);
        foreach ($obraIds as $obraId) {
            foreach (RdoWorkflow::fillParties($rdo) as $party) {
                if ($this->partySubmissionReady($rdo, $obraId, $party)) continue;
                $message = 'Conclua as etapas de preenchimento de '.RdoWorkflow::PARTIES[$party].' em todas as frentes, incluindo o comentário geral. Fotos são opcionais.';
                if ($asValidation) throw ValidationException::withMessages(['action' => $message]);
                abort(422, $message);
            }
        }
    }

    private function ensureSubmissionDeadline(RdoDiario $rdo, bool $asValidation = false): void
    {
        $rdo->loadMissing('configuracao');

        if ($this->isRdoReopened($rdo)) {
            return;
        }

        $deadline = $this->submissionDeadline($rdo);

        if (CarbonImmutable::now($rdo->configuracao?->timezone ?: config('app.timezone'))->lte($deadline)) {
            return;
        }

        $message = sprintf(
            'O prazo para envio deste RDO expirou em %s.',
            $deadline->format('d/m/Y')
        );

        if (! $asValidation) {
            abort(422, $message);
        }

        throw ValidationException::withMessages([
            'action' => $message,
        ]);
    }

    private function deadlinePayload(RdoDiario $rdo): array
    {
        $rdo->loadMissing('configuracao');
        $timezone = $rdo->configuracao?->timezone ?: config('app.timezone');
        $now = CarbonImmutable::now($timezone)->startOfDay();

        if ($this->isRdoReopened($rdo)) {
            $reopenedUntil = CarbonImmutable::parse($rdo->reopened_until)->timezone($timezone);
            $days = max(0, $now->diffInDays($reopenedUntil->startOfDay(), false));

            return [
                'label' => $days === 0 ? 'Reaberto até hoje' : 'Reaberto por '.$days.' dia'.($days === 1 ? '' : 's'),
                'tone' => 'info',
            ];
        }

        $deadline = $this->submissionDeadline($rdo)->timezone($timezone);
        $days = $now->diffInDays($deadline->startOfDay(), false);

        if ($days < 0) {
            return ['label' => 'Prazo encerrado', 'tone' => 'danger'];
        }

        if ($days === 0) {
            return ['label' => 'Vence hoje', 'tone' => 'warning'];
        }

        return [
            'label' => $days.' dia'.($days === 1 ? '' : 's').' restante'.($days === 1 ? '' : 's'),
            'tone' => $days <= 2 ? 'warning' : 'success',
        ];
    }

    private function ensureFillWindowOpen(RdoDiario $rdo, bool $asValidation = false): void
    {
        $rdo->loadMissing('configuracao');

        if ($this->isRdoReopened($rdo)) {
            return;
        }

        if (CarbonImmutable::now($rdo->configuracao?->timezone ?: config('app.timezone'))->lte($this->submissionDeadline($rdo))) {
            return;
        }

        $message = 'O prazo para preenchimento deste RDO expirou. Use o botão Reabrir RDO no calendário para liberar o RDO.';

        if (! $asValidation) {
            abort(422, $message);
        }

        throw ValidationException::withMessages([
            'action' => $message,
        ]);
    }

    private function submissionDeadline(RdoDiario $rdo): CarbonImmutable
    {
        $rdo->loadMissing('configuracao');

        $deadlineDays = max(1, (int) ($rdo->configuracao?->submission_deadline_days ?? 7));
        $referenceDate = $rdo->reference_date instanceof CarbonImmutable
            ? $rdo->reference_date
            : CarbonImmutable::parse($rdo->reference_date);

        return $referenceDate->addDays($deadlineDays)->endOfDay();
    }

    private function isRdoReopened(RdoDiario $rdo): bool
    {
        $rdo->loadMissing('configuracao');

        return $rdo->reopened_until
            && CarbonImmutable::now($rdo->configuracao?->timezone ?: config('app.timezone'))->lte(CarbonImmutable::parse($rdo->reopened_until));
    }

    private function isRdoReopenable(RdoDiario $rdo): bool
    {
        $rdo->loadMissing('configuracao');

        if (! in_array($rdo->status, ['rascunho', 'devolvido_construtora', 'pendente_comprovacao'], true)) {
            return false;
        }

        if ($this->isRdoReopened($rdo)) {
            return false;
        }

        return CarbonImmutable::now($rdo->configuracao?->timezone ?: config('app.timezone'))->gt($this->submissionDeadline($rdo));
    }

    private function resolveStatusAfterDecisions(RdoDiario $rdo, string $stage, string $action): array
    {
        $fillingLabels = implode(' e ', array_map(fn ($party) => RdoWorkflow::PARTIES[$party], RdoWorkflow::fillParties($rdo)));
        if ($action === 'return') {
            return ['devolvido_construtora', 'RDO devolvido para ajustes nos preenchimentos de '.$fillingLabels.'.'];
        }
        $allObraIds = $rdo->configuracao->obras->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (array_diff($allObraIds, $this->decidedObraIds($rdo, $stage))) {
            return [$rdo->status, ($stage === 'cliente' && RdoWorkflow::clientCommentOnly($rdo) ? 'Comentário registrado.' : 'Decisão registrada.').' O RDO aguarda as demais frentes desta etapa.'];
        }
        $reviews = RdoWorkflow::reviews($rdo);
        $nextIndex = $action === 'submit' ? 0 : array_search($stage, $reviews, true) + 1;
        if (isset($reviews[$nextIndex])) {
            $next = $reviews[$nextIndex];
            return [RdoWorkflow::REVIEW_STATUSES[$next], ($next === 'cliente' && RdoWorkflow::clientCommentOnly($rdo) ? 'RDO encaminhado para comentário: ' : 'RDO encaminhado para análise: ').RdoWorkflow::PARTIES[$next].'.'];
        }
        $reservationQuery = RdoAnalise::query()->where('rdo_diario_id', $rdo->id)
            ->whereIn('etapa', $reviews)->where('decisao', 'approve_with_reservations');
        $hasReservations = $this->analysesForCurrentVersion($rdo, $reservationQuery)->isNotEmpty();
        return $hasReservations
            ? ['pendente_comprovacao', 'RDO com ressalvas. As partes responsáveis pelo preenchimento devem atender as obrigações e enviar comprovações.']
            : ['arquivado', 'Etapas configuradas concluídas. O RDO foi concluído e arquivado.'];
    }

    private function notifyFlowRecipients(Tenant $tenant, RdoDiario $rdo, User $actor, string $newStatus, string $message): void
    {
        $parties = RdoWorkflow::parties($rdo);
        $rdo->loadMissing(['contract', 'configuracao.obras']);
        $obraIds = $rdo->configuracao->obras->pluck('id')->all();
        $companyIds = array_filter(array_intersect_key([
            'construtora' => $rdo->contract?->construtora_empresa_id,
            'gerenciadora' => $rdo->contract?->fiscalizadora_empresa_id,
            'cliente' => $rdo->contract?->cliente_empresa_id,
        ], array_flip($parties)));
        $users = User::query()->whereHas('tenantMemberships', fn ($query) => $query->where('tenant_id', $tenant->id)->where('status', 'active'))
            ->where(function ($query) use ($tenant, $rdo, $parties, $obraIds, $companyIds, $actor) {
                $query->where('id', $actor->id)
                    ->orWhereHas('rdoResponsabilidades', fn ($roles) => $roles->where('tenant_id', $tenant->id)->where('contract_id', $rdo->contract_id)
                        ->where('modulo', 'rdo')->where('status', 'active')->whereIn('etapa', $parties)
                        ->where(fn ($front) => $front->whereNull('obra_id')->orWhereIn('obra_id', $obraIds)))
                    ->orWhereHas('tenantMemberships', fn ($members) => $members->where('tenant_id', $tenant->id)->where('status', 'active')->whereIn('empresa_id', $companyIds));
            })->get();
        foreach ($users->unique('id') as $user) $user->notify(new RdoFlowChangedNotification($rdo, $tenant, $actor, $message));
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

    private function decisionLabel(string $decision, bool $commentOnly = false): string
    {
        return match ($decision) {
            'approve' => $commentOnly ? 'Comentário registrado' : 'Aprovado',
            'save' => 'Salvo sem aprovação',
            'approve_with_reservations' => 'Aprovado com ressalvas',
            'return' => 'Devolvido',
            default => 'Enviado para análise',
        };
    }

    private function divergenceStatusLabel(string $status): string
    {
        return match ($status) {
            'respondida' => 'Respondida pela construtora',
            'resolvida' => 'Resolvida pela gerenciadora',
            'aguarda_cliente' => 'Aguardando decisão do cliente',
            'decidida_cliente' => 'Decidida pelo cliente',
            default => 'Aberta',
        };
    }
}

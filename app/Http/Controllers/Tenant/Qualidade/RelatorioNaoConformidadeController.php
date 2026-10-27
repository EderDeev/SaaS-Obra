<?php

namespace App\Http\Controllers\Tenant\Qualidade;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Disciplina;
use App\Models\Empresa;
use App\Models\InstrucaoCampo;
use App\Models\Obra;
use App\Models\ProjectDocument;
use App\Models\RelatorioNaoConformidade;
use App\Models\RelatorioNaoConformidadeEvidencia;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\RncResponsibleNotification;
use App\Support\RncListing;
use App\Support\IcPermissions;
use App\Support\RncPermissions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RelatorioNaoConformidadeController extends Controller
{
    private const GRAVIDADES = ['Leve', 'Média', 'Grave', 'Gravíssima'];

    private const PDF_PHOTO_WIDTH = 1000;

    private const PDF_PHOTO_HEIGHT = 680;

    private const PDF_LOGO_WIDTH = 480;

    private const PDF_LOGO_HEIGHT = 180;

    public function index(Request $request, Tenant $tenant): InertiaResponse
    {
        abort_unless(RncPermissions::canAny($request->user(), $tenant, RncPermissions::VIEW), 403);

        $contractIds = $this->contractIdsForPermission($request, $tenant, RncPermissions::VIEW);

        $contracts = $tenant->contracts()->whereIn('id', $contractIds)->orderBy('code')->get(['id', 'code', 'name']);
        $obras = $tenant->obras()->whereIn('contract_id', $contractIds)->orderBy('codigo')->get(['id', 'contract_id', 'codigo', 'nome']);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'contract_id' => ['nullable', 'integer', Rule::in($contracts->pluck('id')->all())],
            'obra_id' => ['nullable', 'integer', Rule::in($obras->pluck('id')->all())],
            'status' => ['nullable', Rule::in(array_keys(RncListing::STATUSES))],
            'stage' => ['nullable', Rule::in(['all', ...array_keys(RncListing::STAGES)])],
        ]);
        $filters['stage'] = $filters['stage'] ?? 'all';
        if (! empty($filters['contract_id']) && ! empty($filters['obra_id'])
            && (int) $obras->firstWhere('id', $filters['obra_id'])->contract_id !== (int) $filters['contract_id']) {
            throw ValidationException::withMessages(['obra_id' => 'Selecione uma obra do contrato informado.']);
        }

        $statusSql = RncListing::statusSql();
        $query = $tenant->relatorioNaoConformidades()->whereIn('contract_id', $contractIds)
            ->when($filters['contract_id'] ?? null, fn ($query, $id) => $query->where('contract_id', $id))
            ->when($filters['obra_id'] ?? null, fn ($query, $id) => $query->where('obra_id', $id))
            ->when(trim($filters['q'] ?? ''), function ($query, $term) {
                $like = '%'.mb_strtolower($term).'%';
                $query->where(function ($query) use ($term, $like) {
                    $query->whereRaw('LOWER(assunto) LIKE ?', [$like])->orWhereRaw('LOWER(descricao_problema) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(observacao) LIKE ?', [$like])
                        ->orWhereHas('contract', fn ($contract) => $contract->whereRaw('LOWER(code) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like]))
                        ->orWhereHas('obra', fn ($obra) => $obra->whereRaw('LOWER(codigo) LIKE ?', [$like])->orWhereRaw('LOWER(nome) LIKE ?', [$like]));
                    if (preg_match('/^(?:RNC\s*)?(\d+)(?:[-\/](\d{4}))?$/i', $term, $number)) {
                        $query->orWhere(function ($numberQuery) use ($number) {
                            $numberQuery->where(function ($sequence) use ($number) {
                                $sequence->where('sequence_number', (int) $number[1])->orWhere(fn ($legacy) => $legacy
                                    ->where(fn ($missing) => $missing->whereNull('sequence_number')->orWhere('sequence_number', 0))
                                    ->where('id', (int) $number[1]));
                            });
                            if (isset($number[2])) {
                                $numberQuery->where(fn ($year) => $year->where('sequence_year', (int) $number[2])
                                    ->orWhere(fn ($legacy) => $legacy->whereNull('sequence_year')->whereYear('opened_at', $number[2])));
                            }
                        });
                    }
                });
            });
        $statusCounts = (clone $query)->selectRaw("{$statusSql} AS listing_status, COUNT(*) AS total")
            ->groupByRaw($statusSql)->pluck('total', 'listing_status');
        $stageCounts = ['all' => (int) $statusCounts->sum()];
        foreach (RncListing::STAGES as $stage => $statuses) {
            $stageCounts[$stage] = (int) $statusCounts->only($statuses)->sum();
        }

        $rncs = $query->select('relatorio_nao_conformidades.*')->selectRaw("{$statusSql} AS listing_status")
            ->when(($filters['stage'] ?? 'all') !== 'all', function ($query) use ($filters, $statusSql) {
                $statuses = RncListing::STAGES[$filters['stage']];
                $placeholders = implode(',', array_fill(0, count($statuses), '?'));
                $query->whereRaw("({$statusSql}) IN ({$placeholders})", $statuses);
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereRaw("({$statusSql}) = ?", [$status]))
            ->with([
                'contract:id,code,name',
                'obra:id,nome,codigo',
                'disciplina:id,nome,sigla,cor',
                'contratante:id,nome,sigla,logo_path',
                'contratada:id,nome,sigla,logo_path',
                'creator:id,name',
                'acoesCorretivas' => fn ($query) => $query->select(['id', 'tenant_id', 'relatorio_nao_conformidade_id', 'user_id', 'status', 'review_observation', 'prazo_execucao_proposto', 'submitted_at', 'reviewed_at', 'reviewed_by_id'])->orderByDesc('id'),
                'acoesCorretivas.user:id,name',
                'acoesCorretivas.reviewer:id,name',
            ])
            ->withCount(['photos', 'evidencias'])
            ->latest()->orderByDesc('id')->paginate(10)->withQueryString()
            ->through(fn (RelatorioNaoConformidade $rnc): RelatorioNaoConformidade => $this->attachUserPermissions($rnc, $request->user(), $tenant));

        return Inertia::render('Tenant/Qualidade/RelatorioNaoConformidade/Index', [
            'tenant' => $tenant,
            'rncs' => $rncs,
            'contracts' => $contracts,
            'obras' => $obras,
            'filters' => $filters,
            'statuses' => RncListing::STATUSES,
            'stageCounts' => $stageCounts,
            'canCreateRnc' => RncPermissions::canAny($request->user(), $tenant, RncPermissions::CREATE),
            'canDeleteRncs' => $this->canDeleteRncs($request->user(), $tenant),
            'canReviewRncProposals' => $this->canReviewRncProposals($request->user(), $tenant),
        ]);
    }

    public function tourPreview(Request $request, Tenant $tenant): InertiaResponse
    {
        abort_unless(RncPermissions::canAny($request->user(), $tenant, RncPermissions::VIEW), 403);

        $screen = $request->string('screen')->toString();
        $allowedScreens = [
            'responsibles',
            'create',
            'notify',
            'corrective-action',
            'review',
            'evidence',
            'final-pdf',
            'dashboard',
        ];

        return Inertia::render('Tenant/Qualidade/RelatorioNaoConformidade/TourPreview', [
            'tenant' => $tenant,
            'screen' => in_array($screen, $allowedScreens, true) ? $screen : 'responsibles',
        ]);
    }

    public function dashboard(Request $request, Tenant $tenant): InertiaResponse
    {
        abort_unless(RncPermissions::canAny($request->user(), $tenant, RncPermissions::DASHBOARD), 403);

        $contractIds = $this->contractIdsForPermission($request, $tenant, RncPermissions::DASHBOARD);
        $contracts = $tenant->contracts()->whereIn('id', $contractIds)->orderBy('code')->get(['id', 'code', 'name']);
        $obras = $tenant->obras()->withTrashed()->whereIn('contract_id', $contractIds)->orderBy('nome')->get(['id', 'contract_id', 'codigo', 'nome']);
        $disciplinas = $tenant->disciplinas()->withTrashed()->whereIn('contract_id', $contractIds)->orderBy('nome')->get(['id', 'contract_id', 'nome']);
        $filters = $request->validate([
            'contract_id' => ['nullable', 'integer', Rule::in($contractIds->all())],
            'obra_id' => ['nullable', 'integer', Rule::in($obras->pluck('id')->all())],
            'disciplina_id' => ['nullable', 'integer', Rule::in($disciplinas->pluck('id')->all())],
            'gravidade' => ['nullable', Rule::in(self::GRAVIDADES)],
            'status' => ['nullable', Rule::in(array_keys(RncListing::STATUSES))],
            'resolution_result' => ['nullable', Rule::in(array_keys(RelatorioNaoConformidadeEvidencia::RESOLUTION_RESULTS))],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
        ], ['date_to.after_or_equal' => 'A data final deve ser igual ou posterior à data inicial.']);
        $modelSelectFields = !empty($filters['contract_id'])
            ? \App\Support\RncDashboardFields::catalog($tenant, (int) $filters['contract_id']) : [];
        $fieldRules = ['model_fields' => ['nullable', 'array', function ($attribute, $value, $fail) use ($modelSelectFields) {
            if (array_diff(array_keys($value), array_column($modelSelectFields, 'key'))) $fail('Selecione campos de seleção do contrato informado.');
        }]];
        foreach ($modelSelectFields as $field) $fieldRules['model_fields.'.$field['key']] = ['nullable', 'string', Rule::in($field['options'])];
        $filters = [...$filters, ...$request->validate($fieldRules)];
        foreach (['obra_id' => $obras, 'disciplina_id' => $disciplinas] as $field => $options) {
            if (! empty($filters['contract_id']) && ! empty($filters[$field])
                && (int) $options->firstWhere('id', $filters[$field])->contract_id !== (int) $filters['contract_id']) {
                throw ValidationException::withMessages([$field => 'Selecione uma opção do contrato informado.']);
            }
        }
        $latestResolution = RelatorioNaoConformidadeEvidencia::query()->select('resolution_result')
            ->whereColumn('relatorio_nao_conformidade_id', 'relatorio_nao_conformidades.id')
            ->whereColumn('tenant_id', 'relatorio_nao_conformidades.tenant_id')->orderByDesc('id')->limit(1);
        $statusSql = RncListing::statusSql();
        $rncs = $tenant->relatorioNaoConformidades()
            ->whereIn('contract_id', $contractIds)
            ->select('relatorio_nao_conformidades.*')->selectRaw("({$statusSql}) AS workflow_status")
            ->addSelect(['resolution_result' => $latestResolution])
            ->when($filters['contract_id'] ?? null, fn ($q, $id) => $q->where('contract_id', $id))
            ->when($filters['obra_id'] ?? null, fn ($q, $id) => $q->where('obra_id', $id))
            ->when($filters['disciplina_id'] ?? null, fn ($q, $id) => $q->where('disciplina_id', $id))
            ->when($filters['gravidade'] ?? null, fn ($q, $value) => $q->where('gravidade', $value))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->whereRaw("({$statusSql}) = ?", [$value]))
            ->when($filters['resolution_result'] ?? null, fn ($q, $value) => $q->whereRaw('('.$latestResolution->toSql().') = ?', [$value]))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('opened_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('opened_at', '<=', $date))
            ->with([
                'contract:id,code,name',
                'obra:id,nome,codigo',
                'disciplina:id,nome,sigla,cor',
                'contratante:id,nome,sigla',
                'contratada:id,nome,sigla',
                'acoesCorretivas:id,tenant_id,relatorio_nao_conformidade_id,user_id,status,prazo_execucao_proposto,submitted_at,reviewed_at',
                'evidencias:id,tenant_id,relatorio_nao_conformidade_id,relatorio_nao_conformidade_acao_corretiva_id,submitted_at',
            ])
            ->withCount(['photos', 'evidencias'])
            ->latest()
            ->get();

        $rncs = $rncs->filter(fn ($rnc) => \App\Support\RncDashboardFields::matches($tenant, $rnc, $filters['model_fields'] ?? []))->values();

        $today = now()->startOfDay();
        $finalizadas = $rncs->filter(fn (RelatorioNaoConformidade $rnc): bool => $this->isFinalizedRnc($rnc));
        $atrasadasResposta = $rncs->filter(fn (RelatorioNaoConformidade $rnc): bool => $this->isResponseOverdueRnc($rnc, $today));
        $atrasadasExecucao = $rncs->filter(fn (RelatorioNaoConformidade $rnc): bool => $this->isExecutionOverdueRnc($rnc, $today));
        $emAnalise = $rncs->where('workflow_status', 'awaiting_review');
        $aguardandoEvidencia = $rncs->where('workflow_status', 'awaiting_evidence');
        $resolutionCounts = collect(RelatorioNaoConformidadeEvidencia::RESOLUTION_RESULTS)
            ->mapWithKeys(fn ($label, $value) => [$value => $rncs->where('resolution_result', $value)->count()])->all();
        $monthEnd = ! empty($filters['date_to']) ? Carbon::parse($filters['date_to'])->startOfMonth() : now()->startOfMonth();
        $monthlyCounts = collect(range(5, 0))
            ->mapWithKeys(function (int $monthsAgo) use ($rncs, $monthEnd): array {
                $date = $monthEnd->copy()->subMonths($monthsAgo);
                $monthKey = $date->format('Y-m');

                return [
                    $date->translatedFormat('M/y') => $rncs
                        ->filter(fn (RelatorioNaoConformidade $rnc): bool => $rnc->opened_at?->format('Y-m') === $monthKey)
                        ->count(),
                ];
            })
            ->all();

        return Inertia::render('Tenant/Qualidade/RelatorioNaoConformidade/Dashboard', [
            'tenant' => $tenant,
            'contracts' => $contracts, 'obras' => $obras, 'disciplinas' => $disciplinas, 'filters' => $filters,
            'modelSelectFields' => $modelSelectFields,
            'statuses' => RncListing::STATUSES,
            'resolutionCounts' => $resolutionCounts,
            'monthlyRange' => $monthEnd->copy()->subMonths(5)->format('m/Y').' a '.$monthEnd->format('m/Y'),
            'metrics' => [
                'total' => $rncs->count(),
                'abertas' => $rncs->where('status', 'aberta')->count(),
                'atrasoResposta' => $atrasadasResposta->count(),
                'atrasoExecucao' => $atrasadasExecucao->count(),
                'emAnalise' => $emAnalise->count(),
                'aguardandoEvidencia' => $aguardandoEvidencia->count(),
                'aguardandoAssinaturas' => $rncs->where('workflow_status', 'awaiting_signature')->count(),
                'finalizadas' => $finalizadas->count(),
                'resolvidas' => $resolutionCounts['yes'],
                'parcialmenteResolvidas' => $resolutionCounts['partial'],
                'naoResolvidas' => $resolutionCounts['no'],
            ],
            'statusCounts' => collect(RncListing::STATUSES)->mapWithKeys(fn ($label, $status) => [$label => $rncs->where('workflow_status', $status)->count()])->all(),
            'gravidadeCounts' => $this->countBy($rncs, 'gravidade'),
            'disciplinaCounts' => $this->countRncDisciplines($rncs),
            'monthlyCounts' => $monthlyCounts,
            'responseOverdueRncs' => $atrasadasResposta->take(6)->values(),
            'executionOverdueRncs' => $atrasadasExecucao->take(6)->values(),
        ]);
    }

    public function create(Request $request, Tenant $tenant): InertiaResponse
    {
        abort_unless(RncPermissions::canAny($request->user(), $tenant, RncPermissions::CREATE), 403);

        return Inertia::render('Tenant/Qualidade/RelatorioNaoConformidade/Create', [
            'tenant' => $tenant,
            ...$this->rncFormOptions($request, $tenant, RncPermissions::CREATE),
        ]);
    }

    public function show(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): InertiaResponse
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::VIEW, $rnc->contract), 403);

        return Inertia::render('Tenant/Qualidade/RelatorioNaoConformidade/Show', [
            'tenant' => $tenant,
            'rnc' => $rnc,
            'signatureData' => app(\App\Services\RncSignatureService::class)->statusData($tenant, $rnc),
            'modelFields' => app(\App\Services\RncDocumentSnapshots::class)->rows($tenant, $rnc),
        ]);
    }

    public function edit(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): InertiaResponse
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::EDIT, $rnc->contract), 403);
        abort_if($rnc->signatureRequests()->whereIn('status', ['draft', 'sent', 'pending', 'completed'])->exists(), 422, 'O documento enviado para assinatura não pode ser alterado.');

        return Inertia::render('Tenant/Qualidade/RelatorioNaoConformidade/Create', [
            'tenant' => $tenant,
            'mode' => 'edit',
            'rnc' => $rnc,
            'documentModel' => app(\App\Services\RncDocumentSnapshots::class)->form($tenant, $rnc),
            'modelFieldValues' => app(\App\Services\RncDocumentSnapshots::class)->legacyValues($tenant, $rnc),
            ...$this->rncFormOptions($request, $tenant, RncPermissions::EDIT),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'instrucao_campo_id' => ['nullable', 'integer'],
            'obra_id' => [
                'required',
                Rule::exists('obras', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'project_document_id' => [
                'nullable',
                Rule::exists('project_documents', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'project_document_ids' => ['nullable', 'array', 'max:50'],
            'project_document_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('project_documents', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'contratante_empresa_id' => [
                'required',
                Rule::exists('empresas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'contratada_empresa_id' => [
                'required',
                Rule::exists('empresas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'opened_at' => ['required', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'disciplina_id' => [
                'required',
                Rule::exists('disciplinas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'gravidade' => ['required', Rule::in(self::GRAVIDADES)],
            'assunto' => ['required', 'string', 'max:255'],
            'descricao_problema' => ['required', 'string', 'max:10000'],
            'observacao' => ['nullable', 'string', 'max:10000'],
            'acoes_corretivas_recomendadas' => ['required', 'string', 'max:10000'],
            'prazo_resposta_acao_corretiva' => ['required', 'date', 'after_or_equal:opened_at'],
            'photos' => ['nullable', 'array', 'max:12'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'photo_comments' => ['nullable', 'array'],
            'photo_comments.*' => ['nullable', 'string', 'max:1000'],
            'photo_positions' => ['nullable', 'array'],
            'photo_positions.*' => ['nullable', 'integer', 'min:1', 'max:999'],
        ], [
            'obra_id.required' => 'Selecione a obra.',
            'contratante_empresa_id.required' => 'Selecione a empresa contratante.',
            'contratada_empresa_id.required' => 'Selecione a empresa contratada.',
            'disciplina_id.required' => 'Selecione a disciplina.',
            'disciplina_id.exists' => 'Selecione uma disciplina valida.',
            'prazo_resposta_acao_corretiva.required' => 'Informe o prazo para resposta de ação corretiva.',
            'prazo_resposta_acao_corretiva.after_or_equal' => 'O prazo para resposta de ação corretiva não pode ser anterior à data de abertura.',
            'photos.*.image' => 'Envie apenas imagens no registro fotográfico.',
            'photos.*.max' => 'Cada imagem pode ter no máximo 5 MB.',
        ]);

        $obra = $tenant->obras()->findOrFail($data['obra_id']);
        $contract = $obra->contract()->firstOrFail();

        abort_unless($this->canAccessContract($request->user(), $tenant, $contract), 403);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::CREATE, $contract), 403);
        $data['instrucao_campo_id'] = $this->icReferenceForRnc($request, $tenant, $data['instrucao_campo_id'] ?? null, $contract, $obra);

        $contratante = $this->empresaForContract($tenant, (int) $data['contratante_empresa_id'], $contract);
        $contratada = $this->empresaForContract($tenant, (int) $data['contratada_empresa_id'], $contract);
        $projectDocuments = $this->projectDocumentsForRnc(
            $tenant,
            $data['project_document_ids'] ?? [$data['project_document_id'] ?? null],
            $contract,
            $obra,
            array_key_exists('project_document_ids', $data) ? 'project_document_ids' : 'project_document_id'
        );
        $projectDocument = $projectDocuments->first();
        $disciplina = $this->disciplinaForContract($tenant, (int) $data['disciplina_id'], $contract);

        if (! $contratante || ! $contratada) {
            throw ValidationException::withMessages([
                'contratante_empresa_id' => 'Selecione empresas vinculadas ao mesmo contrato da obra.',
            ]);
        }

        if (! $disciplina) {
            throw ValidationException::withMessages([
                'disciplina_id' => 'Selecione uma disciplina vinculada ao mesmo contrato da obra.',
            ]);
        }

        $sequenceYear = Carbon::parse($data['opened_at'])->year;
        $rnc = DB::transaction(function () use ($tenant, $contract, $obra, $projectDocument, $projectDocuments, $disciplina, $contratante, $contratada, $request, $data, $sequenceYear): RelatorioNaoConformidade {
            Tenant::query()
                ->whereKey($tenant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lastSequence = $tenant->relatorioNaoConformidades()
                ->withTrashed()
                ->where('sequence_year', $sequenceYear)
                ->orderByDesc('sequence_number')
                ->lockForUpdate()
                ->value('sequence_number');
            $nextSequence = ((int) $lastSequence) + 1;

            $rnc = $tenant->relatorioNaoConformidades()->create([
                'sequence_number' => $nextSequence,
                'sequence_year' => $sequenceYear,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'instrucao_campo_id' => $data['instrucao_campo_id'],
                'project_document_id' => $projectDocument?->id,
                'disciplina_id' => $disciplina->id,
                'contratante_empresa_id' => $contratante->id,
                'contratada_empresa_id' => $contratada->id,
                'created_by_id' => $request->user()->id,
                'opened_at' => $data['opened_at'],
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'natureza' => $disciplina->nome,
                'gravidade' => $data['gravidade'],
                'assunto' => $data['assunto'],
                'descricao_problema' => $data['descricao_problema'],
                'observacao' => $data['observacao'] ?? null,
                'acoes_corretivas_recomendadas' => $data['acoes_corretivas_recomendadas'],
                'prazo_resposta_acao_corretiva' => $data['prazo_resposta_acao_corretiva'],
                'status' => 'aberta',
                'model_field_values' => $request->input('model_field_values', []),
            ]);

            $rnc->projectDocuments()->sync($projectDocuments->pluck('id')->all());

            return $rnc;
        });

        $comments = collect($request->input('photo_comments', []));
        $positions = collect($request->input('photo_positions', []));

        foreach ($request->file('photos', []) as $index => $photo) {
            $storedPhoto = $this->storeRncPhotoUpload($photo, $tenant, $rnc);

            $rnc->photos()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $request->user()->id,
                'path' => $storedPhoto['path'],
                'original_name' => $photo->getClientOriginalName(),
                'mime_type' => $storedPhoto['mime_type'],
                'size' => $storedPhoto['size'],
                'position' => (int) ($positions->get($index) ?: $index + 1),
                'comment' => $comments->get($index),
            ]);
        }

        return redirect()
            ->route('tenant.qualidade.rnc.index', $tenant)
            ->with('success', 'RNC criada com sucesso.');
    }

    public function update(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): RedirectResponse
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);
        $data = $this->validateRncData($request, $tenant, $rnc);

        $obra = $tenant->obras()->findOrFail($data['obra_id']);
        $contract = $obra->contract()->firstOrFail();

        abort_unless($this->canAccessContract($request->user(), $tenant, $contract), 403);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::EDIT, $contract), 403);
        $data['instrucao_campo_id'] = $this->icReferenceForRnc($request, $tenant,
            array_key_exists('instrucao_campo_id', $data) ? $data['instrucao_campo_id'] : $rnc->instrucao_campo_id,
            $contract, $obra, $rnc);

        $contratante = $this->empresaForContract($tenant, (int) $data['contratante_empresa_id'], $contract);
        $contratada = $this->empresaForContract($tenant, (int) $data['contratada_empresa_id'], $contract);
        $projectDocuments = $this->projectDocumentsForRnc(
            $tenant,
            $data['project_document_ids'] ?? [$data['project_document_id'] ?? null],
            $contract,
            $obra,
            array_key_exists('project_document_ids', $data) ? 'project_document_ids' : 'project_document_id'
        );
        $projectDocument = $projectDocuments->first();
        $disciplina = $this->disciplinaForContract($tenant, (int) $data['disciplina_id'], $contract);

        if (! $contratante || ! $contratada) {
            throw ValidationException::withMessages([
                'contratante_empresa_id' => 'Selecione empresas vinculadas ao mesmo contrato da obra.',
            ]);
        }

        if (! $disciplina) {
            throw ValidationException::withMessages([
                'disciplina_id' => 'Selecione uma disciplina vinculada ao mesmo contrato da obra.',
            ]);
        }

        DB::transaction(function () use ($request, $tenant, $rnc, $contract, $obra, $projectDocument, $projectDocuments, $disciplina, $contratante, $contratada, $data): void {
            $rnc = RelatorioNaoConformidade::whereKey($rnc->id)->lockForUpdate()->firstOrFail();
            abort_if($rnc->signatureRequests()->whereIn('status', ['draft', 'sent', 'pending', 'completed'])->exists(), 422, 'O documento enviado para assinatura não pode ser alterado.');
            app(\App\Services\RncPdfLogoSnapshots::class)->freezeOne($tenant, $rnc);
            app(\App\Services\RncDocumentSnapshots::class)->freezeOne($tenant, $rnc);
            $values = \App\Support\RncModelFields::values($rnc->pdf_template_snapshot['field_definitions'], $request->input('model_field_values', $rnc->model_field_values ?? []));
            $rnc->update([
                'model_field_values' => $values,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'instrucao_campo_id' => $data['instrucao_campo_id'],
                'project_document_id' => $projectDocument?->id,
                'disciplina_id' => $disciplina->id,
                'contratante_empresa_id' => $contratante->id,
                'contratada_empresa_id' => $contratada->id,
                'opened_at' => $data['opened_at'],
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'natureza' => $disciplina->nome,
                'gravidade' => $data['gravidade'],
                'assunto' => $data['assunto'],
                'descricao_problema' => $data['descricao_problema'],
                'observacao' => $data['observacao'] ?? null,
                'acoes_corretivas_recomendadas' => $data['acoes_corretivas_recomendadas'],
                'prazo_resposta_acao_corretiva' => $data['prazo_resposta_acao_corretiva'],
            ]);

            $rnc->projectDocuments()->sync($projectDocuments->pluck('id')->all());
            $this->syncRncPhotos($request, $tenant, $rnc);
        });

        return redirect()
            ->route('tenant.qualidade.rnc.show', [$tenant, $rnc])
            ->with('success', 'RNC atualizada com sucesso.');
    }

    public function pdf(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc, \App\Services\RncPdfRenderer $renderer)
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::VIEW, $rnc->contract), 403);
        $scope = $request->validate(['scope' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Services\RncPdfRenderer::SCOPES))]])['scope'] ?? 'evidence';
        if ($scope === 'signed') {
            $signature = $rnc->signatureRequests()->where('status', 'completed')->whereNotNull('signed_pdf_path')->first();
            abort_unless($signature && $rnc->finalized_at && Storage::disk($signature->artifactDisk())->exists($signature->signed_pdf_path), 404, 'O PDF completo assinado ainda não está disponível.');
            return response()->download(Storage::disk($signature->artifactDisk())->path($signature->signed_pdf_path), "RNC-{$rnc->formatted_number}-assinado.pdf", ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
        }
        $response = $renderer->render($tenant, $rnc, $scope)->stream("RNC-{$rnc->formatted_number}-{$scope}.pdf");
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }

    private function rncFormOptions(Request $request, Tenant $tenant, ?string $permission = null): array
    {
        $contractIds = $permission
            ? $this->contractIdsForPermission($request, $tenant, $permission)
            : $this->accessibleContracts($request, $tenant)->pluck('id');

        $contracts = $tenant->contracts()
            ->whereIn('id', $contractIds)
            ->with(['obra'])
            ->orderBy('code')
            ->get();

        $icContractIds = IcPermissions::contractIdsFor($request->user(), $tenant, IcPermissions::VIEW);
        $instrucoesCampo = $tenant->instrucoesCampo()->whereIn('contract_id', $contractIds)
            ->when($icContractIds !== null, fn ($query) => $query->whereIn('contract_id', $icContractIds))
            ->whereNotNull('notified_at')->where('status', '!=', 'draft')
            ->orderByDesc('sequence_year')->orderByDesc('sequence_number')
            ->get(['id', 'contract_id', 'obra_id', 'sequence_number', 'sequence_year', 'assunto', 'notified_at']);

        return [
            'instrucoesCampo' => $instrucoesCampo,
            'documentModels' => $contracts->mapWithKeys(function ($contract) use ($tenant) {
                $configuration = \App\Support\RncPdfTemplates::forContract($tenant, $contract->id);
                return [$contract->id => ['layout' => $configuration['layout'], 'field_definitions' => $configuration['field_definitions'],
                    'name' => collect(\App\Support\RncPdfTemplates::catalog())->firstWhere('value', $configuration['layout'])['name']]];
            }),
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->obra?->nome ?? $contract->name,
                'city' => $contract->city,
                'state' => $contract->state,
            ])->values(),
            'obras' => $tenant->obras()
                ->whereIn('contract_id', $contractIds)
                ->with('contract:id,code,name')
                ->orderBy('codigo')
                ->get(['id', 'tenant_id', 'contract_id', 'nome', 'codigo', 'tipo']),
            'empresas' => $tenant->empresas()
                ->whereIn('contract_id', $contractIds)
                ->with('tipoEmpresa:id,nome')
                ->orderBy('nome')
                ->get(['id', 'tenant_id', 'contract_id', 'tipo_empresa_id', 'nome', 'cnpj', 'sigla', 'logo_path']),
            'projects' => $tenant->projectDocuments()
                ->whereIn('contract_id', $contractIds)
                ->with(['latestVersion' => fn ($query) => $query->select([
                    'project_document_versions.id',
                    'project_document_versions.project_document_id',
                    'project_document_versions.revision',
                    'project_document_versions.status',
                ])])
                ->orderBy('code')
                ->orderBy('title')
                ->get(['id', 'tenant_id', 'contract_id', 'obra_id', 'title', 'code', 'status']),
            'disciplinas' => $tenant->disciplinas()
                ->whereIn('contract_id', $contractIds)
                ->orderBy('sigla')
                ->orderBy('nome')
                ->get(['id', 'tenant_id', 'contract_id', 'nome', 'sigla', 'cor']),
            'gravidades' => self::GRAVIDADES,
        ];
    }

    private function validateRncData(Request $request, Tenant $tenant, ?RelatorioNaoConformidade $rnc = null): array
    {
        $rules = [
            'instrucao_campo_id' => ['nullable', 'integer'],
            'obra_id' => [
                'required',
                Rule::exists('obras', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'project_document_id' => [
                'nullable',
                Rule::exists('project_documents', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'project_document_ids' => ['nullable', 'array', 'max:50'],
            'project_document_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('project_documents', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'contratante_empresa_id' => [
                'required',
                Rule::exists('empresas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'contratada_empresa_id' => [
                'required',
                Rule::exists('empresas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'opened_at' => ['required', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'disciplina_id' => [
                'required',
                Rule::exists('disciplinas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'gravidade' => ['required', Rule::in(self::GRAVIDADES)],
            'assunto' => ['required', 'string', 'max:255'],
            'descricao_problema' => ['required', 'string', 'max:10000'],
            'observacao' => ['nullable', 'string', 'max:10000'],
            'acoes_corretivas_recomendadas' => ['required', 'string', 'max:10000'],
            'prazo_resposta_acao_corretiva' => ['required', 'date', 'after_or_equal:opened_at'],
            'photos' => ['nullable', 'array', 'max:12'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'photo_comments' => ['nullable', 'array'],
            'photo_comments.*' => ['nullable', 'string', 'max:1000'],
            'photo_positions' => ['nullable', 'array'],
            'photo_positions.*' => ['nullable', 'integer', 'min:1', 'max:999'],
        ];

        if ($rnc) {
            $rules['sync_existing_photos'] = ['nullable', 'boolean'];
            $rules['existing_photo_ids'] = ['nullable', 'array', 'max:12'];
            $rules['existing_photo_ids.*'] = [
                'integer',
                Rule::exists('relatorio_nao_conformidade_photos', 'id')
                    ->where(fn ($query) => $query
                        ->where('tenant_id', $tenant->id)
                        ->where('relatorio_nao_conformidade_id', $rnc->id)),
            ];
            $rules['existing_photo_comments'] = ['nullable', 'array'];
            $rules['existing_photo_comments.*'] = ['nullable', 'string', 'max:1000'];
            $rules['existing_photo_positions'] = ['nullable', 'array'];
            $rules['existing_photo_positions.*'] = ['nullable', 'integer', 'min:1', 'max:999'];
        }

        $data = $request->validate($rules, [
            'obra_id.required' => 'Selecione a obra.',
            'contratante_empresa_id.required' => 'Selecione a empresa contratante.',
            'contratada_empresa_id.required' => 'Selecione a empresa contratada.',
            'disciplina_id.required' => 'Selecione a disciplina.',
            'disciplina_id.exists' => 'Selecione uma disciplina valida.',
            'prazo_resposta_acao_corretiva.required' => 'Informe o prazo para resposta de ação corretiva.',
            'prazo_resposta_acao_corretiva.after_or_equal' => 'O prazo para resposta de ação corretiva não pode ser anterior à data de abertura.',
            'photos.*.image' => 'Envie apenas imagens no registro fotográfico.',
            'photos.*.max' => 'Cada imagem pode ter no máximo 5 MB.',
        ]);

        $existingPhotoCount = $rnc ? count($request->input('existing_photo_ids', [])) : 0;
        $newPhotoCount = count($request->file('photos', []));

        if ($existingPhotoCount + $newPhotoCount > 12) {
            throw ValidationException::withMessages([
                'photos' => 'A RNC pode ter no máximo 12 imagens.',
            ]);
        }

        return $data;
    }

    private function syncRncPhotos(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): void
    {
        if ($request->boolean('sync_existing_photos')) {
            $existingIds = collect($request->input('existing_photo_ids', []))
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->values();
            $existingComments = collect($request->input('existing_photo_comments', []));
            $existingPositions = collect($request->input('existing_photo_positions', []));
            $currentPhotos = $rnc->photos()->get()->keyBy('id');

            $currentPhotos
                ->reject(fn ($photo) => $existingIds->contains((int) $photo->id))
                ->each(function ($photo): void {
                    Storage::disk('public')->delete($photo->path);
                    $photo->delete();
                });

            $existingIds->each(function (int $photoId, int $index) use ($currentPhotos, $existingComments, $existingPositions): void {
                $photo = $currentPhotos->get($photoId);

                if (! $photo) {
                    return;
                }

                $photo->forceFill([
                    'position' => (int) ($existingPositions->get($index) ?: $index + 1),
                    'comment' => $existingComments->get($index),
                ])->save();
            });
        }

        $comments = collect($request->input('photo_comments', []));
        $positions = collect($request->input('photo_positions', []));

        foreach ($request->file('photos', []) as $index => $photo) {
            $storedPhoto = $this->storeRncPhotoUpload($photo, $tenant, $rnc);

            $rnc->photos()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $request->user()->id,
                'path' => $storedPhoto['path'],
                'original_name' => $photo->getClientOriginalName(),
                'mime_type' => $storedPhoto['mime_type'],
                'size' => $storedPhoto['size'],
                'position' => (int) ($positions->get($index) ?: $index + 1),
                'comment' => $comments->get($index),
            ]);
        }
    }

    private function storeRncPhotoUpload(UploadedFile $photo, Tenant $tenant, RelatorioNaoConformidade $rnc): array
    {
        $directory = "tenant-{$tenant->id}/rnc/{$rnc->id}";
        $mime = strtolower((string) $photo->getClientMimeType());

        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            $convertedPhoto = $this->storeImageAsJpeg($photo->getRealPath(), $directory);

            if ($convertedPhoto) {
                return $convertedPhoto;
            }
        }

        $path = $photo->store($directory, 'public');

        return [
            'path' => $path,
            'mime_type' => $photo->getClientMimeType(),
            'size' => $photo->getSize(),
        ];
    }

    private function storeImageAsJpeg(string $sourcePath, string $directory): ?array
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($sourcePath));

        if (! $source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        imagefill($canvas, 0, 0, $white);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

        Storage::disk('public')->makeDirectory($directory);

        $path = $directory.'/'.Str::random(40).'.jpg';
        $absolutePath = Storage::disk('public')->path($path);
        $saved = imagejpeg($canvas, $absolutePath, 88);

        imagedestroy($source);
        imagedestroy($canvas);

        if (! $saved) {
            return null;
        }

        return [
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size' => @filesize($absolutePath) ?: null,
        ];
    }

    public function notifyResponsibleUsers(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): RedirectResponse
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::NOTIFY, $rnc->contract), 403);
        $recipients = $this->responsibleUsers($tenant, $rnc->contract);

        if ($recipients->isEmpty()) {
            return back()->with('success', 'Nenhum responsavel por RNC cadastrado para este contrato.');
        }

        $notification = new RncResponsibleNotification($rnc, $request->user());

        $recipients->each(fn (User $user) => $user->notify($notification));
        $rnc->forceFill(['notified_at' => now()])->save();

        return back()->with('success', "Notificacao enviada para {$recipients->count()} responsavel(is).");
    }

    public function destroy(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): RedirectResponse
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);

        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::DELETE, $rnc->contract), 403);

        DB::transaction(function () use ($request, $rnc) {
            $locked = RelatorioNaoConformidade::whereKey($rnc->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->signatureRequests()->whereIn('status', ['draft', 'sent', 'pending', 'completed'])->exists(), 422, 'Uma RNC enviada para assinatura não pode ser excluída.');
            $locked->forceFill(['deleted_by_id' => $request->user()->id, 'status' => 'excluida'])->save();
            $locked->delete();
        });

        return redirect()
            ->route('tenant.qualidade.rnc.index', $tenant)
            ->with('success', 'RNC excluida com sucesso. O historico foi mantido no banco.');
    }

    private function accessibleContracts(Request $request, Tenant $tenant)
    {
        $query = $tenant->contracts();
        $tenantRole = $request->user()->tenantRole($tenant);

        if (! in_array($tenantRole, ['tenant_owner', 'tenant_admin'], true)) {
            $query->whereHas('participants', function (Builder $query) use ($request): void {
                $query->where('user_id', $request->user()->id)->where('status', 'active');
            });
        }

        return $query;
    }

    private function contractIdsForPermission(Request $request, Tenant $tenant, string $permission): Collection
    {
        $contractIds = RncPermissions::contractIdsFor($request->user(), $tenant, $permission);

        if ($contractIds !== null) {
            return $contractIds;
        }

        return $this->accessibleContracts($request, $tenant)->pluck('id');
    }

    private function attachUserPermissions(RelatorioNaoConformidade $rnc, User $user, Tenant $tenant): RelatorioNaoConformidade
    {
        $permissions = RncPermissions::permissionsFor($user, $tenant, $rnc->contract);
        $active = $rnc->signatureRequests()->whereIn('status', ['draft', 'sent', 'pending', 'completed'])->first();
        if ($active) $permissions = array_values(array_diff($permissions, [RncPermissions::EDIT, RncPermissions::DELETE]));
        $rnc->setAttribute('has_signed_pdf', (bool)($rnc->finalized_at && $active?->status === 'completed' && $active->signed_pdf_path));
        $rnc->syncOriginalAttribute('has_signed_pdf');
        $rnc->setAttribute('user_permissions', $permissions);
        $rnc->syncOriginalAttribute('user_permissions');
        if ($rnc->relationLoaded('instrucaoCampo') && $rnc->instrucaoCampo) {
            $rnc->instrucaoCampo->setAttribute('can_view', ! $rnc->instrucaoCampo->trashed()
                && IcPermissions::can($user, $tenant, IcPermissions::VIEW, $rnc->contract));
        }

        return $rnc;
    }

    private function icReferenceForRnc(Request $request, Tenant $tenant, ?int $id, Contract $contract, Obra $obra, ?RelatorioNaoConformidade $rnc = null): ?int
    {
        if ($id === null) {
            return null;
        }
        $ic = $tenant->instrucoesCampo()->withTrashed()->where('contract_id', $contract->id)->where('obra_id', $obra->id)->find($id);
        // Preserve an existing reference when editing, even if IC access was later revoked.
        if ($ic && $rnc && (int) $rnc->instrucao_campo_id === $id) {
            return $ic->id;
        }
        if (! $ic || $ic->trashed() || ! $ic->notified_at || $ic->status === 'draft'
            || ! IcPermissions::can($request->user(), $tenant, IcPermissions::VIEW, $contract)) {
            throw ValidationException::withMessages(['instrucao_campo_id' => 'Selecione uma IC já comunicada, da mesma obra, que você tenha permissão para visualizar.']);
        }

        return $ic->id;
    }

    private function canAccessContract(User $user, Tenant $tenant, Contract $contract): bool
    {
        if (in_array($user->tenantRole($tenant), ['tenant_owner', 'tenant_admin'], true)) {
            return true;
        }

        return $contract->participants()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    private function canDeleteRncs(User $user, Tenant $tenant): bool
    {
        return RncPermissions::canAny($user, $tenant, RncPermissions::DELETE);
    }

    private function isFinalizedRnc(RelatorioNaoConformidade $rnc): bool
    {
        return $rnc->status === 'finalizada' || $rnc->finalized_at !== null;
    }

    private function isResponseOverdueRnc(RelatorioNaoConformidade $rnc, Carbon $today): bool
    {
        if (! $rnc->prazo_resposta_acao_corretiva) {
            return false;
        }

        $latestAction = $rnc->acoesCorretivas->first();
        $deadline = $rnc->prazo_resposta_acao_corretiva->copy()->startOfDay();

        if ($latestAction?->submitted_at && $latestAction->submitted_at->copy()->startOfDay()->gt($deadline)) {
            return true;
        }

        $awaitingResponse = $latestAction === null || $latestAction->status === 'rejected';

        return ! $this->isFinalizedRnc($rnc)
            && $awaitingResponse
            && $deadline->lt($today);
    }

    private function isExecutionOverdueRnc(RelatorioNaoConformidade $rnc, Carbon $today): bool
    {
        $approvedAction = $rnc->acoesCorretivas->firstWhere('status', 'approved');

        if (! $approvedAction?->prazo_execucao_proposto) {
            return false;
        }

        $deadline = $approvedAction->prazo_execucao_proposto->copy()->startOfDay();
        $evidence = $rnc->evidencias->firstWhere('relatorio_nao_conformidade_acao_corretiva_id', $approvedAction->id)
            ?? $rnc->evidencias->first();

        if ($evidence?->submitted_at) {
            return $evidence->submitted_at->copy()->startOfDay()->gt($deadline);
        }

        return ! $this->isFinalizedRnc($rnc) && $deadline->lt($today);
    }

    /**
     * @param  Collection<int, RelatorioNaoConformidade>  $rncs
     * @return array<string, int>
     */
    private function countBy(Collection $rncs, string $field): array
    {
        return $rncs
            ->groupBy(fn (RelatorioNaoConformidade $rnc): string => (string) ($rnc->{$field} ?: 'Sem valor'))
            ->map(fn (Collection $items): int => $items->count())
            ->sortDesc()
            ->all();
    }

    /**
     * @param  Collection<int, RelatorioNaoConformidade>  $rncs
     * @return array<string, int>
     */
    private function countRncDisciplines(Collection $rncs): array
    {
        return $rncs
            ->groupBy(fn (RelatorioNaoConformidade $rnc): string => $this->disciplinaLabel($rnc))
            ->map(fn (Collection $items): int => $items->count())
            ->sortDesc()
            ->all();
    }

    private function disciplinaLabel(RelatorioNaoConformidade $rnc): string
    {
        if ($rnc->disciplina?->sigla) {
            return $rnc->disciplina->sigla.' - '.$rnc->disciplina->nome;
        }

        return $rnc->disciplina?->nome ?: ($rnc->natureza ?: 'Sem disciplina');
    }

    private function canReviewRncProposals(User $user, Tenant $tenant): bool
    {
        return RncPermissions::canAny($user, $tenant, RncPermissions::REVIEW);
    }

    private function empresaForContract(Tenant $tenant, int $empresaId, Contract $contract): ?Empresa
    {
        return $tenant->empresas()
            ->where('id', $empresaId)
            ->where('contract_id', $contract->id)
            ->first();
    }

    private function disciplinaForContract(Tenant $tenant, int $disciplinaId, Contract $contract): ?Disciplina
    {
        return $tenant->disciplinas()
            ->where('id', $disciplinaId)
            ->where('contract_id', $contract->id)
            ->first();
    }

    /**
     * @return Collection<int, ProjectDocument>
     */
    private function projectDocumentsForRnc(
        Tenant $tenant,
        mixed $projectDocumentIds,
        Contract $contract,
        Obra $obra,
        string $errorKey = 'project_document_ids'
    ): Collection {
        $ids = collect(is_array($projectDocumentIds) ? $projectDocumentIds : [$projectDocumentIds])
            ->filter(fn ($id): bool => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $projectDocuments = $tenant->projectDocuments()
            ->whereIn('id', $ids)
            ->where('contract_id', $contract->id)
            ->where('obra_id', $obra->id)
            ->get()
            ->sortBy(fn (ProjectDocument $project): int => $ids->search($project->id))
            ->values();

        if ($projectDocuments->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                $errorKey => 'Selecione apenas projetos vinculados a mesma obra e ao mesmo contrato da RNC.',
            ]);
        }

        return $projectDocuments;
    }

    /**
     * @return Collection<int, User>
     */
    private function responsibleUsers(Tenant $tenant, Contract $contract): Collection
    {
        return RelatorioNaoConformidadeResponsavel::query()
            ->where('status', 'active')
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->with('user:id,name,email')
            ->get()
            ->pluck('user')
            ->filter(fn (?User $user): bool => $user !== null && $this->canAccessContract($user, $tenant, $contract))
            ->unique('id')
            ->values();
    }

    private function loadAccessibleRnc(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): RelatorioNaoConformidade
    {
        abort_unless((int) $rnc->tenant_id === (int) $tenant->id, 404);

        $rnc->load([
            'instrucaoCampo:id,contract_id,obra_id,sequence_number,sequence_year,assunto,notified_at,deleted_at',
            'contract:id,tenant_id,code,name,total_value,currency,starts_at,ends_at,city,state',
            'obra:id,tenant_id,contract_id,nome,codigo,tipo',
            'projectDocument:id,tenant_id,contract_id,obra_id,title,code,document_number,status',
            'projectDocument.latestVersion' => fn ($query) => $query->select([
                'project_document_versions.id',
                'project_document_versions.project_document_id',
                'project_document_versions.revision',
                'project_document_versions.status',
            ]),
            'projectDocuments:id,tenant_id,contract_id,obra_id,title,code,document_number,status',
            'projectDocuments.latestVersion' => fn ($query) => $query->select([
                'project_document_versions.id',
                'project_document_versions.project_document_id',
                'project_document_versions.revision',
                'project_document_versions.status',
            ]),
            'disciplina:id,tenant_id,contract_id,nome,sigla,cor',
            'contratante:id,nome,cnpj,sigla,logo_path',
            'contratada:id,nome,cnpj,sigla,logo_path',
            'creator:id,name,email',
            'finalizedBy:id,name,email',
            'photos:id,tenant_id,relatorio_nao_conformidade_id,path,position,comment,original_name,mime_type',
            'acoesCorretivas.user:id,name,email,avatar_url',
            'acoesCorretivas.reviewer:id,name,email,avatar_url',
            'evidencias.user:id,name,email,avatar_url',
            'evidencias.photos:id,tenant_id,relatorio_nao_conformidade_evidencia_id,path,position,comment,original_name,mime_type',
        ]);

        abort_unless($this->canAccessContract($request->user(), $tenant, $rnc->contract), 403);

        return $this->attachUserPermissions($rnc, $request->user(), $tenant);
    }
}

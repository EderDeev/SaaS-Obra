<?php

namespace App\Http\Controllers\Tenant\Qualidade;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Disciplina;
use App\Models\Empresa;
use App\Models\Obra;
use App\Models\ProjectDocument;
use App\Models\RelatorioNaoConformidade;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\RncResponsibleNotification;
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

        $rncs = $tenant->relatorioNaoConformidades()
            ->whereIn('contract_id', $contractIds)
            ->with([
                'contract:id,code,name',
                'obra:id,nome,codigo',
                'disciplina:id,nome,sigla,cor',
                'contratante:id,nome,sigla,logo_path',
                'contratada:id,nome,sigla,logo_path',
                'creator:id,name',
                'acoesCorretivas:id,tenant_id,relatorio_nao_conformidade_id,user_id,status,review_observation,prazo_execucao_proposto,submitted_at,reviewed_at,reviewed_by_id',
                'acoesCorretivas.user:id,name',
                'acoesCorretivas.reviewer:id,name',
            ])
            ->withCount(['photos', 'evidencias'])
            ->latest()
            ->get();
        $rncs->each(fn (RelatorioNaoConformidade $rnc): RelatorioNaoConformidade => $this->attachUserPermissions($rnc, $request->user(), $tenant));

        return Inertia::render('Tenant/Qualidade/RelatorioNaoConformidade/Index', [
            'tenant' => $tenant,
            'rncs' => $rncs,
            'canCreateRnc' => RncPermissions::canAny($request->user(), $tenant, RncPermissions::CREATE),
            'canDeleteRncs' => $this->canDeleteRncs($request->user(), $tenant),
            'canReviewRncProposals' => $this->canReviewRncProposals($request->user(), $tenant),
        ]);
    }

    public function dashboard(Request $request, Tenant $tenant): InertiaResponse
    {
        abort_unless(RncPermissions::canAny($request->user(), $tenant, RncPermissions::DASHBOARD), 403);

        $contractIds = $this->contractIdsForPermission($request, $tenant, RncPermissions::DASHBOARD);
        $rncs = $tenant->relatorioNaoConformidades()
            ->whereIn('contract_id', $contractIds)
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

        $today = now()->startOfDay();
        $finalizadas = $rncs->filter(fn (RelatorioNaoConformidade $rnc): bool => $this->isFinalizedRnc($rnc));
        $atrasadasResposta = $rncs->filter(fn (RelatorioNaoConformidade $rnc): bool => $this->isResponseOverdueRnc($rnc, $today));
        $atrasadasExecucao = $rncs->filter(fn (RelatorioNaoConformidade $rnc): bool => $this->isExecutionOverdueRnc($rnc, $today));
        $emAnalise = $rncs->filter(fn (RelatorioNaoConformidade $rnc): bool => $rnc->acoesCorretivas->first()?->status === 'pending');
        $aguardandoEvidencia = $rncs->filter(fn (RelatorioNaoConformidade $rnc): bool => ! $this->isFinalizedRnc($rnc)
            && $rnc->acoesCorretivas->first()?->status === 'approved');
        $monthlyCounts = collect(range(5, 0))
            ->mapWithKeys(function (int $monthsAgo) use ($rncs): array {
                $date = now()->copy()->subMonths($monthsAgo);
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
            'metrics' => [
                'total' => $rncs->count(),
                'abertas' => $rncs->where('status', 'aberta')->count(),
                'atrasoResposta' => $atrasadasResposta->count(),
                'atrasoExecucao' => $atrasadasExecucao->count(),
                'emAnalise' => $emAnalise->count(),
                'aguardandoEvidencia' => $aguardandoEvidencia->count(),
                'finalizadas' => $finalizadas->count(),
            ],
            'statusCounts' => $this->countBy($rncs, 'status'),
            'gravidadeCounts' => $this->countBy($rncs, 'gravidade'),
            'disciplinaCounts' => $this->countRncDisciplines($rncs),
            'monthlyCounts' => $monthlyCounts,
            'recentRncs' => $rncs->take(8)->values(),
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
        ]);
    }

    public function edit(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): InertiaResponse
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::EDIT, $rnc->contract), 403);

        return Inertia::render('Tenant/Qualidade/RelatorioNaoConformidade/Create', [
            'tenant' => $tenant,
            'mode' => 'edit',
            'rnc' => $rnc,
            ...$this->rncFormOptions($request, $tenant, RncPermissions::EDIT),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'obra_id' => [
                'required',
                Rule::exists('obras', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'project_document_id' => [
                'nullable',
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

        $contratante = $this->empresaForContract($tenant, (int) $data['contratante_empresa_id'], $contract);
        $contratada = $this->empresaForContract($tenant, (int) $data['contratada_empresa_id'], $contract);
        $projectDocument = $this->projectDocumentForRnc($tenant, $data['project_document_id'] ?? null, $contract, $obra);
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
        $rnc = DB::transaction(function () use ($tenant, $contract, $obra, $projectDocument, $disciplina, $contratante, $contratada, $request, $data, $sequenceYear): RelatorioNaoConformidade {
            $nextSequence = ((int) $tenant->relatorioNaoConformidades()
                ->where('sequence_year', $sequenceYear)
                ->lockForUpdate()
                ->max('sequence_number')) + 1;

            return $tenant->relatorioNaoConformidades()->create([
                'sequence_number' => $nextSequence,
                'sequence_year' => $sequenceYear,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
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
                'descricao_problema' => $data['descricao_problema'],
                'observacao' => $data['observacao'] ?? null,
                'acoes_corretivas_recomendadas' => $data['acoes_corretivas_recomendadas'],
                'prazo_resposta_acao_corretiva' => $data['prazo_resposta_acao_corretiva'],
                'status' => 'aberta',
            ]);
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

        $contratante = $this->empresaForContract($tenant, (int) $data['contratante_empresa_id'], $contract);
        $contratada = $this->empresaForContract($tenant, (int) $data['contratada_empresa_id'], $contract);
        $projectDocument = $this->projectDocumentForRnc($tenant, $data['project_document_id'] ?? null, $contract, $obra);
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

        $rnc->update([
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'project_document_id' => $projectDocument?->id,
            'disciplina_id' => $disciplina->id,
            'contratante_empresa_id' => $contratante->id,
            'contratada_empresa_id' => $contratada->id,
            'opened_at' => $data['opened_at'],
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'natureza' => $disciplina->nome,
            'gravidade' => $data['gravidade'],
            'descricao_problema' => $data['descricao_problema'],
            'observacao' => $data['observacao'] ?? null,
            'acoes_corretivas_recomendadas' => $data['acoes_corretivas_recomendadas'],
            'prazo_resposta_acao_corretiva' => $data['prazo_resposta_acao_corretiva'],
        ]);

        $this->syncRncPhotos($request, $tenant, $rnc);

        return redirect()
            ->route('tenant.qualidade.rnc.show', [$tenant, $rnc])
            ->with('success', 'RNC atualizada com sucesso.');
    }

    public function pdf(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc)
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::VIEW, $rnc->contract), 403);
        $canRasterizePdfImages = $this->canRasterizePdfImages();
        $approvedAction = $rnc->acoesCorretivas->firstWhere('status', 'approved');
        $latestAction = $rnc->acoesCorretivas->first();
        $latestEvidence = $rnc->evidencias->first();
        $photos = $this->formatPdfPhotos($rnc->photos, $canRasterizePdfImages);
        $evidencePhotos = $latestEvidence
            ? $this->formatPdfPhotos($latestEvidence->photos, $canRasterizePdfImages)
            : collect();
        $contratanteLogo = $this->companyLogoDataUri($rnc->contratante);
        $contratadaLogo = $this->companyLogoDataUri($rnc->contratada);

        $pdf = Pdf::loadView('pdf.rnc', [
            'tenant' => $tenant,
            'rnc' => $rnc,
            'contratanteLogo' => $contratanteLogo,
            'contratadaLogo' => $contratadaLogo,
            'photos' => $photos,
            'approvedAction' => $approvedAction,
            'latestAction' => $latestAction,
            'latestEvidence' => $latestEvidence,
            'evidencePhotos' => $evidencePhotos,
            'flowRows' => $this->rncFlowRows($rnc, $latestAction, $latestEvidence),
            'canRasterizePdfImages' => $canRasterizePdfImages,
        ])->setPaper('a4');

        $fileName = sprintf(
            'rnc-%s-%s.pdf',
            $rnc->contract?->code ? str($rnc->contract->code)->slug()->toString() : 'contrato',
            str($rnc->formatted_number)->slug()->toString(),
        );

        $response = $pdf->stream($fileName);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    private function formatPdfPhotos(Collection $photos, bool $canRasterizePdfImages): Collection
    {
        return $photos->map(function ($photo) use ($canRasterizePdfImages): array {
            $dataUri = null;
            $canEmbedImage = false;
            $mime = $photo->mime_type ?: (Storage::disk('public')->exists($photo->path) ? Storage::disk('public')->mimeType($photo->path) : null);

            if ($mime) {
                $canEmbedImage = in_array(strtolower($mime), ['image/jpeg', 'image/jpg'], true) || $canRasterizePdfImages;
            }

            if ($canEmbedImage && Storage::disk('public')->exists($photo->path)) {
                $absolutePath = Storage::disk('public')->path($photo->path);
                $dataUri = $this->standardizedPdfPhotoDataUri($absolutePath)
                    ?: 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($photo->path));
            }

            return [
                'position' => $photo->position,
                'comment' => $photo->comment ?? null,
                'original_name' => $photo->original_name,
                'data_uri' => $dataUri,
                'mime_type' => $mime,
                'needs_raster_extension' => ! $dataUri && ! in_array(strtolower((string) $mime), ['image/jpeg', 'image/jpg'], true),
            ];
        })->values();
    }

    private function rncFlowRows(RelatorioNaoConformidade $rnc, $latestAction, $latestEvidence): array
    {
        $proposalReview = $latestAction
            ? match ($latestAction->status) {
                'approved' => [
                    'status' => 'Aprovada',
                    'data' => $latestAction->reviewed_at?->format('d/m/Y H:i') ?: '-',
                    'detalhe' => 'Aprovada por '.($latestAction->reviewer?->name ?: 'usuario responsavel').'. Pode iniciar o processo corretivo.',
                ],
                'rejected' => [
                    'status' => 'Reprovada',
                    'data' => $latestAction->reviewed_at?->format('d/m/Y H:i') ?: '-',
                    'detalhe' => 'Reprovada por '.($latestAction->reviewer?->name ?: 'usuario responsavel').'. Motivo: '.($latestAction->review_observation ?: '-'),
                ],
                default => [
                    'status' => 'Em analise',
                    'data' => '-',
                    'detalhe' => 'Aguardando analise da proposta enviada.',
                ],
            }
            : [
                'status' => 'Pendente',
                'data' => '-',
                'detalhe' => 'Aguardando envio da proposta de acao corretiva.',
            ];

        return [
            [
                'etapa' => 'Abertura da RNC',
                'status' => 'Concluida',
                'data' => $rnc->opened_at?->format('d/m/Y') ?: '-',
                'responsavel' => $rnc->creator?->name ?: '-',
                'detalhe' => 'Registro inicial da nao conformidade.',
            ],
            [
                'etapa' => 'Notificacao aos responsaveis',
                'status' => $rnc->notified_at ? 'Concluida' : 'Pendente',
                'data' => $rnc->notified_at?->format('d/m/Y H:i') ?: '-',
                'responsavel' => 'Responsaveis da RNC',
                'detalhe' => $rnc->notified_at ? 'Responsaveis notificados por email e alerta interno.' : 'Aguardando envio da notificacao.',
            ],
            [
                'etapa' => 'Proposta de acao corretiva',
                'status' => $latestAction ? 'Enviada' : 'Pendente',
                'data' => $latestAction?->submitted_at?->format('d/m/Y H:i') ?: '-',
                'responsavel' => $latestAction?->user?->name ?: '-',
                'detalhe' => $latestAction
                    ? 'Prazo proposto: '.($latestAction->prazo_execucao_proposto?->format('d/m/Y') ?: '-')
                    : 'Aguardando proposta do responsavel.',
            ],
            [
                'etapa' => 'Analise da proposta',
                'status' => $proposalReview['status'],
                'data' => $proposalReview['data'],
                'responsavel' => $latestAction?->reviewer?->name ?: '-',
                'detalhe' => $proposalReview['detalhe'],
            ],
            [
                'etapa' => 'Evidencias da correcao',
                'status' => $latestEvidence || $rnc->finalized_at ? 'Finalizada' : ($latestAction?->status === 'approved' ? 'Pendente' : 'Aguardando'),
                'data' => $latestEvidence?->submitted_at?->format('d/m/Y H:i') ?: ($rnc->finalized_at?->format('d/m/Y H:i') ?: '-'),
                'responsavel' => $latestEvidence?->user?->name ?: ($rnc->finalizedBy?->name ?: '-'),
                'detalhe' => $latestEvidence
                    ? 'Evidencias enviadas e RNC finalizada.'
                    : ($latestAction?->status === 'approved' ? 'Aguardando envio das evidencias da correcao.' : 'Aguardando aprovacao da proposta.'),
            ],
        ];
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

        return [
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->obra?->nome ?? $contract->name,
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
            'obra_id' => [
                'required',
                Rule::exists('obras', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'project_document_id' => [
                'nullable',
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

    private function canRasterizePdfImages(): bool
    {
        return extension_loaded('imagick')
            || (function_exists('imagepng') && function_exists('imagecreatefrompng'));
    }

    private function standardizedPdfPhotoDataUri(string $sourcePath): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg') || ! function_exists('imagecopyresampled')) {
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($sourcePath));

        if (! $source) {
            return null;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $canvas = imagecreatetruecolor(self::PDF_PHOTO_WIDTH, self::PDF_PHOTO_HEIGHT);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        imagefill($canvas, 0, 0, $white);

        $targetRatio = self::PDF_PHOTO_WIDTH / self::PDF_PHOTO_HEIGHT;
        $sourceRatio = $sourceWidth / $sourceHeight;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) round($sourceHeight * $targetRatio);
            $cropX = (int) floor(($sourceWidth - $cropWidth) / 2);
            $cropY = 0;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($sourceWidth / $targetRatio);
            $cropX = 0;
            $cropY = (int) floor(($sourceHeight - $cropHeight) / 2);
        }

        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            $cropX,
            $cropY,
            self::PDF_PHOTO_WIDTH,
            self::PDF_PHOTO_HEIGHT,
            $cropWidth,
            $cropHeight,
        );

        ob_start();
        imagejpeg($canvas, null, 88);
        $jpeg = ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        if (! $jpeg) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

    private function companyLogoDataUri(?Empresa $empresa): ?string
    {
        if (! $empresa?->logo_path || ! Storage::disk('public')->exists($empresa->logo_path)) {
            return null;
        }

        return $this->containedPdfImageDataUri(
            Storage::disk('public')->path($empresa->logo_path),
            self::PDF_LOGO_WIDTH,
            self::PDF_LOGO_HEIGHT,
        );
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

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $canvas = imagecreatetruecolor($targetCanvasWidth, $targetCanvasHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        imagefill($canvas, 0, 0, $white);

        $scale = min($targetCanvasWidth / $sourceWidth, $targetCanvasHeight / $sourceHeight);
        $targetWidth = (int) round($sourceWidth * $scale);
        $targetHeight = (int) round($sourceHeight * $scale);
        $targetX = (int) floor(($targetCanvasWidth - $targetWidth) / 2);
        $targetY = (int) floor(($targetCanvasHeight - $targetHeight) / 2);

        imagecopyresampled(
            $canvas,
            $source,
            $targetX,
            $targetY,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        if (! $jpeg) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
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

        $rnc->forceFill([
            'deleted_by_id' => $request->user()->id,
            'status' => 'excluida',
        ])->save();

        $rnc->delete();

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
        $rnc->setAttribute('user_permissions', RncPermissions::permissionsFor($user, $tenant, $rnc->contract));
        $rnc->syncOriginalAttribute('user_permissions');

        return $rnc;
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
        return $rnc->status === 'finalizada' || $rnc->finalized_at !== null || $rnc->evidencias_count > 0;
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

    private function projectDocumentForRnc(Tenant $tenant, mixed $projectDocumentId, Contract $contract, Obra $obra): ?ProjectDocument
    {
        if (blank($projectDocumentId)) {
            return null;
        }

        $projectDocument = $tenant->projectDocuments()
            ->whereKey($projectDocumentId)
            ->where('contract_id', $contract->id)
            ->where('obra_id', $obra->id)
            ->first();

        if (! $projectDocument) {
            throw ValidationException::withMessages([
                'project_document_id' => 'Selecione um projeto vinculado a mesma obra e ao mesmo contrato da RNC.',
            ]);
        }

        return $projectDocument;
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
            'contract:id,tenant_id,code,name,total_value,currency,starts_at,ends_at,city,state',
            'obra:id,tenant_id,contract_id,nome,codigo,tipo',
            'projectDocument:id,tenant_id,contract_id,obra_id,title,code,document_number,status',
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

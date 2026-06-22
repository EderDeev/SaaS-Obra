<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessProjectVersionApsJob;
use App\Models\Contract;
use App\Models\Disciplina;
use App\Models\Obra;
use App\Models\ProjectDisciplineResponsavel;
use App\Models\ProjectDocument;
use App\Models\ProjectDocumentVersion;
use App\Models\ProjectPhase;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ProjectSubmittedForReviewNotification;
use App\Services\AutodeskApsService;
use App\Support\ProjectCap;
use App\Support\ProjectPermissions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    private const DOCUMENT_TYPES = [
        'projeto' => 'Projeto',
        'prancha' => 'Prancha',
        'modelo_bim' => 'Modelo BIM',
        'memorial' => 'Memorial',
        'especificacao' => 'Especificacao',
        'outro' => 'Outro',
    ];

    private const DOCUMENT_TYPE_CODES = [
        'projeto' => 'PRJ',
        'prancha' => 'PRA',
        'modelo_bim' => 'BIM',
        'memorial' => 'MEM',
        'especificacao' => 'ESP',
        'outro' => 'OUT',
    ];

    private const STATUS_LABELS = [
        'em_analise' => 'Em analise',
        'em_aprovacao' => 'Em aprovacao',
        'ativo' => 'Aprovado',
        'inativo' => 'Inativo',
        'reprovado' => 'Reprovado',
    ];

    private const ALLOWED_EXTENSIONS = [
        'dwg',
        'ifc',
        'rvt',
        'pdf',
        'dwfx',
        'dwf',
    ];

    public function index(Request $request, Tenant $tenant): Response
    {
        abort_unless(ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::VIEW), 403);

        $contracts = $this->accessibleContracts($request, $tenant, ProjectPermissions::VIEW)
            ->with('obra:id,nome')
            ->orderBy('code')
            ->get();
        $contractIds = $contracts->pluck('id');

        $documents = $tenant->projectDocuments()
            ->whereIn('contract_id', $contractIds)
            ->withCount(['rncs as open_rncs_count' => fn (Builder $query): Builder => $query->where('status', 'aberta')])
            ->with([
                'contract:id,code,name,obra_id',
                'contract.obra:id,nome',
                'obra:id,nome,codigo',
                'disciplina:id,nome,sigla,cor',
                'phase:id,name,code',
                'creator:id,name,email',
                'reviewer:id,name,email',
                'approver:id,name,email',
                'inactiveBy:id,name,email',
                'openRncs:id,tenant_id,project_document_id,sequence_number,sequence_year,opened_at,status',
                'latestVersion.uploader:id,name,email',
                'latestVersion.reviewer:id,name,email',
                'latestVersion.approver:id,name,email',
                'latestVersion.capRequester:id,name,email',
            ])
            ->latest()
            ->get();

        $disciplinas = $tenant->disciplinas()
            ->whereIn('contract_id', $contractIds)
            ->orderBy('nome')
            ->get(['id', 'contract_id', 'nome', 'sigla', 'cor']);

        $obras = $tenant->obras()
            ->whereIn('contract_id', $contractIds)
            ->orderBy('nome')
            ->get(['id', 'contract_id', 'obra_pai_id', 'nome', 'codigo', 'tipo']);

        $projectPhases = ProjectPhase::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return Inertia::render('Tenant/Projects/Index', [
            'tenant' => $tenant,
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->obra?->nome ?? $contract->name,
                'status' => $contract->status,
            ])->values(),
            'obras' => $obras->map(fn (Obra $obra): array => [
                'id' => $obra->id,
                'contract_id' => $obra->contract_id,
                'obra_pai_id' => $obra->obra_pai_id,
                'nome' => $obra->nome,
                'codigo' => $obra->codigo,
                'tipo' => $obra->tipo,
            ])->values(),
            'disciplinas' => $disciplinas,
            'projectPhases' => $projectPhases,
            'documents' => $documents,
            'documentTypes' => self::DOCUMENT_TYPES,
            'documentTypeCodes' => self::DOCUMENT_TYPE_CODES,
            'statusLabels' => self::STATUS_LABELS,
            'capImpactLabels' => ProjectCap::IMPACT_LABELS,
            'allowedExtensions' => self::ALLOWED_EXTENSIONS,
            'canUploadProjects' => $contracts->contains(fn (Contract $contract): bool => ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::UPLOAD, $contract)),
            'canAnalyzeProjects' => ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::REVIEW),
            'canDeleteProjects' => ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::DELETE),
        ]);
    }

    public function revisions(Request $request, Tenant $tenant): Response
    {
        abort_unless(ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::VIEW), 403);

        $contracts = $this->accessibleContracts($request, $tenant, ProjectPermissions::VIEW)
            ->with('obra:id,nome')
            ->orderBy('code')
            ->get();
        $contractIds = $contracts->pluck('id');

        $documents = $tenant->projectDocuments()
            ->whereIn('contract_id', $contractIds)
            ->whereHas('versions', fn (Builder $query): Builder => $query->whereNotNull('cap_number'))
            ->with([
                'contract:id,code,name,obra_id',
                'contract.obra:id,nome',
                'obra:id,nome,codigo',
                'disciplina:id,nome,sigla,cor',
                'phase:id,name,code',
                'creator:id,name,email',
                'reviewer:id,name,email',
                'approver:id,name,email',
                'versions' => fn ($query) => $query
                    ->with([
                        'uploader:id,name,email',
                        'reviewer:id,name,email',
                        'approver:id,name,email',
                        'capRequester:id,name,email',
                        'reviewMarkups' => fn ($query) => $query
                            ->with([
                                'creator:id,name,email',
                                'assignee:id,name,email',
                            ])
                            ->latest(),
                        'reviewChecklist.items.checkedBy:id,name,email',
                    ])
                    ->orderBy('created_at')
                    ->orderBy('id'),
            ])
            ->latest()
            ->get();

        return Inertia::render('Tenant/Projects/Revisions', [
            'tenant' => $tenant,
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->obra?->nome ?? $contract->name,
                'status' => $contract->status,
            ])->values(),
            'documents' => $documents,
            'documentTypes' => self::DOCUMENT_TYPES,
            'statusLabels' => self::STATUS_LABELS,
            'capImpactLabels' => ProjectCap::IMPACT_LABELS,
            'canReviewProjects' => ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::REVIEW),
        ]);
    }

    public function tree(Request $request, Tenant $tenant): Response
    {
        abort_unless(ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::VIEW), 403);

        $contracts = $this->accessibleContracts($request, $tenant, ProjectPermissions::VIEW)
            ->with('obra:id,nome')
            ->orderBy('code')
            ->get();
        $contractIds = $contracts->pluck('id');

        $documents = $tenant->projectDocuments()
            ->whereIn('contract_id', $contractIds)
            ->whereNull('inactive_at')
            ->whereHas('versions', fn (Builder $query) => $query->where('status', 'ativo'))
            ->withCount(['rncs as open_rncs_count' => fn (Builder $query): Builder => $query->where('status', 'aberta')])
            ->with([
                'contract:id,code,name,obra_id',
                'contract.obra:id,nome',
                'obra:id,nome,codigo',
                'disciplina:id,nome,sigla,cor',
                'phase:id,name,code',
                'creator:id,name,email',
                'reviewer:id,name,email',
                'approver:id,name,email',
                'openRncs:id,tenant_id,project_document_id,sequence_number,sequence_year,opened_at,status',
                'latestVersion',
                'latestApprovedVersion.uploader:id,name,email',
                'latestApprovedVersion.reviewer:id,name,email',
                'latestApprovedVersion.approver:id,name,email',
            ])
            ->orderBy('code')
            ->get();

        $obras = $tenant->obras()
            ->whereIn('contract_id', $contractIds)
            ->whereIn('id', $documents->pluck('obra_id')->filter()->unique()->values())
            ->orderBy('codigo')
            ->orderBy('nome')
            ->get(['id', 'contract_id', 'obra_pai_id', 'nome', 'codigo', 'tipo']);

        $disciplinas = $tenant->disciplinas()
            ->whereIn('contract_id', $contractIds)
            ->whereIn('id', $documents->pluck('disciplina_id')->filter()->unique()->values())
            ->orderBy('sigla')
            ->orderBy('nome')
            ->get(['id', 'contract_id', 'nome', 'sigla', 'cor']);

        return Inertia::render('Tenant/Projects/Tree', [
            'tenant' => $tenant,
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->obra?->nome ?? $contract->name,
                'status' => $contract->status,
            ])->values(),
            'obras' => $obras->map(fn (Obra $obra): array => [
                'id' => $obra->id,
                'contract_id' => $obra->contract_id,
                'obra_pai_id' => $obra->obra_pai_id,
                'nome' => $obra->nome,
                'codigo' => $obra->codigo,
                'tipo' => $obra->tipo,
            ])->values(),
            'disciplinas' => $disciplinas,
            'documents' => $documents,
            'documentTypes' => self::DOCUMENT_TYPES,
        ]);
    }

    public function masterList(Request $request, Tenant $tenant): Response
    {
        abort_unless(ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::VIEW), 403);

        $contracts = $this->masterListContracts($request, $tenant);
        $contractIds = $contracts->pluck('id');
        $documents = $this->masterListDocumentsQuery($request, $tenant, $contractIds)
            ->latest()
            ->paginate(50)
            ->withQueryString()
            ->through(fn (ProjectDocument $document): array => $this->serializeMasterListDocument($document));

        $obras = $tenant->obras()
            ->whereIn('contract_id', $contractIds)
            ->orderBy('codigo')
            ->orderBy('nome')
            ->get(['id', 'contract_id', 'obra_pai_id', 'nome', 'codigo', 'tipo']);

        $disciplinas = $tenant->disciplinas()
            ->whereIn('contract_id', $contractIds)
            ->orderBy('sigla')
            ->orderBy('nome')
            ->get(['id', 'contract_id', 'nome', 'sigla', 'cor']);

        $projectPhases = ProjectPhase::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return Inertia::render('Tenant/Projects/MasterList', [
            'tenant' => $tenant,
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->obra?->nome ?? $contract->name,
                'status' => $contract->status,
            ])->values(),
            'obras' => $obras->map(fn (Obra $obra): array => [
                'id' => $obra->id,
                'contract_id' => $obra->contract_id,
                'obra_pai_id' => $obra->obra_pai_id,
                'nome' => $obra->nome,
                'codigo' => $obra->codigo,
                'tipo' => $obra->tipo,
            ])->values(),
            'disciplinas' => $disciplinas,
            'projectPhases' => $projectPhases,
            'documents' => $documents,
            'documentTypes' => self::DOCUMENT_TYPES,
            'statusLabels' => self::STATUS_LABELS,
            'filters' => $this->masterListFilters($request),
            'totalDocuments' => $tenant->projectDocuments()
                ->whereIn('contract_id', $contractIds)
                ->count(),
        ]);
    }

    public function masterListPdf(Request $request, Tenant $tenant)
    {
        abort_unless(ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::VIEW), 403);

        $contracts = $this->masterListContracts($request, $tenant);
        $documents = $this->masterListDocumentsQuery($request, $tenant, $contracts->pluck('id'))
            ->orderBy('code')
            ->get()
            ->map(fn (ProjectDocument $document): array => $this->serializeMasterListDocument($document));

        $pdf = Pdf::loadView('pdf.project-master-list', [
            'tenant' => $tenant,
            'documents' => $documents,
            'filters' => $this->masterListFilters($request),
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape');

        $fileName = 'lista-mestra-projetos-'.now()->format('Ymd-His').'.pdf';
        $response = $pdf->download($fileName);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

        return $response;
    }

    public function masterListExcel(Request $request, Tenant $tenant)
    {
        abort_unless(ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::VIEW), 403);

        $contracts = $this->masterListContracts($request, $tenant);
        $documents = $this->masterListDocumentsQuery($request, $tenant, $contracts->pluck('id'))
            ->orderBy('code')
            ->get()
            ->map(fn (ProjectDocument $document): array => $this->serializeMasterListDocument($document));
        $fileName = 'lista-mestra-projetos-'.now()->format('Ymd-His').'.xls';

        return response()
            ->view('exports.project-master-list-excel', [
                'tenant' => $tenant,
                'documents' => $documents,
                'generatedAt' => now(),
            ], 200, [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
                'Cache-Control' => 'max-age=0, no-cache, must-revalidate',
            ]);
    }

    public function store(Request $request, Tenant $tenant, AutodeskApsService $aps): RedirectResponse
    {
        abort_unless(ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::UPLOAD), 403);

        $uploadedFile = $request->file('file');

        if ($uploadedFile && ! $uploadedFile->isValid()) {
            throw ValidationException::withMessages([
                'file' => $this->uploadFailureMessage($uploadedFile->getError()),
            ]);
        }

        $data = $request->validate([
            'contract_id' => [
                'required',
                Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'disciplina_id' => [
                'required',
                Rule::exists('disciplinas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'project_phase_id' => [
                'required',
                Rule::exists('project_phases', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'obra_id' => [
                'required',
                Rule::exists('obras', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'document_type' => ['required', Rule::in(array_keys(self::DOCUMENT_TYPES))],
            'document_number' => ['required', 'string', 'regex:/^[0-9]{1,3}$/'],
            'revision' => ['nullable', 'string', 'max:30'],
            'revision_change_summary' => ['nullable', 'string', 'max:5000'],
            'cap_reason' => ['nullable', 'string', 'max:5000'],
            'cap_description' => ['nullable', 'string', 'max:5000'],
            'cap_impacts' => ['nullable', 'array'],
            'cap_impacts.*' => ['string', Rule::in(ProjectCap::impactKeys())],
            'file' => ['required', 'file', 'max:51200'],
        ], [
            'contract_id.required' => 'Selecione o contrato.',
            'obra_id.required' => 'Selecione a obra.',
            'disciplina_id.required' => 'Selecione a disciplina.',
            'project_phase_id.required' => 'Selecione a fase do projeto.',
            'document_number.required' => 'Informe o sequencial do projeto.',
            'document_number.regex' => 'O sequencial do projeto deve conter de 1 a 3 numeros.',
            'file.required' => 'Selecione um arquivo de projeto.',
            'file.uploaded' => 'Falha ao enviar o arquivo. Verifique se ele tem ate 50 MB e se o servidor foi iniciado com limite de upload maior que 50 MB.',
            'file.max' => 'O arquivo deve ter no maximo 50 MB neste MVP.',
        ]);

        $contract = $tenant->contracts()->findOrFail($data['contract_id']);
        $obra = $tenant->obras()->findOrFail($data['obra_id']);
        $disciplina = $tenant->disciplinas()->findOrFail($data['disciplina_id']);
        $phase = ProjectPhase::query()
            ->where('is_active', true)
            ->findOrFail($data['project_phase_id']);

        abort_unless((int) $obra->contract_id === (int) $contract->id, 422);
        abort_unless((int) $disciplina->contract_id === (int) $contract->id, 422);
        abort_unless($this->canAccessContract($request, $tenant, $contract), 403);
        abort_unless(ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::UPLOAD, $contract), 403);

        $documentNumber = $this->normalizeDocumentNumber($data['document_number']);
        $code = $this->buildDocumentCode($contract, $obra, $disciplina, $phase, $data['document_type'], $documentNumber);
        $file = $data['file'];
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $existingDocument = $this->findExistingDocumentForEap($tenant, $contract, $obra, $disciplina, $phase, $data['document_type'], $code, $documentNumber);
        $capImpacts = ProjectCap::normalizeImpacts($data['cap_impacts'] ?? []);

        if ($existingDocument) {
            $capErrors = [];

            if (blank($data['cap_reason'] ?? null)) {
                $capErrors['cap_reason'] = 'Informe o motivo da alteracao desta revisao.';
            }

            if (blank($data['cap_description'] ?? null)) {
                $capErrors['cap_description'] = 'Descreva o que foi alterado nesta revisao.';
            }

            if ($capImpacts === []) {
                $capErrors['cap_impacts'] = 'Selecione ao menos um impacto da alteracao.';
            }

            if ($capErrors !== []) {
                throw ValidationException::withMessages($capErrors);
            }
        } elseif (blank($data['title'] ?? null)) {
            throw ValidationException::withMessages([
                'title' => 'Informe o titulo do projeto.',
            ]);
        }

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => 'Formato de arquivo nao permitido para projetos.',
            ]);
        }

        $createdNewDocument = false;
        $createdRevision = 'R00';
        $isRevision = (bool) $existingDocument;

        $document = DB::transaction(function () use ($tenant, $contract, $obra, $disciplina, $phase, $request, $data, $file, $extension, $code, $documentNumber, $existingDocument, $isRevision, $capImpacts, &$createdNewDocument, &$createdRevision): ProjectDocument {
            $document = $existingDocument;

            if ($document) {
                $createdRevision = $this->nextRevision($document);

                $document->forceFill([
                    'code' => $code,
                    'document_number' => $documentNumber,
                    'project_phase_id' => $phase->id,
                    'status' => 'em_analise',
                    'reviewed_by_id' => null,
                    'approved_by_id' => null,
                    'inactive_by_id' => null,
                    'reviewed_at' => null,
                    'review_notes' => null,
                    'approved_at' => null,
                    'approval_notes' => null,
                    'inactive_at' => null,
                    'inactive_reason' => null,
                ])->save();
            } else {
                $createdNewDocument = true;
                $createdRevision = 'R00';

                $document = $tenant->projectDocuments()->create([
                    'contract_id' => $contract->id,
                    'obra_id' => $obra->id,
                    'disciplina_id' => $disciplina->id,
                    'project_phase_id' => $phase->id,
                    'created_by_id' => $request->user()->id,
                    'title' => $data['title'],
                    'code' => $code,
                    'document_number' => $documentNumber,
                    'document_type' => $data['document_type'],
                    'status' => 'em_analise',
                ]);
            }

            $storedName = $this->storedFileName($code, $createdRevision, $extension);
            $path = $file->storeAs("tenant-{$tenant->id}/projects/contract-{$contract->id}/obra-{$obra->id}", $storedName, 'public');
            $capPayload = [];

            if ($isRevision) {
                $capYear = (int) now()->year;
                $capSequence = ProjectCap::nextSequence($tenant, $capYear);

                $capPayload = [
                    'cap_number' => ProjectCap::number($capSequence, $capYear),
                    'cap_sequence' => $capSequence,
                    'cap_year' => $capYear,
                    'cap_requested_by_id' => $request->user()->id,
                    'cap_requested_at' => now(),
                    'cap_reason' => $data['cap_reason'] ?? null,
                    'cap_description' => $data['cap_description'] ?? null,
                    'cap_impacts' => $capImpacts,
                ];
            }

            $document->versions()->create([
                'tenant_id' => $tenant->id,
                'uploaded_by_id' => $request->user()->id,
                'revision' => $createdRevision,
                'revision_change_summary' => $isRevision ? (($data['revision_change_summary'] ?? null) ?: ($data['cap_description'] ?? null)) : null,
                'status' => 'em_analise',
                'original_name' => $file->getClientOriginalName(),
                'stored_name' => $storedName,
                'file_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'derivative_status' => 'not_submitted',
            ] + $capPayload);

            return $document->load(['tenant', 'contract', 'obra', 'disciplina', 'phase', 'latestVersion']);
        });

        $apsQueued = $this->queueApsProcessing($document->latestVersion, $aps);
        $notifiedCount = $this->notifyDisciplineReviewers($document, $request->user());
        $messagePrefix = $createdNewDocument
            ? "Arquivo de projeto submetido para analise como revisao {$createdRevision}."
            : "Nova revisao {$createdRevision} submetida para analise do EAP {$document->code}.";
        $apsMessage = $apsQueued ? ' Processamento APS iniciado em segundo plano.' : '';

        return back()->with('success', $notifiedCount > 0
            ? "{$messagePrefix}{$apsMessage} {$notifiedCount} responsavel(is) notificado(s) no sistema e por email."
            : "{$messagePrefix}{$apsMessage} Nenhum responsavel cadastrado para esta disciplina.");
    }

    public function destroy(Request $request, Tenant $tenant, ProjectDocument $document): RedirectResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);
        $contract = $document->contract()->firstOrFail();

        abort_unless($this->canAccessContract($request, $tenant, $contract), 403);
        abort_unless(ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::DELETE, $contract), 403);

        $document->delete();

        return back()->with('success', 'Projeto excluido. O arquivo e o registro foram mantidos no historico.');
    }

    public function inactivate(Request $request, Tenant $tenant, ProjectDocument $document): RedirectResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);
        $contract = $document->contract()->firstOrFail();

        abort_unless($this->canAccessContract($request, $tenant, $contract), 403);
        abort_unless(ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::DELETE, $contract), 403);

        if ($document->status !== 'ativo' || $document->inactive_at !== null) {
            return back()->with('error', 'Somente projetos aprovados e ativos na arvore podem ser inativados.');
        }

        $data = $request->validate([
            'inactive_reason' => ['required', 'string', 'max:5000'],
        ], [
            'inactive_reason.required' => 'Informe o motivo da inativacao do projeto.',
        ]);

        $document->forceFill([
            'status' => 'inativo',
            'inactive_at' => now(),
            'inactive_by_id' => $request->user()->id,
            'inactive_reason' => $data['inactive_reason'],
        ])->save();

        return back()->with('success', 'Projeto inativado. Ele foi removido da arvore principal, mas permanece no historico.');
    }

    private function accessibleContracts(Request $request, Tenant $tenant, ?string $permission = null)
    {
        $query = $tenant->contracts();
        $tenantRole = $request->user()->tenantRole($tenant);

        if (! in_array($tenantRole, ['tenant_owner', 'tenant_admin'], true)) {
            $query->whereHas('participants', function (Builder $query) use ($request): void {
                $query->where('user_id', $request->user()->id)->where('status', 'active');
            });
        }

        if ($permission) {
            $contractIds = ProjectPermissions::contractIdsFor($request->user(), $tenant, $permission);

            if ($contractIds !== null) {
                $query->whereIn('id', $contractIds);
            }
        }

        return $query;
    }

    private function masterListContracts(Request $request, Tenant $tenant)
    {
        return $this->accessibleContracts($request, $tenant, ProjectPermissions::VIEW)
            ->with('obra:id,nome')
            ->orderBy('code')
            ->get();
    }

    private function masterListDocumentsQuery(Request $request, Tenant $tenant, $contractIds)
    {
        $query = $tenant->projectDocuments()
            ->whereIn('contract_id', $contractIds)
            ->withCount(['rncs as open_rncs_count' => fn (Builder $query): Builder => $query->where('status', 'aberta')])
            ->with([
                'contract:id,code,name,obra_id',
                'contract.obra:id,nome',
                'obra:id,nome,codigo',
                'disciplina:id,nome,sigla,cor',
                'phase:id,name,code',
                'creator:id,name,email',
                'reviewer:id,name,email',
                'approver:id,name,email',
                'inactiveBy:id,name,email',
                'latestVersion.uploader:id,name,email',
                'latestVersion.reviewer:id,name,email',
                'latestVersion.approver:id,name,email',
            ]);

        if ($contractId = $this->masterListFilterValue($request, 'contract_id')) {
            $query->where('contract_id', $contractId);
        }

        if ($obraId = $this->masterListFilterValue($request, 'obra_id')) {
            $query->where('obra_id', $obraId);
        }

        if ($disciplinaId = $this->masterListFilterValue($request, 'disciplina_id')) {
            $query->where('disciplina_id', $disciplinaId);
        }

        if ($phaseId = $this->masterListFilterValue($request, 'project_phase_id')) {
            $query->where('project_phase_id', $phaseId);
        }

        if ($documentType = $this->masterListFilterValue($request, 'document_type')) {
            if (array_key_exists($documentType, self::DOCUMENT_TYPES)) {
                $query->where('document_type', $documentType);
            }
        }

        if ($status = $this->masterListFilterValue($request, 'status')) {
            if (array_key_exists($status, self::STATUS_LABELS)) {
                $query->where('status', $status);
            }
        }

        if ($search = trim((string) $request->query('q', ''))) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($term): void {
                $query
                    ->whereRaw('lower(title) like ?', [$term])
                    ->orWhereRaw('lower(code) like ?', [$term])
                    ->orWhereRaw('lower(document_number) like ?', [$term])
                    ->orWhereHas('contract', function (Builder $query) use ($term): void {
                        $query
                            ->whereRaw('lower(code) like ?', [$term])
                            ->orWhereRaw('lower(name) like ?', [$term]);
                    })
                    ->orWhereHas('obra', function (Builder $query) use ($term): void {
                        $query
                            ->whereRaw('lower(codigo) like ?', [$term])
                            ->orWhereRaw('lower(nome) like ?', [$term]);
                    })
                    ->orWhereHas('disciplina', function (Builder $query) use ($term): void {
                        $query
                            ->whereRaw('lower(sigla) like ?', [$term])
                            ->orWhereRaw('lower(nome) like ?', [$term]);
                    })
                    ->orWhereHas('phase', function (Builder $query) use ($term): void {
                        $query
                            ->whereRaw('lower(code) like ?', [$term])
                            ->orWhereRaw('lower(name) like ?', [$term]);
                    })
                    ->orWhereHas('latestVersion', function (Builder $query) use ($term): void {
                        $query
                            ->whereRaw('lower(revision) like ?', [$term])
                            ->orWhereRaw('lower(original_name) like ?', [$term])
                            ->orWhereRaw('lower(stored_name) like ?', [$term]);
                    });
            });
        }

        return $query;
    }

    private function masterListFilterValue(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (blank($value) || $value === 'todos') {
            return null;
        }

        return (string) $value;
    }

    private function masterListFilters(Request $request): array
    {
        return [
            'contract_id' => $request->query('contract_id', 'todos'),
            'obra_id' => $request->query('obra_id', 'todos'),
            'disciplina_id' => $request->query('disciplina_id', 'todos'),
            'project_phase_id' => $request->query('project_phase_id', 'todos'),
            'document_type' => $request->query('document_type', 'todos'),
            'status' => $request->query('status', 'todos'),
            'q' => $request->query('q', ''),
        ];
    }

    private function serializeMasterListDocument(ProjectDocument $document): array
    {
        $version = $document->latestVersion;

        return [
            'id' => $document->id,
            'title' => $document->title,
            'code' => $document->code,
            'document_number' => $document->document_number,
            'document_type' => $document->document_type,
            'document_type_label' => self::DOCUMENT_TYPES[$document->document_type] ?? $document->document_type,
            'status' => $document->status,
            'status_label' => self::STATUS_LABELS[$document->status] ?? $document->status,
            'contract' => [
                'id' => $document->contract?->id,
                'code' => $document->contract?->code,
                'name' => $document->contract?->obra?->nome ?? $document->contract?->name,
            ],
            'obra' => [
                'id' => $document->obra?->id,
                'codigo' => $document->obra?->codigo,
                'nome' => $document->obra?->nome,
            ],
            'disciplina' => [
                'id' => $document->disciplina?->id,
                'sigla' => $document->disciplina?->sigla,
                'nome' => $document->disciplina?->nome,
                'cor' => $document->disciplina?->cor,
            ],
            'phase' => [
                'id' => $document->phase?->id,
                'code' => $document->phase?->code,
                'name' => $document->phase?->name,
            ],
            'revision' => $version?->revision,
            'file_name' => $version?->stored_name ?: $version?->original_name,
            'original_name' => $version?->original_name,
            'file_size' => $version?->size_label,
            'uploaded_by' => $version?->uploader?->name,
            'created_by' => $document->creator?->name,
            'reviewed_by' => $document->reviewer?->name,
            'approved_by' => $document->approver?->name,
            'inactive_by' => $document->inactiveBy?->name,
            'created_at' => $this->formatMasterListDate($document->created_at),
            'reviewed_at' => $this->formatMasterListDate($document->reviewed_at),
            'approved_at' => $this->formatMasterListDate($document->approved_at),
            'inactive_at' => $this->formatMasterListDate($document->inactive_at),
            'open_rncs_count' => (int) ($document->open_rncs_count ?? 0),
        ];
    }

    private function formatMasterListDate($value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)
                ->timezone(config('app.timezone', 'America/Sao_Paulo'))
                ->format('d/m/Y H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function queueApsProcessing(?ProjectDocumentVersion $version, AutodeskApsService $aps): bool
    {
        if (! $version || ! $aps->isConfigured() || ! config('services.autodesk_aps.auto_process', true)) {
            return false;
        }

        if (in_array($version->derivative_status, ['queued', 'processing', 'ready'], true)) {
            return false;
        }

        $version->forceFill([
            'derivative_status' => 'queued',
            'processed_at' => null,
        ])->save();

        ProcessProjectVersionApsJob::dispatch($version->id)->afterResponse();

        return true;
    }

    private function canAccessContract(Request $request, Tenant $tenant, Contract $contract): bool
    {
        if (in_array($request->user()->tenantRole($tenant), ['tenant_owner', 'tenant_admin'], true)) {
            return true;
        }

        return $contract->participants()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->exists();
    }

    private function findExistingDocumentForEap(Tenant $tenant, Contract $contract, Obra $obra, Disciplina $disciplina, ProjectPhase $phase, string $documentType, string $code, string $documentNumber): ?ProjectDocument
    {
        $document = $tenant->projectDocuments()
            ->where('contract_id', $contract->id)
            ->where('obra_id', $obra->id)
            ->where('disciplina_id', $disciplina->id)
            ->where('project_phase_id', $phase->id)
            ->where('document_type', $documentType)
            ->where('code', $code)
            ->first();

        if ($document) {
            return $document;
        }

        $legacyCodes = collect([
            $this->buildDocumentCode($contract, $obra, $disciplina, null, $documentType, $documentNumber),
        ]);

        if ($documentNumber === '001') {
            $legacyCodes->push($this->buildDocumentCode($contract, $obra, $disciplina, null, $documentType));
        }

        $legacyCodes = $legacyCodes->unique()->values();

        return $tenant->projectDocuments()
            ->where('contract_id', $contract->id)
            ->where('obra_id', $obra->id)
            ->where('disciplina_id', $disciplina->id)
            ->whereNull('project_phase_id')
            ->where('document_type', $documentType)
            ->whereIn('code', $legacyCodes)
            ->latest('id')
            ->first();
    }

    private function buildDocumentCode(Contract $contract, Obra $obra, Disciplina $disciplina, ?ProjectPhase $phase, string $documentType, ?string $documentNumber = null): string
    {
        $documentTypeCode = self::DOCUMENT_TYPE_CODES[$documentType] ?? $documentType;

        return collect([$contract->code, $obra->codigo, $disciplina->sigla, $phase?->code, $documentTypeCode, $documentNumber])
            ->map(fn (?string $part): string => mb_strtoupper((string) $part))
            ->map(fn (string $part): string => preg_replace('/\s+/', '', trim($part)) ?? '')
            ->map(fn (string $part): string => preg_replace('/[^A-Z0-9]/', '', $part) ?? '')
            ->filter()
            ->implode('-');
    }

    private function normalizeDocumentNumber(string $documentNumber): string
    {
        $normalized = mb_substr(preg_replace('/\D+/', '', $documentNumber) ?: '', 0, 3);

        return str_pad($normalized, 3, '0', STR_PAD_LEFT);
    }

    private function storedFileName(string $code, string $revision, string $extension): string
    {
        $baseName = collect([$code, $revision])
            ->map(fn (string $part): string => mb_strtoupper($part))
            ->map(fn (string $part): string => preg_replace('/[^A-Z0-9-]/', '', $part) ?? '')
            ->filter()
            ->implode('-');

        return $baseName.'.'.mb_strtolower($extension);
    }

    private function nextRevision(ProjectDocument $document): string
    {
        $highest = $document->versions()
            ->withTrashed()
            ->pluck('revision')
            ->map(function (?string $revision): int {
                if (preg_match('/^R?(\d+)$/i', (string) $revision, $matches)) {
                    return (int) $matches[1];
                }

                return -1;
            })
            ->max();

        return 'R'.str_pad((string) (((int) $highest) + 1), 2, '0', STR_PAD_LEFT);
    }

    private function notifyDisciplineReviewers(ProjectDocument $document, User $actor): int
    {
        if (! $document->disciplina_id) {
            return 0;
        }

        $reviewers = ProjectDisciplineResponsavel::query()
            ->where('tenant_id', $document->tenant_id)
            ->where('contract_id', $document->contract_id)
            ->where('disciplina_id', $document->disciplina_id)
            ->where('tipo', 'analise')
            ->where('status', 'active')
            ->with('user:id,name,email')
            ->get()
            ->pluck('user')
            ->filter()
            ->unique('id')
            ->values();

        $notification = new ProjectSubmittedForReviewNotification($document, $actor);

        $notifiedCount = 0;

        foreach ($reviewers as $user) {
            try {
                $user->notify($notification);
                $notifiedCount++;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $notifiedCount;
    }

    private function uploadFailureMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Falha ao enviar: o PHP ainda esta limitando o upload abaixo do tamanho do arquivo. Reinicie o servidor local usando npm run serve:php ou composer run dev para carregar upload_max_filesize=100M e post_max_size=128M.',
            UPLOAD_ERR_PARTIAL => 'Falha ao enviar: o upload foi interrompido antes de terminar. Tente novamente.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falha ao enviar: o servidor nao encontrou a pasta temporaria de upload.',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao enviar: o servidor nao conseguiu gravar o arquivo temporario.',
            UPLOAD_ERR_EXTENSION => 'Falha ao enviar: uma extensao do PHP bloqueou o upload.',
            default => 'Falha ao enviar o arquivo. Codigo interno do PHP: '.$error.'.',
        };
    }
}

<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessProjectVersionApsJob;
use App\Models\Contract;
use App\Models\ProjectDocumentVersion;
use App\Models\ProjectReviewChecklist;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AutodeskApsService;
use App\Support\ProjectPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProjectViewerController extends Controller
{
    private const DEFAULT_REVIEW_CHECKLIST = [
        'Verificar se a EAP está correta (contrato-obra-disciplina-fase-tipo-sequencial-revisão)',
        'Verificar se o arquivo abre e carrega corretamente no APS',
        'Verificar se há marcações e pendências técnicas.',
    ];

    private const OBSOLETE_REVIEW_CHECKLIST = [
        'Conferir EAP, revisao e sequencial do arquivo',
        'Confirmar contrato, obra, disciplina, fase e tipo do projeto',
        'Conferir compatibilidade com o escopo do contrato',
        'Registrar conclusao da analise/aprovacao',
    ];

    private const RENAMED_REVIEW_CHECKLIST = [
        'Registrar marcacoes tecnicas nas pendencias encontradas' => 'Verificar se há marcações e pendências técnicas.',
    ];

    public function show(Request $request, Tenant $tenant, ProjectDocumentVersion $version, AutodeskApsService $aps): Response
    {
        $version = $this->authorizedVersion($request, $tenant, $version);
        $contract = $version->document->contract;
        $canReviewProjects = ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::REVIEW, $contract);
        $workspaceMode = $this->workspaceMode($request, $version, $canReviewProjects);
        $showChecklistPanel = $workspaceMode === 'review' && $canReviewProjects;
        $showCommentsPanel = in_array($workspaceMode, ['comments', 'review'], true);

        if ($version->aps_urn && in_array($version->derivative_status, ['queued', 'processing'], true)) {
            $version = $aps->syncManifestStatus($version);
            $version->load([
                'document.contract:id,tenant_id,code,name,obra_id',
                'document.obra:id,nome,codigo',
                'document.disciplina:id,nome,sigla,cor',
                'document.phase:id,name,code',
            ]);
        }

        $checklist = $showChecklistPanel
            ? $this->ensureReviewChecklist($version, $request->user()->id)
            : $version->reviewChecklist()->with('items.checkedBy:id,name,email')->first();

        return Inertia::render('Tenant/Projects/Viewer', [
            'tenant' => $tenant,
            'version' => $this->versionPayload($version),
            'apsConfigured' => $aps->isConfigured(),
            'canReviewProjects' => $canReviewProjects,
            'workspaceMode' => $workspaceMode,
            'projectListContext' => $request->query('origin') === 'visualizar' ? 'visualizar' : 'review',
            'showCommentsPanel' => $showCommentsPanel,
            'showChecklistPanel' => $showChecklistPanel,
            'contractUsers' => $this->contractUsers($tenant, $contract),
            'reviewMarkups' => $version->reviewMarkups()
                ->with([
                    'creator:id,name,email',
                    'assignee:id,name,email,avatar_url',
                    'closer:id,name,email',
                ])
                ->latest()
                ->get(),
            'reviewChecklist' => $checklist?->load('items.checkedBy:id,name,email'),
        ]);
    }

    public function process(Request $request, Tenant $tenant, ProjectDocumentVersion $version, AutodeskApsService $aps): RedirectResponse
    {
        $version = $this->authorizedVersion($request, $tenant, $version);

        if (! $aps->isConfigured()) {
            return back()->with('error', 'Configure AUTODESK_APS_CLIENT_ID, AUTODESK_APS_CLIENT_SECRET e AUTODESK_APS_BUCKET_KEY no .env para processar APS.');
        }

        if (in_array($version->derivative_status, ['queued', 'processing'], true)) {
            return back()->with('success', 'Este arquivo ja esta na fila/processamento APS.');
        }

        if ($version->derivative_status === 'ready') {
            return back()->with('success', 'Este arquivo ja esta pronto para visualizacao APS.');
        }

        $version->forceFill([
            'derivative_status' => 'queued',
            'processed_at' => null,
        ])->save();

        ProcessProjectVersionApsJob::dispatch($version->id)->afterResponse();

        return back()->with('success', 'Processamento APS iniciado em segundo plano.');
    }

    public function status(Request $request, Tenant $tenant, ProjectDocumentVersion $version, AutodeskApsService $aps): JsonResponse
    {
        $version = $this->authorizedVersion($request, $tenant, $version);

        try {
            $manifest = $version->aps_urn ? $aps->manifest($version) : null;

            if ($manifest) {
                $version = $aps->syncManifestStatus($version);
            }
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'failed',
                'progress' => null,
                'message' => $exception->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => $version->derivative_status,
            'progress' => $manifest['progress'] ?? null,
            'manifest_status' => $manifest['status'] ?? null,
            'processed_at' => $version->processed_at?->toIso8601String(),
        ]);
    }

    public function token(Request $request, Tenant $tenant, AutodeskApsService $aps): JsonResponse
    {
        abort_unless(
            ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::VIEW)
            || ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::REVIEW),
            403
        );

        return response()->json($aps->viewerToken());
    }

    private function authorizedVersion(Request $request, Tenant $tenant, ProjectDocumentVersion $version): ProjectDocumentVersion
    {
        abort_unless((int) $version->tenant_id === (int) $tenant->id, 404);

        $version->load([
            'document.contract:id,tenant_id,code,name,obra_id',
            'document.latestVersion',
            'document.latestApprovedVersion',
            'document.obra:id,nome,codigo',
            'document.disciplina:id,nome,sigla,cor',
            'document.phase:id,name,code',
        ]);

        abort_unless($version->document, 404);

        if ($request->query('origin') === 'visualizar') {
            abort_if($this->hasPendingRevision($version), 403);
        }

        $canView = ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::VIEW, $version->document->contract);
        $canReview = ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::REVIEW, $version->document->contract);

        abort_unless($canView || $canReview, 403);
        abort_if($this->hasPendingRevision($version) && ! $canReview, 403);

        if ($version->status !== 'ativo') {
            abort_unless($canReview, 403);
        }

        return $version;
    }

    private function hasPendingRevision(ProjectDocumentVersion $version): bool
    {
        $latestVersion = $version->document->latestVersion;
        $latestApprovedVersion = $version->document->latestApprovedVersion;

        return $latestVersion
            && $latestApprovedVersion
            && (int) $latestVersion->id !== (int) $latestApprovedVersion->id
            && in_array($latestVersion->status, ['em_analise', 'em_aprovacao'], true);
    }

    private function workspaceMode(Request $request, ProjectDocumentVersion $version, bool $canReviewProjects): string
    {
        $requestedMode = (string) $request->query('workspace', '');

        if (in_array($requestedMode, ['view', 'comments'], true)) {
            return $requestedMode;
        }

        if ($requestedMode === 'review' && $canReviewProjects) {
            return 'review';
        }

        if ($version->status !== 'ativo' && $canReviewProjects) {
            return 'review';
        }

        return 'view';
    }

    /**
     * @return array<string, mixed>
     */
    private function versionPayload(ProjectDocumentVersion $version): array
    {
        return [
            'id' => $version->id,
            'revision' => $version->revision,
            'status' => $version->status,
            'review_notes' => $version->review_notes,
            'approval_notes' => $version->approval_notes,
            'original_name' => $version->original_name,
            'stored_name' => $version->stored_name,
            'url' => $version->url,
            'size_label' => $version->size_label,
            'aps_urn' => $version->aps_urn,
            'derivative_status' => $version->derivative_status,
            'submitted_to_aps_at' => $version->submitted_to_aps_at?->toIso8601String(),
            'processed_at' => $version->processed_at?->toIso8601String(),
            'document' => [
                'id' => $version->document->id,
                'title' => $version->document->title,
                'code' => $version->document->code,
                'document_type' => $version->document->document_type,
                'status' => $version->document->status,
                'contract' => $version->document->contract,
                'obra' => $version->document->obra,
                'disciplina' => $version->document->disciplina,
                'phase' => $version->document->phase,
            ],
        ];
    }

    private function ensureReviewChecklist(ProjectDocumentVersion $version, int $userId): ProjectReviewChecklist
    {
        $checklist = ProjectReviewChecklist::firstOrCreate(
            [
                'tenant_id' => $version->tenant_id,
                'project_document_version_id' => $version->id,
            ],
            [
                'contract_id' => $version->document->contract_id,
                'project_document_id' => $version->document->id,
                'created_by_id' => $userId,
                'status' => 'open',
            ]
        );

        $checklist->items()
            ->whereIn('label', self::OBSOLETE_REVIEW_CHECKLIST)
            ->delete();

        foreach (self::RENAMED_REVIEW_CHECKLIST as $oldLabel => $newLabel) {
            $checklist->items()
                ->where('label', $oldLabel)
                ->update(['label' => $newLabel]);
        }

        foreach (self::DEFAULT_REVIEW_CHECKLIST as $position => $label) {
            $item = $checklist->items()->where('label', $label)->first();

            if ($item) {
                $item->forceFill(['position' => $position + 1])->save();
            } else {
                $checklist->items()->create([
                    'tenant_id' => $version->tenant_id,
                    'label' => $label,
                    'required' => true,
                    'checked' => false,
                    'position' => $position + 1,
                ]);
            }
        }

        return $checklist->load('items.checkedBy:id,name,email');
    }

    private function contractUsers(Tenant $tenant, Contract $contract)
    {
        return User::query()
            ->where('is_platform_admin', false)
            ->where(function (Builder $query) use ($tenant, $contract): void {
                $query
                    ->whereHas('tenantMemberships', function (Builder $query) use ($tenant): void {
                        $query->where('tenant_id', $tenant->id)->where('status', 'active');
                    })
                    ->orWhereHas('contractParticipations', function (Builder $query) use ($tenant, $contract): void {
                        $query->where('tenant_id', $tenant->id)
                            ->where('contract_id', $contract->id)
                            ->where('status', 'active');
                    });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'avatar_url'])
            ->unique('id')
            ->values();
    }
}

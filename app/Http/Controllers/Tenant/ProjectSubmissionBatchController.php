<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessProjectVersionApsJob;
use App\Jobs\RemoveRejectedProjectVersionFromApsJob;
use App\Models\Contract;
use App\Models\Disciplina;
use App\Models\Obra;
use App\Models\ProjectDisciplineResponsavel;
use App\Models\ProjectDocument;
use App\Models\ProjectDocumentVersion;
use App\Models\ProjectPhase;
use App\Models\ProjectSubmissionBatch;
use App\Models\Tenant;
use App\Models\Trecho;
use App\Models\User;
use App\Notifications\ProjectBatchWorkflowNotification;
use App\Services\AutodeskApsService;
use App\Services\ProjectMasterListExportService;
use App\Support\ProjectCap;
use App\Support\ProjectPermissions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectSubmissionBatchController extends Controller
{
    private const DOCUMENT_TYPES = ['projeto', 'prancha', 'modelo_bim', 'memorial', 'especificacao', 'outro'];

    private const DOCUMENT_TYPE_CODES = [
        'projeto' => 'PRJ',
        'prancha' => 'PRA',
        'modelo_bim' => 'BIM',
        'memorial' => 'MEM',
        'especificacao' => 'ESP',
        'outro' => 'OUT',
    ];

    private const ALLOWED_EXTENSIONS = ['dwg', 'ifc', 'rvt', 'pdf', 'dwfx', 'dwf'];

    public function store(Request $request, Tenant $tenant, AutodeskApsService $aps): RedirectResponse
    {
        abort_unless(ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::UPLOAD_BATCH), 403);

        $data = $request->validate([
            'contract_id' => ['required', Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
            'obra_id' => ['required', Rule::exists('obras', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
            'trecho_id' => ['nullable', Rule::exists('trechos', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
            'project_phase_id' => ['required', Rule::exists('project_phases', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'document_type' => ['required', Rule::in(self::DOCUMENT_TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'cap_reason' => ['nullable', 'string', 'max:5000'],
            'cap_description' => ['nullable', 'string', 'max:5000'],
            'cap_impacts' => ['nullable', 'array'],
            'cap_impacts.*' => ['string', Rule::in(ProjectCap::impactKeys())],
            'items' => ['required', 'array', 'min:2', 'max:20'],
            'items.*.disciplina_id' => ['required', Rule::exists('disciplinas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
            'items.*.document_number' => ['required', 'string', 'regex:/^[0-9]{3}$/'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.revision_change_summary' => ['nullable', 'string', 'max:5000'],
            'items.*.file' => ['required', 'file', 'max:51200'],
        ], [
            'items.min' => 'Adicione ao menos dois projetos ao pacote.',
            'items.max' => 'Cada pacote pode conter no máximo 20 projetos.',
            'items.*.file.required' => 'Selecione o arquivo de cada projeto.',
            'items.*.file.max' => 'Cada arquivo deve ter no máximo 50 MB.',
            'items.*.document_number.regex' => 'O sequencial deve conter exatamente 3 números.',
        ]);

        $contract = $tenant->contracts()->findOrFail($data['contract_id']);
        $obra = $tenant->obras()->findOrFail($data['obra_id']);
        $trecho = $this->submissionTrecho($tenant, $obra, $data['trecho_id'] ?? null);
        $phase = ProjectPhase::query()->where('is_active', true)->findOrFail($data['project_phase_id']);

        abort_unless((int) $obra->contract_id === (int) $contract->id, 422);
        abort_unless($this->canAccessContract($request, $tenant, $contract), 403);
        abort_unless(ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::UPLOAD_BATCH, $contract), 403);

        $preparedItems = collect();
        $seenCodes = [];
        $errors = [];

        foreach ($data['items'] as $index => $item) {
            $disciplina = $tenant->disciplinas()->findOrFail($item['disciplina_id']);
            $documentNumber = $this->normalizeDocumentNumber($item['document_number']);
            $code = $this->buildDocumentCode($contract, $obra, $trecho, $disciplina, $phase, $data['document_type'], $documentNumber);
            $file = $item['file'];
            $extension = mb_strtolower($file->getClientOriginalExtension());

            if ((int) $disciplina->contract_id !== (int) $contract->id) {
                $errors["items.{$index}.disciplina_id"] = 'A disciplina não pertence ao contrato selecionado.';
            }

            if (isset($seenCodes[$code])) {
                $errors["items.{$index}.document_number"] = 'Esta combinação de disciplina e sequencial já foi adicionada ao pacote.';
            }
            $seenCodes[$code] = true;

            if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                $errors["items.{$index}.file"] = 'Formato de arquivo não permitido para projetos.';
            }

            $existing = $this->findExistingDocumentForEap($tenant, $contract, $obra, $trecho, $disciplina, $phase, $data['document_type'], $code, $documentNumber);
            $latestVersion = $existing?->latestVersion()->first();

            if ($latestVersion && in_array($latestVersion->status, ['em_analise', 'em_aprovacao'], true)) {
                $errors["items.{$index}.document_number"] = 'Esta EAP já possui uma versão em análise ou aprovação.';
            }

            $requiresCap = $existing?->versions()->where('status', 'ativo')->exists() ?? false;

            if (! $existing && blank($item['title'] ?? null)) {
                $errors["items.{$index}.title"] = 'Informe o título do novo projeto.';
            }

            if ($existing && ! $requiresCap && blank($item['revision_change_summary'] ?? null)) {
                $errors["items.{$index}.revision_change_summary"] = 'Descreva as correções realizadas nesta nova revisão.';
            }

            $preparedItems->push([
                ...$item,
                'disciplina' => $disciplina,
                'document_number' => $documentNumber,
                'code' => $code,
                'file' => $file,
                'extension' => $extension,
                'existing' => $existing,
                'requires_cap' => $requiresCap,
                'revision' => $existing ? $this->nextRevision($existing) : 'R00',
            ]);
        }

        $disciplineCounts = $preparedItems->countBy(fn (array $item): string => (string) $item['disciplina']->id);
        $sharedSequence = $preparedItems->first()['document_number'] ?? null;

        foreach ($preparedItems as $index => $item) {
            if ($disciplineCounts->get((string) $item['disciplina']->id, 0) === 1 && $item['document_number'] !== $sharedSequence) {
                $errors["items.{$index}.document_number"] = 'Disciplinas diferentes devem usar o mesmo sequencial. Ele só pode variar quando houver mais de um projeto na mesma disciplina.';
            }
        }

        $requiresCapDetails = $preparedItems->contains('requires_cap', true);
        $capImpacts = ProjectCap::normalizeImpacts($data['cap_impacts'] ?? []);

        if ($requiresCapDetails && blank($data['cap_reason'] ?? null)) {
            $errors['cap_reason'] = 'Informe o motivo comum das alterações deste pacote.';
        }
        if ($requiresCapDetails && blank($data['cap_description'] ?? null)) {
            $errors['cap_description'] = 'Descreva as alterações reunidas nesta CAP.';
        }
        if ($requiresCapDetails && $capImpacts === []) {
            $errors['cap_impacts'] = 'Selecione ao menos um impacto das alterações.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $batch = DB::transaction(function () use ($tenant, $contract, $obra, $trecho, $phase, $request, $data, $preparedItems, $capImpacts, $requiresCapDetails): ProjectSubmissionBatch {
            $year = (int) now()->year;
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $packageSequence = ((int) ProjectSubmissionBatch::query()
                ->where('tenant_id', $tenant->id)
                ->where('sequence_year', $year)
                ->max('package_sequence')) + 1;
            $capSequence = $requiresCapDetails ? ProjectCap::nextSequence($tenant, $year) : null;
            $capNumber = null;

            if ($requiresCapDetails) {
                $highestRevision = $preparedItems->pluck('revision')
                    ->map(fn (string $revision): int => (int) preg_replace('/\D+/', '', $revision))
                    ->max() ?? 0;
                $capRevision = 'R'.str_pad((string) $highestRevision, 2, '0', STR_PAD_LEFT);
                $capNumber = ProjectCap::fromBatch(
                    $contract->code,
                    $obra->codigo,
                    $trecho->codigo,
                    $preparedItems->pluck('disciplina.sigla')->all(),
                    $phase->code,
                    $capSequence,
                    $capRevision,
                );
            }

            $batch = ProjectSubmissionBatch::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'trecho_id' => $trecho->id,
                'project_phase_id' => $phase->id,
                'submitted_by_id' => $request->user()->id,
                'package_number' => 'LOT-'.str_pad((string) $packageSequence, 3, '0', STR_PAD_LEFT).'-'.$year,
                'package_sequence' => $packageSequence,
                'sequence_year' => $year,
                'title' => $data['title'],
                'document_type' => $data['document_type'],
                'status' => 'em_analise',
                'has_revisions' => $requiresCapDetails,
                'cap_number' => $capNumber,
                'cap_sequence' => $capSequence,
                'cap_year' => $requiresCapDetails ? $year : null,
                'cap_requested_at' => $requiresCapDetails ? now() : null,
                'cap_reason' => $requiresCapDetails ? ($data['cap_reason'] ?? null) : null,
                'cap_description' => $requiresCapDetails ? ($data['cap_description'] ?? null) : null,
                'cap_impacts' => $requiresCapDetails ? $capImpacts : null,
            ]);

            foreach ($preparedItems as $item) {
                $document = $item['existing'];

                if ($document) {
                    $document->forceFill([
                        'code' => $item['code'],
                        'trecho_id' => $trecho->id,
                        'document_number' => $item['document_number'],
                        'project_phase_id' => $phase->id,
                        'status' => 'em_analise',
                        'reviewed_by_id' => null,
                        'approved_by_id' => null,
                        'reviewed_at' => null,
                        'review_notes' => null,
                        'approved_at' => null,
                        'approval_notes' => null,
                    ])->save();
                } else {
                    $document = $tenant->projectDocuments()->create([
                        'contract_id' => $contract->id,
                        'obra_id' => $obra->id,
                        'trecho_id' => $trecho->id,
                        'disciplina_id' => $item['disciplina']->id,
                        'project_phase_id' => $phase->id,
                        'created_by_id' => $request->user()->id,
                        'title' => $item['title'],
                        'code' => $item['code'],
                        'document_number' => $item['document_number'],
                        'document_type' => $data['document_type'],
                        'status' => 'em_analise',
                    ]);
                }

                $storedName = $this->storedFileName($item['code'], $item['revision'], $item['extension']);
                $path = $item['file']->storeAs("tenant-{$tenant->id}/projects/contract-{$contract->id}/obra-{$obra->id}", $storedName, 'local');

                $document->versions()->create([
                    'tenant_id' => $tenant->id,
                    'project_submission_batch_id' => $batch->id,
                    'uploaded_by_id' => $request->user()->id,
                    'revision' => $item['revision'],
                    'revision_change_summary' => $item['existing'] ? (($item['revision_change_summary'] ?? null) ?: ($data['cap_description'] ?? null)) : null,
                    'status' => 'em_analise',
                    'original_name' => $item['file']->getClientOriginalName(),
                    'stored_name' => $storedName,
                    'file_path' => $path,
                    'storage_disk' => 'local',
                    'mime_type' => $item['file']->getClientMimeType(),
                    'file_size' => $item['file']->getSize(),
                    'derivative_status' => 'not_submitted',
                ]);
            }

            return $batch->load(['tenant', 'contract', 'versions.document.disciplina']);
        });

        foreach ($batch->versions as $version) {
            $this->queueApsProcessing($version, $aps);
        }
        $notified = $this->notifyResponsibles($batch, 'analise', 'submitted', $request->user());

        $capMessage = $batch->cap_number ? " e CAP {$batch->cap_number}" : '';

        return back()->with('success', "Pacote {$batch->package_number} submetido com {$batch->versions->count()} projetos{$capMessage}. {$notified} responsável(is) notificado(s).");
    }

    public function update(Request $request, Tenant $tenant, ProjectSubmissionBatch $batch): RedirectResponse
    {
        abort_unless((int) $batch->tenant_id === (int) $tenant->id, 404);
        $batch->load(['contract', 'versions.document.disciplina', 'versions.document.creator', 'versions.uploader']);
        abort_unless($batch->contract && $this->canAccessContract($request, $tenant, $batch->contract), 403);
        abort_unless(ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::REVIEW_BATCH, $batch->contract), 403);
        abort_unless(in_array($batch->status, ['em_analise', 'em_aprovacao'], true), 403);

        $stage = $batch->status === 'em_analise' ? 'analise' : 'aprovacao';
        abort_unless($this->canReviewEveryDiscipline($request, $tenant, $batch, $stage), 403);

        $data = $request->validate([
            'action' => ['required', Rule::in(['aprovar', 'reprovar'])],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $approved = $data['action'] === 'aprovar';
        $notes = trim((string) ($data['review_notes'] ?? ''));

        if (! $approved && $notes === '') {
            throw ValidationException::withMessages(['review_notes' => 'Informe o motivo da devolução do pacote.']);
        }

        DB::transaction(function () use ($batch, $request, $approved, $notes, $stage): void {
            $nextStatus = ! $approved ? 'reprovado' : ($stage === 'analise' ? 'em_aprovacao' : 'ativo');
            $batchUpdates = ['status' => $nextStatus];

            if ($stage === 'analise') {
                $batchUpdates += ['reviewed_by_id' => $request->user()->id, 'reviewed_at' => now(), 'review_notes' => $notes ?: null];
            } else {
                $batchUpdates += ['approved_by_id' => $request->user()->id, 'approved_at' => now(), 'approval_notes' => $notes ?: null];
            }
            $batch->forceFill($batchUpdates)->save();

            foreach ($batch->versions as $version) {
                $updates = ['status' => $nextStatus];
                if ($stage === 'analise') {
                    $updates += ['reviewed_by_id' => $request->user()->id, 'reviewed_at' => now(), 'review_notes' => $notes ?: null];
                } else {
                    $updates += ['approved_by_id' => $request->user()->id, 'approved_at' => now(), 'approval_notes' => $notes ?: null];
                }
                $version->forceFill($updates)->save();
                $version->document?->forceFill($updates)->save();
            }
        });

        if (! $approved) {
            foreach ($batch->versions as $version) {
                $version->forceFill(['derivative_status' => 'removing'])->save();
                RemoveRejectedProjectVersionFromApsJob::dispatch($version->id)->afterResponse();
            }
            $batch->submitter?->notify(new ProjectBatchWorkflowNotification($batch->fresh(['tenant', 'contract', 'versions.document']), $request->user(), 'rejected', $notes));

            return back()->with('success', 'Pacote devolvido integralmente para correção. Os arquivos originais foram preservados.');
        }

        if ($stage === 'analise') {
            $notified = $this->notifyResponsibles($batch->fresh(['tenant', 'contract', 'versions.document.disciplina']), 'aprovacao', 'verified', $request->user());

            return back()->with('success', "Pacote analisado e enviado para aprovação. {$notified} aprovador(es) notificado(s).");
        }

        $notified = $this->notifyContractUsers($batch->fresh(['tenant', 'contract', 'versions.document']), $request->user());

        return back()->with('success', "Pacote aprovado e liberado para a árvore. {$notified} usuário(s) do contrato notificado(s).");
    }

    public function capPdf(Request $request, Tenant $tenant, ProjectSubmissionBatch $batch, ProjectMasterListExportService $exportService)
    {
        abort_unless((int) $batch->tenant_id === (int) $tenant->id, 404);
        abort_if(blank($batch->cap_number), 404);
        $batch->load([
            'contract:id,tenant_id,code,name,obra_id,cliente_empresa_id,construtora_empresa_id,fiscalizadora_empresa_id',
            'obra:id,nome,codigo', 'trecho:id,codigo,nome', 'phase:id,name,code',
            'submitter:id,name,email', 'reviewer:id,name,email', 'approver:id,name,email',
            'versions.uploader:id,name,email', 'versions.document:id,contract_id,obra_id,trecho_id,disciplina_id,project_phase_id,title,code,document_number,document_type',
            'versions.document.disciplina:id,nome,sigla,cor',
        ]);
        abort_unless($batch->contract, 404);
        abort_unless(ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::VIEW, $batch->contract)
            || ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::REVIEW_BATCH, $batch->contract), 403);

        $branding = $exportService->branding($tenant, collect([$batch->contract_id]));
        $response = Pdf::loadView('pdf.project-batch-cap', [
            'tenant' => $tenant,
            'batch' => $batch,
            'branding' => $branding,
            'impactLabels' => ProjectCap::IMPACT_LABELS,
        ])->setPaper('a4')->stream(Str::slug($batch->cap_number).'.pdf');
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');

        return $response;
    }

    private function notifyResponsibles(ProjectSubmissionBatch $batch, string $type, string $event, User $actor): int
    {
        $disciplineIds = $batch->versions->pluck('document.disciplina_id')->filter()->unique();
        $users = ProjectDisciplineResponsavel::query()
            ->where('tenant_id', $batch->tenant_id)->where('contract_id', $batch->contract_id)
            ->whereIn('disciplina_id', $disciplineIds)->where('tipo', $type)->where('status', 'active')
            ->with('user:id,name,email')->get()->pluck('user')->filter()->unique('id')->values();
        $notification = new ProjectBatchWorkflowNotification($batch, $actor, $event);
        $users->each(fn (User $user) => $user->notify($notification));

        return $users->count();
    }

    private function notifyContractUsers(ProjectSubmissionBatch $batch, User $actor): int
    {
        $users = User::query()->where('is_platform_admin', false)
            ->whereHas('contractParticipations', fn (Builder $query) => $query->where('tenant_id', $batch->tenant_id)->where('contract_id', $batch->contract_id)->where('status', 'active'))
            ->get(['id', 'name', 'email'])->unique(fn (User $user): string => mb_strtolower($user->email))->values();
        $notification = new ProjectBatchWorkflowNotification($batch, $actor, 'approved');
        $users->each(fn (User $user) => $user->notify($notification));

        return $users->count();
    }

    private function canReviewEveryDiscipline(Request $request, Tenant $tenant, ProjectSubmissionBatch $batch, string $type): bool
    {
        if (in_array($request->user()->tenantRole($tenant), ['tenant_owner', 'tenant_admin'], true)) {
            return true;
        }
        $disciplineIds = $batch->versions->pluck('document.disciplina_id')->filter()->unique();
        $assignedIds = ProjectDisciplineResponsavel::query()
            ->where('tenant_id', $tenant->id)->where('contract_id', $batch->contract_id)
            ->where('user_id', $request->user()->id)->where('tipo', $type)->where('status', 'active')
            ->whereIn('disciplina_id', $disciplineIds)->pluck('disciplina_id')->unique();

        return $disciplineIds->diff($assignedIds)->isEmpty();
    }

    private function canAccessContract(Request $request, Tenant $tenant, Contract $contract): bool
    {
        return in_array($request->user()->tenantRole($tenant), ['tenant_owner', 'tenant_admin'], true)
            || $contract->participants()->where('user_id', $request->user()->id)->where('status', 'active')->exists();
    }

    private function submissionTrecho(Tenant $tenant, Obra $obra, mixed $trechoId): Trecho
    {
        if ($trechoId) {
            $trecho = $tenant->trechos()->findOrFail($trechoId);
            abort_unless((int) $trecho->obra_id === (int) $obra->id, 422);

            return $trecho;
        }

        return Trecho::defaultForObra($obra);
    }

    private function findExistingDocumentForEap(Tenant $tenant, Contract $contract, Obra $obra, Trecho $trecho, Disciplina $disciplina, ProjectPhase $phase, string $documentType, string $code, string $documentNumber): ?ProjectDocument
    {
        return $tenant->projectDocuments()->where('contract_id', $contract->id)->where('obra_id', $obra->id)
            ->where('trecho_id', $trecho->id)->where('disciplina_id', $disciplina->id)
            ->where('project_phase_id', $phase->id)->where('document_type', $documentType)->where('code', $code)->first();
    }

    private function buildDocumentCode(Contract $contract, Obra $obra, Trecho $trecho, Disciplina $disciplina, ProjectPhase $phase, string $documentType, string $documentNumber): string
    {
        return collect([$contract->code, $obra->codigo, $trecho->codigo, $disciplina->sigla, $phase->code, self::DOCUMENT_TYPE_CODES[$documentType] ?? $documentType, $documentNumber])
            ->map(fn ($part): string => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $part)) ?? '')->filter()->implode('-');
    }

    private function normalizeDocumentNumber(string $number): string
    {
        return str_pad(mb_substr(preg_replace('/\D+/', '', $number) ?: '', 0, 3), 3, '0', STR_PAD_LEFT);
    }

    private function storedFileName(string $code, string $revision, string $extension): string
    {
        return $code.'-'.$revision.'.'.$extension;
    }

    private function nextRevision(ProjectDocument $document): string
    {
        $highest = $document->versions()->withTrashed()->pluck('revision')->map(fn ($revision): int => preg_match('/^R?(\d+)$/i', (string) $revision, $matches) ? (int) $matches[1] : -1)->max();

        return 'R'.str_pad((string) (((int) $highest) + 1), 2, '0', STR_PAD_LEFT);
    }

    private function queueApsProcessing(ProjectDocumentVersion $version, AutodeskApsService $aps): bool
    {
        if (! $aps->isConfigured() || ! config('services.autodesk_aps.auto_process', true)) {
            return false;
        }
        $version->forceFill(['derivative_status' => 'queued', 'processed_at' => null])->save();
        ProcessProjectVersionApsJob::dispatch($version->id)->afterResponse();

        return true;
    }
}

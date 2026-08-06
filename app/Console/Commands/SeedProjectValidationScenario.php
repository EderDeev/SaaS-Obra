<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Disciplina;
use App\Models\Obra;
use App\Models\ProjectDisciplineResponsavel;
use App\Models\ProjectDocument;
use App\Models\ProjectDocumentStatusChange;
use App\Models\ProjectDocumentVersion;
use App\Models\ProjectPhase;
use App\Models\ProjectReviewChecklist;
use App\Models\ProjectReviewMarkup;
use App\Models\ProjectReviewMarkupReply;
use App\Models\ProjectSubmissionBatch;
use App\Models\Tenant;
use App\Models\Trecho;
use App\Models\User;
use App\Support\ProjectCap;
use App\Support\ProjectPermissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeedProjectValidationScenario extends Command
{
    protected $signature = 'projects:seed-validation {tenant : Slug do tenant local}';

    protected $description = 'Cria uma massa persistente e idempotente para validar o modulo de Projetos.';

    private Tenant $tenant;

    private User $admin;

    private Contract $contract;

    private Obra $obra;

    private Trecho $trecho;

    private ProjectPhase $phase;

    /** @var array<string, Disciplina> */
    private array $disciplines = [];

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, ProjectDocumentVersion> */
    private array $sources = [];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando e bloqueado em producao.');

            return self::FAILURE;
        }

        $this->tenant = Tenant::query()->where('slug', $this->argument('tenant'))->firstOrFail();
        $this->admin = $this->tenant->memberships()
            ->with('user')
            ->where('status', 'active')
            ->whereIn('role', ['tenant_owner', 'tenant_admin'])
            ->first()?->user
            ?? $this->tenant->memberships()->with('user')->where('status', 'active')->firstOrFail()->user;

        $this->loadSourceFiles();

        DB::transaction(function (): void {
            $this->prepareStructure();
            $this->prepareUsersAndPermissions();
            $this->prepareResponsibles();
            $this->prepareStandaloneScenarios();
            $this->prepareBatchScenarios();
        });

        $this->newLine();
        $this->info('Massa de Projetos criada/atualizada sem excluir registros existentes.');
        $this->table(['Item', 'Valor'], [
            ['Tenant', $this->tenant->slug],
            ['Contrato', $this->contract->code.' - '.$this->contract->name],
            ['Projetos no contrato', (string) $this->contract->projectDocuments()->count()],
            ['Pacotes no contrato', (string) ProjectSubmissionBatch::query()->where('contract_id', $this->contract->id)->count()],
            ['Usuarios de validacao', implode(', ', collect($this->users)->pluck('email')->all())],
        ]);

        return self::SUCCESS;
    }

    private function loadSourceFiles(): void
    {
        $versions = ProjectDocumentVersion::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('file_path')
            ->where(function ($query): void {
                $query->whereRaw("lower(original_name) like '%.dwg'")
                    ->orWhereRaw("lower(original_name) like '%.rvt'");
            })
            ->latest('id')
            ->get()
            ->filter(fn (ProjectDocumentVersion $version): bool => Storage::disk('public')->exists($version->file_path));

        foreach (['dwg', 'rvt'] as $extension) {
            $source = $versions->first(fn (ProjectDocumentVersion $version): bool => Str::lower(pathinfo($version->original_name, PATHINFO_EXTENSION)) === $extension);

            if ($source) {
                $this->sources[$extension] = $source;
            }
        }

        if ($this->sources === []) {
            throw new \RuntimeException('Nenhum arquivo DWG ou RVT existente foi encontrado no storage deste tenant.');
        }

        if (! isset($this->sources['dwg'])) {
            $this->sources['dwg'] = collect($this->sources)->first();
        }

        if (! isset($this->sources['rvt'])) {
            $this->sources['rvt'] = $this->sources['dwg'];
        }
    }

    private function prepareStructure(): void
    {
        $this->contract = Contract::query()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'code' => 'TSTPRJ2026'],
            [
                'name' => 'VALIDACAO COMPLETA - MODULO PROJETOS',
                'description' => 'Massa persistente para validar permissoes, revisoes, lotes, CAP, comentarios e arvore.',
                'status' => 'active',
                'starts_at' => now()->startOfYear(),
                'ends_at' => now()->addYear(),
            ],
        );
        $this->obra = Obra::query()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'codigo' => '901'],
            ['nome' => 'Obra de validacao de Projetos', 'tipo' => 'pai'],
        );
        $this->contract->forceFill(['obra_id' => $this->obra->id])->save();
        $this->trecho = Trecho::query()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'GER'],
            ['nome' => 'Geral', 'is_default' => true],
        );

        foreach ([
            'ARQ' => ['Arquitetura', '#8b5cf6'],
            'EST' => ['Estruturas', '#2563eb'],
            'PAV' => ['Pavimentacao', '#10b981'],
        ] as $code => [$name, $color]) {
            $this->disciplines[$code] = Disciplina::query()->updateOrCreate(
                ['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'sigla' => $code],
                ['nome' => $name, 'cor' => $color],
            );
        }

        $this->phase = ProjectPhase::query()->where('is_active', true)->orderBy('position')->firstOrFail();
    }

    private function prepareUsersAndPermissions(): void
    {
        $profiles = [
            'submitter' => ['Validador - Submissao', 'validacao.projetos.submissao@obras.test', [ProjectPermissions::VIEW, ProjectPermissions::UPLOAD, ProjectPermissions::UPLOAD_BATCH]],
            'reviewer' => ['Validador - Analise', 'validacao.projetos.analise@obras.test', [ProjectPermissions::VIEW, ProjectPermissions::REVIEW, ProjectPermissions::REVIEW_BATCH, ProjectPermissions::COMMENTS]],
            'approver' => ['Validador - Aprovacao', 'validacao.projetos.aprovacao@obras.test', [ProjectPermissions::VIEW, ProjectPermissions::REVIEW, ProjectPermissions::REVIEW_BATCH, ProjectPermissions::COMMENTS]],
            'viewer' => ['Validador - Visualizacao', 'validacao.projetos.visualizacao@obras.test', [ProjectPermissions::VIEW]],
        ];

        foreach ($profiles as $key => [$name, $email, $permissions]) {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => 'Senha1!', 'email_verified_at' => now(), 'is_platform_admin' => false],
            );
            $this->tenant->memberships()->updateOrCreate(
                ['user_id' => $user->id],
                ['role' => 'engineer', 'status' => 'active', 'project_permissions' => $permissions],
            );
            $this->contract->participants()->updateOrCreate(
                ['tenant_id' => $this->tenant->id, 'user_id' => $user->id],
                ['side' => 'manager', 'role' => 'engineer', 'status' => 'active', 'project_permissions' => $permissions],
            );
            $this->users[$key] = $user;
        }
    }

    private function prepareResponsibles(): void
    {
        foreach ($this->disciplines as $discipline) {
            foreach (['analise' => $this->users['reviewer'], 'aprovacao' => $this->users['approver']] as $type => $user) {
                ProjectDisciplineResponsavel::withTrashed()->updateOrCreate(
                    [
                        'tenant_id' => $this->tenant->id,
                        'contract_id' => $this->contract->id,
                        'disciplina_id' => $discipline->id,
                        'user_id' => $user->id,
                        'tipo' => $type,
                    ],
                    ['created_by_id' => $this->admin->id, 'status' => 'active', 'deleted_at' => null],
                );
            }
        }
    }

    private function prepareStandaloneScenarios(): void
    {
        $approved = $this->document('901', 'ARQ', 'Projeto aprovado com comentarios', 'ativo');
        $approvedVersion = $this->version($approved, 'R00', 'ativo', 'dwg', [
            'reviewed_by_id' => $this->users['reviewer']->id,
            'approved_by_id' => $this->users['approver']->id,
            'reviewed_at' => now()->subDays(10),
            'approved_at' => now()->subDays(9),
        ]);
        $approved->forceFill([
            'reviewed_by_id' => $this->users['reviewer']->id,
            'approved_by_id' => $this->users['approver']->id,
            'reviewed_at' => now()->subDays(10),
            'approved_at' => now()->subDays(9),
        ])->save();
        $this->reviewWorkspace($approvedVersion);

        $analysis = $this->document('902', 'EST', 'Projeto aguardando analise', 'em_analise');
        $this->version($analysis, 'R00', 'em_analise', 'rvt', ['derivative_status' => 'processing']);

        $approval = $this->document('903', 'PAV', 'Projeto aguardando aprovacao', 'em_aprovacao');
        $this->version($approval, 'R00', 'em_aprovacao', 'dwg', [
            'reviewed_by_id' => $this->users['reviewer']->id,
            'reviewed_at' => now()->subDay(),
            'review_notes' => 'Checklist e arquivo conferidos.',
        ]);

        $rejected = $this->document('904', 'ARQ', 'Projeto devolvido para correcao', 'reprovado');
        $this->version($rejected, 'R00', 'reprovado', 'dwg', [
            'derivative_status' => 'removed',
            'aps_urn' => null,
            'aps_object_id' => null,
            'review_notes' => 'Incompatibilidade identificada na analise.',
        ]);

        $inactive = $this->document('905', 'EST', 'Projeto bloqueado preventivamente', 'inativo');
        $this->version($inactive, 'R00', 'ativo', 'rvt');
        $inactive->forceFill([
            'inactive_at' => now()->subHours(4),
            'inactive_by_id' => $this->admin->id,
            'inactive_reason' => 'Bloqueio preventivo para corrigir uma interferencia urgente.',
        ])->save();
        ProjectDocumentStatusChange::query()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'project_document_id' => $inactive->id, 'to_status' => 'inativo'],
            ['user_id' => $this->admin->id, 'from_status' => 'ativo', 'reason' => $inactive->inactive_reason],
        );

        $revision = $this->document('906', 'ARQ', 'Projeto com revisao e CAP', 'em_analise');
        $this->version($revision, 'R00', 'ativo', 'dwg', [
            'approved_by_id' => $this->users['approver']->id,
            'approved_at' => now()->subDays(20),
        ]);
        $revisionVersion = $this->version($revision, 'R01', 'em_analise', 'dwg', [
            'revision_change_summary' => 'Eixos e aberturas revisados.',
        ]);
        if (blank($revisionVersion->cap_number)) {
            $capSequence = ProjectCap::nextSequence($this->tenant, (int) now()->year);
            $revisionVersion->forceFill([
                'cap_number' => ProjectCap::fromProjectCode($revision->code, 'R01'),
                'cap_sequence' => $capSequence,
                'cap_year' => now()->year,
                'cap_requested_by_id' => $this->users['submitter']->id,
                'cap_requested_at' => now()->subHours(8),
                'cap_reason' => 'Compatibilizacao de arquitetura e estrutura.',
                'cap_description' => 'Ajustes nas aberturas e nos eixos do projeto.',
                'cap_impacts' => ['compatibilidade', 'prazo'],
            ])->save();
        }
    }

    private function prepareBatchScenarios(): void
    {
        $initialBatch = $this->batch('TST-LOT-INICIAL-'.now()->year, 'Pacote inicial aguardando analise', 'em_analise', false);
        foreach ([['911', 'ARQ'], ['912', 'EST'], ['913', 'PAV']] as [$number, $discipline]) {
            $document = $this->document($number, $discipline, "Projeto {$discipline} do pacote inicial", 'em_analise');
            $this->version($document, 'R00', 'em_analise', $discipline === 'EST' ? 'rvt' : 'dwg', [
                'project_submission_batch_id' => $initialBatch->id,
            ]);
        }

        $revisionBatch = $this->batch('TST-LOT-REVISAO-'.now()->year, 'Pacote revisado com CAP consolidada', 'em_aprovacao', true);
        foreach ([['921', 'ARQ'], ['922', 'EST'], ['923', 'PAV']] as [$number, $discipline]) {
            $document = $this->document($number, $discipline, "Projeto {$discipline} revisado em lote", 'em_aprovacao');
            $this->version($document, 'R00', 'ativo', $discipline === 'EST' ? 'rvt' : 'dwg', [
                'approved_by_id' => $this->users['approver']->id,
                'approved_at' => now()->subMonth(),
            ]);
            $this->version($document, 'R01', 'em_aprovacao', $discipline === 'EST' ? 'rvt' : 'dwg', [
                'project_submission_batch_id' => $revisionBatch->id,
                'reviewed_by_id' => $this->users['reviewer']->id,
                'reviewed_at' => now()->subDay(),
            ]);
        }
    }

    private function document(string $number, string $disciplineCode, string $title, string $status): ProjectDocument
    {
        $discipline = $this->disciplines[$disciplineCode];

        return ProjectDocument::withTrashed()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'code' => $this->eapCode($disciplineCode, 'PRJ', $number)],
            [
                'obra_id' => $this->obra->id,
                'trecho_id' => $this->trecho->id,
                'disciplina_id' => $discipline->id,
                'project_phase_id' => $this->phase->id,
                'created_by_id' => $this->users['submitter']->id,
                'title' => $title,
                'document_number' => $number,
                'document_type' => 'projeto',
                'status' => $status,
                'deleted_at' => null,
            ],
        );
    }

    private function version(ProjectDocument $document, string $revision, string $status, string $sourceType, array $overrides = []): ProjectDocumentVersion
    {
        $source = $this->sources[$sourceType] ?? $this->sources['dwg'];
        $extension = Str::lower(pathinfo($source->original_name, PATHINFO_EXTENSION)) ?: 'dwg';
        $storedName = $document->eap($revision).'.'.$extension;
        $target = 'tenant-'.$this->tenant->id.'/projects/validation/'.$storedName;

        if (! Storage::disk('public')->exists($target)) {
            Storage::disk('public')->copy($source->file_path, $target);
        }

        return ProjectDocumentVersion::withTrashed()->updateOrCreate(
            ['project_document_id' => $document->id, 'revision' => $revision],
            array_merge([
                'tenant_id' => $this->tenant->id,
                'uploaded_by_id' => $this->users['submitter']->id,
                'status' => $status,
                'original_name' => 'VALIDACAO-'.$storedName,
                'stored_name' => $storedName,
                'file_path' => $target,
                'mime_type' => $source->mime_type,
                'file_size' => $source->file_size,
                'aps_object_id' => $source->aps_object_id,
                'aps_urn' => $source->aps_urn,
                'derivative_status' => $source->derivative_status === 'ready' ? 'ready' : 'not_submitted',
                'submitted_to_aps_at' => $source->submitted_to_aps_at,
                'processed_at' => $source->processed_at,
                'deleted_at' => null,
            ], $overrides),
        );
    }

    private function batch(string $number, string $title, string $status, bool $hasRevisions): ProjectSubmissionBatch
    {
        $existing = ProjectSubmissionBatch::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('package_number', $number)
            ->first();
        $sequence = $existing?->package_sequence
            ?? (((int) ProjectSubmissionBatch::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('sequence_year', now()->year)
                ->max('package_sequence')) + 1);
        $capSequence = $existing?->cap_sequence
            ?? ($hasRevisions ? ProjectCap::nextSequence($this->tenant, (int) now()->year) : null);
        $capNumber = $hasRevisions
            ? ProjectCap::fromBatch(
                $this->contract->code,
                $this->obra->codigo,
                $this->trecho->codigo,
                array_keys($this->disciplines),
                $this->phase->code,
                $capSequence,
                'R01',
            )
            : null;

        return ProjectSubmissionBatch::query()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'package_number' => $number],
            [
                'contract_id' => $this->contract->id,
                'obra_id' => $this->obra->id,
                'trecho_id' => $this->trecho->id,
                'project_phase_id' => $this->phase->id,
                'submitted_by_id' => $this->users['submitter']->id,
                'reviewed_by_id' => $status === 'em_aprovacao' ? $this->users['reviewer']->id : null,
                'package_sequence' => $sequence,
                'sequence_year' => now()->year,
                'title' => $title,
                'document_type' => 'projeto',
                'status' => $status,
                'has_revisions' => $hasRevisions,
                'cap_number' => $capNumber,
                'cap_sequence' => $capSequence,
                'cap_year' => $hasRevisions ? now()->year : null,
                'cap_requested_at' => $hasRevisions ? now()->subDays(2) : null,
                'cap_reason' => $hasRevisions ? 'Compatibilizacao conjunta do pacote.' : null,
                'cap_description' => $hasRevisions ? 'Revisao coordenada dos tres projetos do pacote.' : null,
                'cap_impacts' => $hasRevisions ? ['compatibilidade', 'prazo'] : null,
                'reviewed_at' => $status === 'em_aprovacao' ? now()->subDay() : null,
                'review_notes' => $status === 'em_aprovacao' ? 'Pacote conferido em conjunto.' : null,
            ],
        );
    }

    private function reviewWorkspace(ProjectDocumentVersion $version): void
    {
        $checklist = ProjectReviewChecklist::query()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'project_document_version_id' => $version->id],
            [
                'contract_id' => $this->contract->id,
                'project_document_id' => $version->project_document_id,
                'created_by_id' => $this->users['reviewer']->id,
                'status' => 'completed',
            ],
        );
        foreach ([
            'Conferir a EAP e a revisao do arquivo',
            'Verificar o carregamento correto no visualizador APS',
            'Conferir marcacoes e pendencias tecnicas',
        ] as $position => $label) {
            $checklist->items()->updateOrCreate(
                ['position' => $position + 1],
                [
                    'tenant_id' => $this->tenant->id,
                    'label' => $label,
                    'required' => true,
                    'checked' => true,
                    'checked_by_id' => $this->users['reviewer']->id,
                    'checked_at' => now()->subDays(8),
                ],
            );
        }

        $open = ProjectReviewMarkup::query()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'project_document_version_id' => $version->id, 'title' => 'Compatibilizar passagem de instalacoes'],
            [
                'contract_id' => $this->contract->id,
                'project_document_id' => $version->project_document_id,
                'created_by_id' => $this->users['reviewer']->id,
                'assigned_to_id' => $this->users['submitter']->id,
                'description' => 'Conferir a interferencia e registrar a solucao definida pela equipe.',
                'markup_type' => 'pin',
                'markup_payload' => ['source' => 'validation', 'visual_anchor' => ['type' => 'viewport', 'viewport' => ['x' => 0.55, 'y' => 0.45]]],
                'priority' => 'alta',
                'status' => 'in_progress',
            ],
        );
        $open->assignees()->sync([
            $this->users['submitter']->id => ['tenant_id' => $this->tenant->id],
            $this->users['approver']->id => ['tenant_id' => $this->tenant->id],
        ]);
        ProjectReviewMarkupReply::query()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'project_review_markup_id' => $open->id, 'body' => 'Ajuste em desenvolvimento pela equipe de projeto.'],
            ['created_by_id' => $this->users['submitter']->id, 'resolves_markup' => false],
        );

        $resolved = ProjectReviewMarkup::query()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'project_document_version_id' => $version->id, 'title' => 'Revisar identificacao da prancha'],
            [
                'contract_id' => $this->contract->id,
                'project_document_id' => $version->project_document_id,
                'created_by_id' => $this->users['reviewer']->id,
                'assigned_to_id' => $this->users['submitter']->id,
                'description' => 'Identificacao atualizada conforme o padrao do contrato.',
                'markup_type' => 'pin',
                'markup_payload' => ['source' => 'validation'],
                'priority' => 'normal',
                'status' => 'resolved',
                'closed_by_id' => $this->users['approver']->id,
                'closed_at' => now()->subDays(6),
            ],
        );
        $resolved->assignees()->sync([
            $this->users['submitter']->id => ['tenant_id' => $this->tenant->id],
            $this->users['approver']->id => ['tenant_id' => $this->tenant->id],
        ]);
        ProjectReviewMarkupReply::query()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'project_review_markup_id' => $resolved->id, 'body' => 'Solucao validada e comentario encerrado.'],
            ['created_by_id' => $this->users['approver']->id, 'resolves_markup' => true],
        );
    }

    private function eapCode(string $discipline, string $type, string $number): string
    {
        return implode('-', [
            $this->contract->code,
            $this->obra->codigo,
            $this->trecho->codigo,
            $discipline,
            $this->phase->code,
            $type,
            str_pad($number, 3, '0', STR_PAD_LEFT),
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\ProcessProjectVersionApsJob;
use App\Jobs\RemoveRejectedProjectVersionFromApsJob;
use App\Models\Contract;
use App\Models\Disciplina;
use App\Models\Empresa;
use App\Models\Obra;
use App\Models\ProjectDisciplineResponsavel;
use App\Models\ProjectDocument;
use App\Models\ProjectDocumentVersion;
use App\Models\ProjectPhase;
use App\Models\ProjectReviewChecklistItem;
use App\Models\ProjectReviewMarkup;
use App\Models\ProjectSubmissionBatch;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use App\Models\Trecho;
use App\Models\User;
use App\Notifications\ProjectApprovedNotification;
use App\Notifications\ProjectRejectedNotification;
use App\Notifications\ProjectReviewMarkupCreatedNotification;
use App\Notifications\ProjectSubmittedForReviewNotification;
use App\Notifications\ProjectVerifiedForApprovalNotification;
use App\Services\AutodeskApsService;
use App\Support\ProjectPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class TenantProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_user_with_project_permission_can_open_projects_page(): void
    {
        [$tenant, $user, $contract] = $this->tenantScenario('engineer');

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.projects.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Projects\/Index', false);
    }

    public function test_user_without_project_permission_cannot_open_projects_page(): void
    {
        [$tenant, $user] = $this->tenantScenario('engineer', []);

        $this->actingAs($user)
            ->get(route('tenant.projects.index', $tenant))
            ->assertForbidden();
    }

    public function test_submit_and_review_lists_are_ordered_by_latest_submission(): void
    {
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Bloco A',
            'codigo' => '001',
            'tipo' => 'pai',
        ]);
        $phase = $this->projectPhase('PB');

        $olderSubmission = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'disciplina_id' => $disciplina->id,
            'project_phase_id' => $phase->id,
            'created_by_id' => $user->id,
            'title' => 'Envio antigo',
            'code' => '001-001-GER-ARQ-PB-PRJ-001',
            'document_number' => '001',
            'document_type' => 'projeto',
            'status' => 'em_analise',
        ]);
        $olderVersion = $olderSubmission->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $user->id,
            'revision' => 'R00',
            'status' => 'em_analise',
            'original_name' => 'antigo.pdf',
            'stored_name' => 'antigo.pdf',
            'file_path' => 'projects/antigo.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'derivative_status' => 'not_submitted',
        ]);
        $olderVersion->forceFill([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ])->saveQuietly();

        $newerSubmission = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'disciplina_id' => $disciplina->id,
            'project_phase_id' => $phase->id,
            'created_by_id' => $user->id,
            'title' => 'Envio recente',
            'code' => '001-001-ARQ-PB-PRJ-002',
            'document_number' => '002',
            'document_type' => 'projeto',
            'status' => 'em_aprovacao',
        ]);
        $newerVersion = $newerSubmission->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $user->id,
            'revision' => 'R00',
            'status' => 'em_aprovacao',
            'original_name' => 'recente.pdf',
            'stored_name' => 'recente.pdf',
            'file_path' => 'projects/recente.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'derivative_status' => 'not_submitted',
        ]);
        $newerVersion->forceFill([
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ])->saveQuietly();

        foreach (['tenant.projects.index', 'tenant.projects.review.index'] as $routeName) {
            $this->actingAs($user)
                ->get(route($routeName, $tenant))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('documents.0.id', $newerSubmission->id)
                    ->where('documents.1.id', $olderSubmission->id));
        }
    }

    public function test_user_can_upload_project_document(): void
    {
        Storage::fake('public');

        [$tenant, $user, $contract] = $this->tenantScenario('engineer');
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Bloco A',
            'codigo' => '001',
            'tipo' => 'pai',
        ]);
        $phase = $this->projectPhase('PB');

        $this->actingAs($user)
            ->post(route('tenant.projects.store', $tenant), [
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'disciplina_id' => $disciplina->id,
                'project_phase_id' => $phase->id,
                'title' => 'Projeto Arquitetonico',
                'document_type' => 'projeto',
                'document_number' => '001',
                'revision' => 'r00',
                'file' => UploadedFile::fake()->create('planta.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $document = ProjectDocument::with('latestVersion')->firstOrFail();

        $this->assertDatabaseHas('project_documents', [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'disciplina_id' => $disciplina->id,
            'project_phase_id' => $phase->id,
            'title' => 'Projeto Arquitetonico',
            'code' => '001-001-GER-ARQ-PB-PRJ-001',
            'document_number' => '001',
            'document_type' => 'projeto',
            'status' => 'em_analise',
        ]);
        $this->assertSame('R00', $document->latestVersion->revision);
        $this->assertSame('em_analise', $document->latestVersion->status);
        $this->assertSame('planta.pdf', $document->latestVersion->original_name);
        $this->assertSame('001-001-GER-ARQ-PB-PRJ-001-R00.pdf', $document->latestVersion->stored_name);
        $this->assertSame('not_submitted', $document->latestVersion->derivative_status);
        $this->assertSame('local', $document->latestVersion->storage_disk);
        Storage::disk('local')->assertExists($document->latestVersion->file_path);
        $this->assertStringEndsWith('/001-001-GER-ARQ-PB-PRJ-001-R00.pdf', str_replace('\\', '/', $document->latestVersion->file_path));

        $this->actingAs($user)
            ->get(route('tenant.projects.versions.download', [$tenant, $document->latestVersion]))
            ->assertOk()
            ->assertDownload('planta.pdf');
    }

    public function test_project_download_rechecks_contract_permission(): void
    {
        [$tenant, $owner, $contract] = $this->tenantScenario('tenant_admin');
        $document = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $owner->id,
            'title' => 'Projeto protegido',
            'document_type' => 'projeto',
            'status' => 'aprovado',
        ]);
        $version = $document->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $owner->id,
            'revision' => 'R00',
            'status' => 'ativo',
            'original_name' => 'protegido.dwg',
            'stored_name' => 'protegido.dwg',
            'file_path' => 'tenant-protected/projects/protegido.dwg',
            'storage_disk' => 'local',
            'mime_type' => 'application/octet-stream',
            'file_size' => 7,
        ]);
        Storage::disk('local')->put($version->file_path, 'arquivo');

        $unauthorized = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $unauthorized->id,
            'role' => 'viewer',
            'status' => 'active',
            'project_permissions' => [],
        ]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $unauthorized->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'project_permissions' => [],
        ]);

        $this->actingAs($unauthorized)
            ->get(route('tenant.projects.versions.download', [$tenant, $version]))
            ->assertForbidden();
    }

    public function test_project_submission_uses_selected_trecho_in_eap(): void
    {
        Storage::fake('public');

        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Pavimentacao',
            'sigla' => 'PAV',
            'cor' => '#2563eb',
        ]);
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Rodovia Norte',
            'codigo' => '100',
            'tipo' => 'pai',
        ]);
        $trecho = Trecho::create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'codigo' => 'T01',
            'nome' => 'Km 0 ao Km 10',
        ]);
        $phase = $this->projectPhase('PE');

        $this->actingAs($user)
            ->post(route('tenant.projects.store', $tenant), [
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'trecho_id' => $trecho->id,
                'disciplina_id' => $disciplina->id,
                'project_phase_id' => $phase->id,
                'title' => 'Projeto do trecho norte',
                'document_type' => 'projeto',
                'document_number' => '003',
                'file' => UploadedFile::fake()->create('trecho-norte.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_documents', [
            'tenant_id' => $tenant->id,
            'trecho_id' => $trecho->id,
            'code' => '001-100-T01-PAV-PE-PRJ-003',
        ]);
        $this->assertDatabaseHas('project_document_versions', [
            'stored_name' => '001-100-T01-PAV-PE-PRJ-003-R00.pdf',
        ]);
    }

    public function test_trecho_management_validates_code_and_protects_linked_records(): void
    {
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Rodovia Sul',
            'codigo' => '200',
            'tipo' => 'pai',
        ]);

        $this->actingAs($user)
            ->from(route('tenant.parametrizacao.trechos.index', $tenant))
            ->post(route('tenant.parametrizacao.trechos.store', $tenant), [
                'obra_id' => $obra->id,
                'codigo' => 't02',
                'nome' => 'Km 10 ao Km 20',
            ])
            ->assertRedirect();

        $trecho = Trecho::query()->where('obra_id', $obra->id)->where('codigo', 'T02')->firstOrFail();
        $this->assertSame('Km 10 ao Km 20', $trecho->nome);

        ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'trecho_id' => $trecho->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto vinculado ao trecho',
            'code' => '001-200-T02-PAV-PE-PRJ-001',
            'document_number' => '001',
            'document_type' => 'projeto',
            'status' => 'em_analise',
        ]);

        $this->actingAs($user)
            ->delete(route('tenant.parametrizacao.trechos.destroy', [$tenant, $trecho]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('trechos', [
            'id' => $trecho->id,
            'deleted_at' => null,
        ]);

        $this->actingAs($user)
            ->from(route('tenant.parametrizacao.trechos.index', $tenant))
            ->post(route('tenant.parametrizacao.trechos.store', $tenant), [
                'obra_id' => $obra->id,
                'codigo' => 'LONGO',
                'nome' => 'Codigo invalido',
            ])
            ->assertSessionHasErrors('codigo');
    }

    public function test_project_submission_queues_aps_processing_when_configured(): void
    {
        Queue::fake();
        Storage::fake('public');
        config()->set('services.autodesk_aps.client_id', 'client-id');
        config()->set('services.autodesk_aps.client_secret', 'client-secret');
        config()->set('services.autodesk_aps.bucket_key', 'bucket-key');
        config()->set('services.autodesk_aps.auto_process', true);

        [$tenant, $user, $contract] = $this->tenantScenario('engineer');
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Bloco A',
            'codigo' => '001',
            'tipo' => 'pai',
        ]);
        $phase = $this->projectPhase('PB');

        $this->actingAs($user)
            ->post(route('tenant.projects.store', $tenant), [
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'disciplina_id' => $disciplina->id,
                'project_phase_id' => $phase->id,
                'title' => 'Projeto Arquitetonico',
                'document_type' => 'projeto',
                'document_number' => '001',
                'file' => UploadedFile::fake()->create('planta.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $document = ProjectDocument::with('latestVersion')->firstOrFail();

        $this->assertSame('queued', $document->latestVersion->derivative_status);
        Queue::assertPushed(ProcessProjectVersionApsJob::class, fn (ProcessProjectVersionApsJob $job): bool => $job->queue === 'aps');
    }

    public function test_aps_processing_retries_before_marking_version_as_failed(): void
    {
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $document = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto APS',
            'document_type' => 'projeto',
            'status' => 'em_analise',
        ]);
        $version = $document->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $user->id,
            'revision' => 'R00',
            'status' => 'em_analise',
            'original_name' => 'aps.rvt',
            'stored_name' => 'aps.rvt',
            'file_path' => 'tenant-aps/projects/aps.rvt',
            'storage_disk' => 'local',
            'mime_type' => 'application/octet-stream',
            'file_size' => 7,
            'derivative_status' => 'queued',
        ]);

        $aps = Mockery::mock(AutodeskApsService::class);
        $aps->shouldReceive('isConfigured')->once()->andReturnTrue();
        $aps->shouldReceive('submitVersion')->once()->andThrow(new \RuntimeException('APS indisponivel'));

        $job = new ProcessProjectVersionApsJob($version->id);
        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120], $job->backoff);

        try {
            $job->handle($aps);
            $this->fail('A falha temporaria da APS deveria ser devolvida para a fila.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('APS indisponivel', $exception->getMessage());
        }

        $this->assertSame('queued', $version->fresh()->derivative_status);

        $job->failed(null);

        $this->assertSame('failed', $version->fresh()->derivative_status);
        $this->assertNotNull($version->fresh()->processed_at);
    }

    public function test_project_submission_reuses_same_eap_and_creates_next_revision(): void
    {
        Storage::fake('public');

        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Bloco A',
            'codigo' => '001',
            'tipo' => 'pai',
        ]);
        $phase = $this->projectPhase('PB');

        $payload = [
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'disciplina_id' => $disciplina->id,
            'project_phase_id' => $phase->id,
            'title' => 'Projeto Arquitetonico',
            'document_type' => 'projeto',
            'document_number' => '001',
        ];

        $this->actingAs($user)
            ->post(route('tenant.projects.store', $tenant), [
                ...$payload,
                'file' => UploadedFile::fake()->create('planta-r00.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $approvedDocument = ProjectDocument::with('latestVersion')->firstOrFail();
        $approvedDocument->forceFill(['status' => 'ativo'])->save();
        $approvedDocument->latestVersion->forceFill(['status' => 'ativo'])->save();

        $this->actingAs($user)
            ->from(route('tenant.projects.index', $tenant))
            ->post(route('tenant.projects.store', $tenant), [
                ...$payload,
                'file' => UploadedFile::fake()->create('planta-r01.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect(route('tenant.projects.index', $tenant))
            ->assertSessionHasErrors('cap_reason');

        $this->actingAs($user)
            ->post(route('tenant.projects.store', $tenant), [
                ...$payload,
                'title' => 'Titulo que nao deve sobrescrever',
                'cap_reason' => 'Compatibilizacao com estrutura.',
                'cap_description' => 'Alteracao de layout e compatibilizacao com estrutura.',
                'cap_impacts' => ['custo', 'prazo'],
                'file' => UploadedFile::fake()->create('planta-r01.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $document = ProjectDocument::with('versions')->firstOrFail();

        $this->assertDatabaseCount('project_documents', 1);
        $this->assertSame('em_analise', $document->status);
        $this->assertSame('Projeto Arquitetonico', $document->title);
        $this->assertSame('001-001-GER-ARQ-PB-PRJ-001', $document->code);
        $this->assertSame(['R00', 'R01'], $document->versions->pluck('revision')->all());
        $this->assertSame(['001-001-GER-ARQ-PB-PRJ-001-R00.pdf', '001-001-GER-ARQ-PB-PRJ-001-R01.pdf'], $document->versions->pluck('stored_name')->all());
        $this->assertSame('Alteracao de layout e compatibilizacao com estrutura.', $document->versions->last()->revision_change_summary);
        $this->assertSame('001-001-GER-ARQ-PB-CAP-001-R01', $document->versions->last()->cap_number);
        $this->assertSame('Compatibilizacao com estrutura.', $document->versions->last()->cap_reason);
        $this->assertSame(['custo', 'prazo'], $document->versions->last()->cap_impacts);
    }

    public function test_rejected_project_resubmission_creates_revision_without_cap(): void
    {
        Storage::fake('public');

        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Bloco A',
            'codigo' => '001',
            'tipo' => 'pai',
        ]);
        $phase = $this->projectPhase('PB');
        $payload = [
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'disciplina_id' => $disciplina->id,
            'project_phase_id' => $phase->id,
            'title' => 'Projeto Arquitetônico',
            'document_type' => 'projeto',
            'document_number' => '001',
        ];

        $this->actingAs($user)
            ->post(route('tenant.projects.store', $tenant), [
                ...$payload,
                'file' => UploadedFile::fake()->create('planta-r00.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $document = ProjectDocument::with('latestVersion')->firstOrFail();
        $document->forceFill(['status' => 'reprovado', 'review_notes' => 'Corrigir cotas.'])->save();
        $document->latestVersion->forceFill(['status' => 'reprovado', 'review_notes' => 'Corrigir cotas.'])->save();

        $this->actingAs($user)
            ->from(route('tenant.projects.index', $tenant))
            ->post(route('tenant.projects.store', $tenant), [
                ...$payload,
                'file' => UploadedFile::fake()->create('planta-r01.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect(route('tenant.projects.index', $tenant))
            ->assertSessionHasErrors('revision_change_summary');

        $this->actingAs($user)
            ->post(route('tenant.projects.store', $tenant), [
                ...$payload,
                'revision_change_summary' => 'Cotas corrigidas conforme o motivo da reprovação.',
                'file' => UploadedFile::fake()->create('planta-r01.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $document->refresh()->load('versions');
        $newVersion = $document->versions->last();

        $this->assertSame(['R00', 'R01'], $document->versions->pluck('revision')->all());
        $this->assertSame('em_analise', $document->status);
        $this->assertSame('Cotas corrigidas conforme o motivo da reprovação.', $newVersion->revision_change_summary);
        $this->assertNull($newVersion->cap_number);
        $this->assertNull($newVersion->cap_reason);
        $this->assertNull($newVersion->cap_description);
        $this->assertNull($newVersion->cap_impacts);
    }

    public function test_revised_projects_page_lists_versions_with_cap(): void
    {
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Bloco A',
            'codigo' => '001',
            'tipo' => 'pai',
        ]);
        $phase = $this->projectPhase('PB');
        $document = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'disciplina_id' => $disciplina->id,
            'project_phase_id' => $phase->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto revisado',
            'code' => '001-001-ARQ-PB-PRJ-001',
            'document_number' => '001',
            'document_type' => 'projeto',
            'status' => 'em_analise',
        ]);
        $baseVersion = $document->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $user->id,
            'revision' => 'R00',
            'status' => 'ativo',
            'original_name' => 'projeto-r00.pdf',
            'stored_name' => '001-001-ARQ-PB-PRJ-001-R00.pdf',
            'file_path' => 'tenant-1/projects/projeto-r00.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 120,
            'aps_urn' => 'urn-base-version',
            'derivative_status' => 'ready',
        ]);
        $currentVersion = $document->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $user->id,
            'cap_requested_by_id' => $user->id,
            'revision' => 'R01',
            'status' => 'em_analise',
            'cap_number' => 'CAP-001-'.now()->year,
            'cap_sequence' => 1,
            'cap_year' => now()->year,
            'cap_requested_at' => now(),
            'cap_reason' => 'Compatibilizacao com obra.',
            'cap_description' => 'Ajuste de prancha conforme interferencias.',
            'cap_impacts' => ['compatibilidade'],
            'original_name' => 'projeto-r01.pdf',
            'stored_name' => '001-001-ARQ-PB-PRJ-001-R01.pdf',
            'file_path' => 'tenant-1/projects/projeto-r01.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 123,
            'aps_urn' => 'urn-current-version',
            'derivative_status' => 'ready',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.projects.revisions.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Projects\/Revisions', false)
            ->assertSee('CAP-001-'.now()->year);

        $this->actingAs($user)
            ->get(route('tenant.projects.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Projects/Index')
                ->where('documents.0.latest_cap_version.id', $currentVersion->id)
                ->where('documents.0.latest_cap_version.cap_number', 'CAP-001-'.now()->year));

        $pdfResponse = $this->actingAs($user)
            ->get(route('tenant.projects.cap.pdf', [$tenant, $currentVersion]));

        $pdfResponse->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $pdfResponse->headers->get('content-type'));

        $this->actingAs($user)
            ->get(route('tenant.projects.compare', [$tenant, $currentVersion, $baseVersion]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Projects/Comparison')
                ->where('baseVersion.id', $baseVersion->id)
                ->where('currentVersion.id', $currentVersion->id));
    }

    public function test_project_tree_lists_only_approved_documents(): void
    {
        [$tenant, $user, $contract] = $this->tenantScenario('engineer', [ProjectPermissions::VIEW]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Bloco A',
            'codigo' => '001',
            'tipo' => 'pai',
        ]);
        $phase = $this->projectPhase('PE');
        $approved = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'disciplina_id' => $disciplina->id,
            'project_phase_id' => $phase->id,
            'created_by_id' => $user->id,
            'approved_by_id' => $user->id,
            'title' => 'Projeto aprovado na arvore',
            'code' => '001-001-ARQ-PE-PRJ-001',
            'document_number' => '001',
            'document_type' => 'projeto',
            'status' => 'ativo',
            'approved_at' => now(),
        ]);
        $approvedVersion = $approved->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $user->id,
            'revision' => 'R00',
            'status' => 'ativo',
            'approved_by_id' => $user->id,
            'approved_at' => now(),
            'original_name' => 'aprovado.pdf',
            'file_path' => 'tenant-1/projects/aprovado.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 120,
        ]);
        $approved->forceFill(['status' => 'em_analise'])->save();
        $approved->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $user->id,
            'revision' => 'R01',
            'status' => 'em_analise',
            'original_name' => 'revisao-pendente.pdf',
            'file_path' => 'tenant-1/projects/revisao-pendente.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 120,
        ]);
        ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'disciplina_id' => $disciplina->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto ainda em analise',
            'document_type' => 'projeto',
            'status' => 'em_analise',
        ]);
        $inactive = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'disciplina_id' => $disciplina->id,
            'project_phase_id' => $phase->id,
            'created_by_id' => $user->id,
            'inactive_by_id' => $user->id,
            'title' => 'Projeto aprovado inativo',
            'code' => '001-001-ARQ-PE-PRJ-002',
            'document_number' => '002',
            'document_type' => 'projeto',
            'status' => 'inativo',
            'inactive_at' => now(),
            'inactive_reason' => 'Substituido por outro projeto.',
        ]);
        $inactive->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $user->id,
            'revision' => 'R00',
            'status' => 'ativo',
            'approved_by_id' => $user->id,
            'approved_at' => now(),
            'original_name' => 'inativo.pdf',
            'file_path' => 'tenant-1/projects/inativo.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 120,
        ]);

        $this->actingAs($user)
            ->get(route('tenant.projects.visualizar.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Projects\/Tree', false)
            ->assertSee('Projeto aprovado na arvore')
            ->assertDontSee('Projeto ainda em analise')
            ->assertDontSee('Projeto aprovado inativo')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Projects/Tree')
                ->where('documents.0.status', 'em_analise')
                ->where('documents.0.latest_version.revision', 'R01')
                ->where('documents.0.latest_approved_version.revision', 'R00'));

        $this->actingAs($user)
            ->get(route('tenant.projects.viewer', [$tenant, $approvedVersion]).'?workspace=view&origin=visualizar')
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.projects.viewer', [$tenant, $approvedVersion]).'?workspace=comments')
            ->assertForbidden();
    }

    public function test_project_submission_notifies_discipline_reviewers_by_database_and_mail(): void
    {
        Notification::fake();
        Storage::fake('public');

        [$tenant, $admin, $contract] = $this->tenantScenario('tenant_admin');
        $reviewer = User::factory()->create();
        $approver = User::factory()->create();
        $notResponsible = User::factory()->create();
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $reviewer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $approver->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $notResponsible->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Bloco A',
            'codigo' => '001',
            'tipo' => 'pai',
        ]);
        $phase = $this->projectPhase('PE');
        ProjectDisciplineResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'disciplina_id' => $disciplina->id,
            'user_id' => $reviewer->id,
            'created_by_id' => $admin->id,
            'tipo' => 'analise',
            'status' => 'active',
        ]);
        ProjectDisciplineResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'disciplina_id' => $disciplina->id,
            'user_id' => $approver->id,
            'created_by_id' => $admin->id,
            'tipo' => 'aprovacao',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('tenant.projects.store', $tenant), [
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'disciplina_id' => $disciplina->id,
                'project_phase_id' => $phase->id,
                'title' => 'Projeto para analise',
                'document_type' => 'projeto',
                'document_number' => '001',
                'revision' => 'R00',
                'file' => UploadedFile::fake()->create('projeto.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentTo($reviewer, ProjectSubmittedForReviewNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });
        Notification::assertNotSentTo($approver, ProjectSubmittedForReviewNotification::class);
        Notification::assertNotSentTo($notResponsible, ProjectSubmittedForReviewNotification::class);
        Notification::assertCount(1);
    }

    public function test_user_with_review_permission_can_send_project_to_approval_and_approve_it(): void
    {
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $document = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto Arquitetonico',
            'document_type' => 'projeto',
            'status' => 'em_analise',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.projects.review.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Projects\/Review', false);

        $this->actingAs($user)
            ->patch(route('tenant.projects.review.update', [$tenant, $document]), [
                'action' => 'aprovar',
                'review_notes' => 'Verificado para aprovacao.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_documents', [
            'id' => $document->id,
            'status' => 'em_aprovacao',
            'reviewed_by_id' => $user->id,
            'review_notes' => 'Verificado para aprovacao.',
        ]);
        $this->assertNotNull($document->refresh()->reviewed_at);

        $this->actingAs($user)
            ->patch(route('tenant.projects.review.update', [$tenant, $document]), [
                'action' => 'aprovar',
                'review_notes' => 'Aprovado para emissao.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_documents', [
            'id' => $document->id,
            'status' => 'ativo',
            'approved_by_id' => $user->id,
            'approval_notes' => 'Aprovado para emissao.',
        ]);
        $this->assertNotNull($document->refresh()->approved_at);
    }

    public function test_project_rejection_requires_reason_and_notifies_latest_version_uploader(): void
    {
        Notification::fake();
        Queue::fake();

        [$tenant, $reviewer, $contract] = $this->tenantScenario('tenant_admin');
        $creator = User::factory()->create();
        $submitter = User::factory()->create();
        $document = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $creator->id,
            'title' => 'Projeto estrutural para revisão',
            'code' => 'CT001-001-EST-PE-PRJ-001',
            'document_type' => 'projeto',
            'status' => 'em_analise',
        ]);
        $document->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $submitter->id,
            'revision' => 'R01',
            'status' => 'em_analise',
            'original_name' => 'estrutura-r01.pdf',
            'stored_name' => 'estrutura-r01.pdf',
            'file_path' => 'projects/estrutura-r01.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ]);

        $this->actingAs($reviewer)
            ->patch(route('tenant.projects.review.update', [$tenant, $document]), [
                'action' => 'reprovar',
                'review_notes' => '   ',
            ])
            ->assertSessionHasErrors('review_notes');

        $this->assertSame('em_analise', $document->fresh()->status);
        Notification::assertNothingSent();

        $reason = 'Compatibilizar os pilares com a arquitetura e reenviar a revisão.';

        $this->actingAs($reviewer)
            ->patch(route('tenant.projects.review.update', [$tenant, $document]), [
                'action' => 'reprovar',
                'review_notes' => $reason,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('project_documents', [
            'id' => $document->id,
            'status' => 'reprovado',
            'reviewed_by_id' => $reviewer->id,
            'review_notes' => $reason,
        ]);
        $this->assertDatabaseHas('project_document_versions', [
            'project_document_id' => $document->id,
            'status' => 'reprovado',
            'review_notes' => $reason,
        ]);

        Notification::assertSentTo($submitter, ProjectRejectedNotification::class, function ($notification, array $channels) use ($submitter, $reason): bool {
            $mail = $notification->toMail($submitter);

            return in_array('database', $channels, true)
                && in_array('mail', $channels, true)
                && $mail->subject === 'Projeto reprovado: Projeto estrutural para revisão'
                && data_get($mail->viewData, 'reason') === $reason
                && data_get($mail->viewData, 'stageLabel') === 'Análise técnica';
        });
        Notification::assertNotSentTo($creator, ProjectRejectedNotification::class);
        Queue::assertPushed(RemoveRejectedProjectVersionFromApsJob::class);
        $this->assertSame('removing', $document->latestVersion()->first()->derivative_status);
    }

    public function test_rejected_project_cleanup_removes_aps_but_preserves_local_file_and_history(): void
    {
        Storage::fake('public');

        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $document = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto rejeitado',
            'document_type' => 'projeto',
            'status' => 'reprovado',
            'review_notes' => 'Corrigir interferências.',
        ]);
        $version = $document->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $user->id,
            'revision' => 'R00',
            'status' => 'reprovado',
            'original_name' => 'projeto-rejeitado.rvt',
            'stored_name' => 'CT001-001-ARQ-PE-PRJ-001-R00.rvt',
            'file_path' => 'projects/projeto-rejeitado.rvt',
            'mime_type' => 'application/octet-stream',
            'file_size' => 2048,
            'aps_object_id' => 'urn:adsk.objects:os.object:bucket/projeto-rejeitado.rvt',
            'aps_urn' => 'aps-urn',
            'derivative_status' => 'removing',
        ]);
        Storage::disk('public')->put($version->file_path, 'arquivo');

        $aps = Mockery::mock(AutodeskApsService::class);
        $aps->shouldReceive('deleteVersionFromAps')
            ->once()
            ->with(Mockery::on(fn ($candidate): bool => $candidate->is($version)))
            ->andReturn($version);

        (new RemoveRejectedProjectVersionFromApsJob($version->id))->handle($aps);

        Storage::disk('public')->assertExists('projects/projeto-rejeitado.rvt');
        $this->assertDatabaseHas('project_document_versions', [
            'id' => $version->id,
            'status' => 'reprovado',
            'file_path' => 'projects/projeto-rejeitado.rvt',
            'file_size' => 2048,
            'aps_object_id' => null,
            'aps_urn' => null,
            'derivative_status' => 'removed',
        ]);
        $this->assertDatabaseHas('project_documents', [
            'id' => $document->id,
            'status' => 'reprovado',
            'review_notes' => 'Corrigir interferências.',
        ]);
        $this->assertNull($version->fresh()->url);
    }

    public function test_tenant_admin_can_manage_project_discipline_responsibles(): void
    {
        [$tenant, $admin, $contract] = $this->tenantScenario('tenant_admin');
        $user = User::factory()->create();
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $segundaDisciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Estrutura',
            'sigla' => 'EST',
            'cor' => '#0f9d63',
        ]);

        $this->actingAs($admin)
            ->get(route('tenant.projects.responsaveis.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Projects\/Responsaveis', false);

        $this->actingAs($admin)
            ->post(route('tenant.projects.responsaveis.store', $tenant), [
                'contract_id' => $contract->id,
                'disciplina_ids' => [$disciplina->id, $segundaDisciplina->id],
                'user_id' => $user->id,
                'tipo' => 'analise',
            ])
            ->assertRedirect();

        $responsavel = ProjectDisciplineResponsavel::where('disciplina_id', $disciplina->id)->firstOrFail();

        $this->assertDatabaseHas('project_discipline_responsaveis', [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'disciplina_id' => $disciplina->id,
            'user_id' => $user->id,
            'tipo' => 'analise',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('project_discipline_responsaveis', [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'disciplina_id' => $segundaDisciplina->id,
            'user_id' => $user->id,
            'tipo' => 'analise',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->delete(route('tenant.projects.responsaveis.destroy', [$tenant, $responsavel]))
            ->assertRedirect();

        $this->assertSoftDeleted('project_discipline_responsaveis', [
            'id' => $responsavel->id,
        ]);
    }

    public function test_project_reviewer_only_sees_documents_from_assigned_disciplines(): void
    {
        [$tenant, $reviewer, $contract] = $this->tenantScenario('engineer', [
            ProjectPermissions::VIEW,
            ProjectPermissions::REVIEW,
        ]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $reviewer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);
        $arquitetura = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $estrutura = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Estrutura',
            'sigla' => 'EST',
            'cor' => '#0f9d63',
        ]);
        $visibleDocument = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'disciplina_id' => $arquitetura->id,
            'created_by_id' => $reviewer->id,
            'title' => 'Projeto ARQ',
            'document_type' => 'projeto',
            'status' => 'em_analise',
        ]);
        $hiddenDocument = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'disciplina_id' => $estrutura->id,
            'created_by_id' => $reviewer->id,
            'title' => 'Projeto EST',
            'document_type' => 'projeto',
            'status' => 'em_analise',
        ]);
        ProjectDisciplineResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'disciplina_id' => $arquitetura->id,
            'user_id' => $reviewer->id,
            'created_by_id' => $reviewer->id,
            'tipo' => 'analise',
            'status' => 'active',
        ]);

        $this->actingAs($reviewer)
            ->get(route('tenant.projects.review.index', $tenant))
            ->assertOk()
            ->assertSee('Projeto ARQ')
            ->assertDontSee('Projeto EST');

        $this->actingAs($reviewer)
            ->patch(route('tenant.projects.review.update', [$tenant, $visibleDocument]), [
                'action' => 'aprovar',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_documents', [
            'id' => $visibleDocument->id,
            'status' => 'em_aprovacao',
        ]);

        $this->actingAs($reviewer)
            ->patch(route('tenant.projects.review.update', [$tenant, $hiddenDocument]), [
                'action' => 'aprovar',
            ])
            ->assertForbidden();
    }

    public function test_project_verification_notifies_approver_and_only_approver_can_release_to_tree(): void
    {
        Notification::fake();

        [$tenant, $reviewer, $contract] = $this->tenantScenario('engineer', [
            ProjectPermissions::VIEW,
            ProjectPermissions::REVIEW,
        ]);
        $approver = User::factory()->create();
        $observer = User::factory()->create();
        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);
        $tenant->memberships()->create([
            'user_id' => $approver->id,
            'role' => 'engineer',
            'status' => 'active',
            'project_permissions' => [
                ProjectPermissions::VIEW,
                ProjectPermissions::REVIEW,
            ],
        ]);
        foreach ([$reviewer, $approver, $observer] as $user) {
            $contract->participants()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'side' => 'manager',
                'role' => 'team_member',
                'status' => 'active',
            ]);
        }
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $platformAdmin->id,
            'side' => 'manager',
            'role' => 'manager',
            'status' => 'active',
        ]);
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $document = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'disciplina_id' => $disciplina->id,
            'created_by_id' => $reviewer->id,
            'title' => 'Projeto ARQ',
            'document_type' => 'projeto',
            'status' => 'em_analise',
        ]);
        $document->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $reviewer->id,
            'revision' => 'R01',
            'status' => 'em_analise',
            'cap_number' => '001-001-ARQ-PB-CAP-001-R01',
            'cap_sequence' => 1,
            'cap_year' => now()->year,
            'cap_requested_at' => now(),
            'cap_reason' => 'Compatibilizacao aprovada.',
            'cap_description' => 'Projeto revisado conforme interferencias.',
            'cap_impacts' => ['compatibilidade'],
            'original_name' => 'projeto-r01.pdf',
            'stored_name' => '001-001-ARQ-PB-PRJ-001-R01.pdf',
            'file_path' => 'tenant-1/projects/projeto-r01.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 120,
            'derivative_status' => 'ready',
        ]);
        ProjectDisciplineResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'disciplina_id' => $disciplina->id,
            'user_id' => $reviewer->id,
            'created_by_id' => $reviewer->id,
            'tipo' => 'analise',
            'status' => 'active',
        ]);
        ProjectDisciplineResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'disciplina_id' => $disciplina->id,
            'user_id' => $approver->id,
            'created_by_id' => $reviewer->id,
            'tipo' => 'aprovacao',
            'status' => 'active',
        ]);

        $this->actingAs($reviewer)
            ->patch(route('tenant.projects.review.update', [$tenant, $document]), [
                'action' => 'aprovar',
                'review_notes' => 'Analise tecnica concluida.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_documents', [
            'id' => $document->id,
            'status' => 'em_aprovacao',
            'reviewed_by_id' => $reviewer->id,
            'review_notes' => 'Analise tecnica concluida.',
        ]);
        Notification::assertSentTo($approver, ProjectVerifiedForApprovalNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });
        Notification::assertNotSentTo($observer, ProjectVerifiedForApprovalNotification::class);

        $this->actingAs($reviewer)
            ->patch(route('tenant.projects.review.update', [$tenant, $document]), [
                'action' => 'aprovar',
            ])
            ->assertForbidden();

        $this->actingAs($approver)
            ->patch(route('tenant.projects.review.update', [$tenant, $document]), [
                'action' => 'aprovar',
                'review_notes' => 'Liberado para arvore.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_documents', [
            'id' => $document->id,
            'status' => 'ativo',
            'approved_by_id' => $approver->id,
            'approval_notes' => 'Liberado para arvore.',
        ]);
        $this->assertNotNull($document->refresh()->approved_at);

        foreach ([$reviewer, $approver, $observer] as $user) {
            Notification::assertSentTo($user, ProjectApprovedNotification::class, function ($notification, array $channels) use ($user): bool {
                $payload = $notification->toArray($user);

                return in_array('database', $channels, true)
                    && in_array('mail', $channels, true)
                    && $payload['title'] === 'Revisao de projeto aprovada'
                    && $payload['revision'] === 'R01'
                    && $payload['cap_number'] === '001-001-ARQ-PB-CAP-001-R01';
            });
        }
        Notification::assertNotSentTo($platformAdmin, ProjectApprovedNotification::class);
    }

    public function test_user_without_review_permission_cannot_open_project_review_page(): void
    {
        [$tenant, $user] = $this->tenantScenario('engineer', [ProjectPermissions::VIEW, ProjectPermissions::UPLOAD]);

        $this->actingAs($user)
            ->get(route('tenant.projects.review.index', $tenant))
            ->assertForbidden();
    }

    public function test_project_reviewer_can_open_pending_version_viewer_and_manage_review_workspace(): void
    {
        Notification::fake();

        [$tenant, $user, $contract] = $this->tenantScenario('engineer', [
            ProjectPermissions::REVIEW,
            ProjectPermissions::COMMENTS,
        ]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'project_permissions' => [
                ProjectPermissions::REVIEW,
                ProjectPermissions::COMMENTS,
            ],
        ]);
        $assignee = User::factory()->create();
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $assignee->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'project_permissions' => [ProjectPermissions::VIEW],
        ]);
        $secondaryAssignee = User::factory()->create();
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $secondaryAssignee->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'project_permissions' => [ProjectPermissions::VIEW],
        ]);
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Bloco A',
            'codigo' => '001',
            'tipo' => 'pai',
        ]);
        $phase = $this->projectPhase('PE');
        $document = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'disciplina_id' => $disciplina->id,
            'project_phase_id' => $phase->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto em analise',
            'code' => '001-001-ARQ-PE-PRJ-001',
            'document_number' => '001',
            'document_type' => 'projeto',
            'status' => 'em_analise',
        ]);
        $version = $document->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $user->id,
            'revision' => 'R00',
            'status' => 'em_analise',
            'original_name' => 'analise.pdf',
            'stored_name' => '001-001-ARQ-PE-PRJ-001-R00.pdf',
            'file_path' => 'tenant-1/projects/analise.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 120,
            'aps_urn' => 'fake-urn',
            'derivative_status' => 'ready',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.projects.viewer', [$tenant, $version]))
            ->assertOk()
            ->assertSee('Tenant\/Projects\/Viewer', false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Projects/Viewer')
                ->where('workspaceMode', 'review')
                ->where('showCommentsPanel', true)
                ->where('showChecklistPanel', true)
                ->where('canManageProjectComments', true)
            );

        $this->actingAs($user)
            ->get(route('tenant.projects.viewer', [$tenant, $version]).'?workspace=view')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Projects/Viewer')
                ->where('workspaceMode', 'view')
                ->where('showCommentsPanel', false)
                ->where('showChecklistPanel', false)
            );

        $this->actingAs($user)
            ->get(route('tenant.projects.viewer', [$tenant, $version]).'?workspace=comments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Projects/Viewer')
                ->where('workspaceMode', 'comments')
                ->where('showCommentsPanel', true)
                ->where('showChecklistPanel', false)
            );

        $this->assertDatabaseHas('project_review_checklists', [
            'tenant_id' => $tenant->id,
            'project_document_version_id' => $version->id,
        ]);
        $this->assertDatabaseCount('project_review_checklist_items', 3);
        $this->assertDatabaseHas('project_review_checklist_items', [
            'label' => 'Verificar se a EAP está correta (contrato-obra-trecho-disciplina-fase-tipo-sequencial-revisão)',
        ]);
        $this->assertDatabaseHas('project_review_checklist_items', [
            'label' => 'Verificar se o arquivo abre e carrega corretamente no APS',
        ]);
        $this->assertDatabaseHas('project_review_checklist_items', [
            'label' => 'Verificar se há marcações e pendências técnicas.',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.projects.markups.store', [$tenant, $version]), [
                'title' => 'Ajustar detalhe',
                'description' => 'Conferir detalhe no corte.',
                'assigned_to_ids' => [$user->id, $secondaryAssignee->id],
                'priority' => 'alta',
                'viewer_state' => ['viewport' => ['name' => 'teste']],
                'markup_payload' => [
                    'source' => 'aps_viewer',
                    'visual_anchor' => [
                        'type' => 'viewport',
                        'viewport' => ['x' => 0.5, 'y' => 0.5],
                    ],
                    'markups_core_svg' => '<svg><path d="M 10 10 L 80 80" /></svg>',
                    'markups_core_tool' => 'arrow',
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_review_markups', [
            'tenant_id' => $tenant->id,
            'project_document_version_id' => $version->id,
            'assigned_to_id' => $user->id,
            'title' => 'Ajustar detalhe',
            'priority' => 'alta',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('project_review_markup_assignees', [
            'project_review_markup_id' => ProjectReviewMarkup::where('title', 'Ajustar detalhe')->value('id'),
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('project_review_markup_assignees', [
            'project_review_markup_id' => ProjectReviewMarkup::where('title', 'Ajustar detalhe')->value('id'),
            'user_id' => $secondaryAssignee->id,
        ]);
        Notification::assertSentTo($user, ProjectReviewMarkupCreatedNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });
        Notification::assertSentTo($secondaryAssignee, ProjectReviewMarkupCreatedNotification::class);

        $markup = ProjectReviewMarkup::where('title', 'Ajustar detalhe')->firstOrFail();
        $this->assertSame('aps_viewer', $markup->markup_payload['source']);
        $this->assertSame('viewport', $markup->markup_payload['visual_anchor']['type']);
        $this->assertSame('arrow', $markup->markup_payload['markups_core_tool']);
        $this->assertStringContainsString('<svg>', $markup->markup_payload['markups_core_svg']);

        $this->actingAs($user)
            ->patch(route('tenant.projects.markups.update', [$tenant, $markup]), [
                'assigned_to_ids' => [$assignee->id, $secondaryAssignee->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('project_review_markup_assignees', [
            'project_review_markup_id' => $markup->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('project_review_markup_assignees', [
            'project_review_markup_id' => $markup->id,
            'user_id' => $assignee->id,
        ]);

        Notification::assertSentTo($assignee, ProjectReviewMarkupCreatedNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });

        $this->actingAs($assignee)
            ->post(route('tenant.projects.markups.replies.store', [$tenant, $markup]), [
                'body' => 'Ajuste executado conforme o apontamento.',
                'resolve' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_review_markup_replies', [
            'tenant_id' => $tenant->id,
            'project_review_markup_id' => $markup->id,
            'created_by_id' => $assignee->id,
            'body' => 'Ajuste executado conforme o apontamento.',
            'resolves_markup' => false,
        ]);

        $this->actingAs($assignee)
            ->post(route('tenant.projects.markups.replies.store', [$tenant, $markup]), [
                'body' => 'Solução conferida e definida.',
                'resolve' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_review_markup_replies', [
            'project_review_markup_id' => $markup->id,
            'created_by_id' => $assignee->id,
            'body' => 'Solução conferida e definida.',
            'resolves_markup' => true,
        ]);
        $this->assertDatabaseHas('project_review_markups', [
            'id' => $markup->id,
            'status' => 'resolved',
            'closed_by_id' => $assignee->id,
        ]);

        $this->actingAs($user)
            ->get(route('tenant.projects.viewer', [$tenant, $version]).'?workspace=comments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canReplyToMarkups', true)
                ->has('reviewMarkups.0.replies', 2)
                ->where('reviewMarkups.0.can_resolve', true)
            );

        $item = ProjectReviewChecklistItem::firstOrFail();

        $this->actingAs($user)
            ->patch(route('tenant.projects.checklist-items.update', [$tenant, $item]), [
                'checked' => true,
                'notes' => 'Item conferido.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_review_checklist_items', [
            'id' => $item->id,
            'checked' => true,
            'checked_by_id' => $user->id,
            'notes' => 'Item conferido.',
        ]);
    }

    public function test_project_document_number_is_limited_and_saved_with_three_digits(): void
    {
        Storage::fake('public');

        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Bloco A',
            'codigo' => '001',
            'tipo' => 'pai',
        ]);
        $phase = $this->projectPhase('PE');

        $this->actingAs($user)
            ->post(route('tenant.projects.store', $tenant), [
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'disciplina_id' => $disciplina->id,
                'project_phase_id' => $phase->id,
                'title' => 'Projeto sequencial curto',
                'document_type' => 'projeto',
                'document_number' => '7',
                'file' => UploadedFile::fake()->create('planta.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_documents', [
            'tenant_id' => $tenant->id,
            'document_number' => '007',
            'code' => '001-001-GER-ARQ-PE-PRJ-007',
        ]);
        $this->assertDatabaseHas('project_document_versions', [
            'stored_name' => '001-001-GER-ARQ-PE-PRJ-007-R00.pdf',
        ]);

        $this->actingAs($user)
            ->from(route('tenant.projects.index', $tenant))
            ->post(route('tenant.projects.store', $tenant), [
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'disciplina_id' => $disciplina->id,
                'project_phase_id' => $phase->id,
                'title' => 'Projeto sequencial invalido',
                'document_type' => 'projeto',
                'document_number' => '1000',
                'file' => UploadedFile::fake()->create('planta-1000.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect(route('tenant.projects.index', $tenant))
            ->assertSessionHasErrors('document_number');
    }

    public function test_invalid_project_file_extension_is_rejected(): void
    {
        Storage::fake('public');

        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Bloco A',
            'codigo' => '001',
            'tipo' => 'pai',
        ]);
        $phase = $this->projectPhase('PE');

        $this->actingAs($user)
            ->from(route('tenant.projects.index', $tenant))
            ->post(route('tenant.projects.store', $tenant), [
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'disciplina_id' => $disciplina->id,
                'project_phase_id' => $phase->id,
                'title' => 'Arquivo invalido',
                'document_type' => 'projeto',
                'document_number' => '001',
                'revision' => 'R00',
                'file' => UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream'),
            ])
            ->assertRedirect(route('tenant.projects.index', $tenant))
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('project_documents', 0);
    }

    public function test_master_list_accepts_multiple_filters(): void
    {
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $arquitetura = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $urbanismo = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Urbanismo',
            'sigla' => 'URB',
            'cor' => '#16a34a',
        ]);
        $estrutura = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Estrutura',
            'sigla' => 'EST',
            'cor' => '#d97706',
        ]);
        $obra100 = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Obra 100',
            'codigo' => '100',
            'tipo' => 'pai',
        ]);
        $obra101 = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Obra 101',
            'codigo' => '101',
            'tipo' => 'pai',
        ]);
        $obra102 = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Obra 102',
            'codigo' => '102',
            'tipo' => 'pai',
        ]);
        $phasePe = $this->projectPhase('PE');
        $phasePb = $this->projectPhase('PB');

        ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra100->id,
            'disciplina_id' => $arquitetura->id,
            'project_phase_id' => $phasePe->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto 100 ARQ',
            'document_type' => 'projeto',
            'status' => 'ativo',
        ]);
        ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra101->id,
            'disciplina_id' => $urbanismo->id,
            'project_phase_id' => $phasePb->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto 101 URB',
            'document_type' => 'prancha',
            'status' => 'em_analise',
        ]);
        ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra102->id,
            'disciplina_id' => $estrutura->id,
            'project_phase_id' => $phasePe->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto 102 EST',
            'document_type' => 'projeto',
            'status' => 'ativo',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.projects.master-list.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filtersApplied', false)
                ->where('documents.total', 0));

        $response = $this->actingAs($user)->get(route('tenant.projects.master-list.index', [
            'tenant' => $tenant,
            'applied' => 1,
            'obra_ids' => [$obra100->id, $obra101->id],
            'disciplina_ids' => [$arquitetura->id, $urbanismo->id],
            'project_phase_ids' => [$phasePe->id, $phasePb->id],
            'document_types' => ['projeto', 'prancha'],
            'statuses' => ['ativo', 'em_analise'],
        ]));

        $response
            ->assertOk()
            ->assertSee('Projeto 100 ARQ')
            ->assertSee('Projeto 101 URB')
            ->assertDontSee('Projeto 102 EST')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Projects/MasterList')
                ->where('documents.total', 2)
                ->where('filters.obra_ids', [(string) $obra100->id, (string) $obra101->id])
                ->where('filters.disciplina_ids', [(string) $arquitetura->id, (string) $urbanismo->id]));
    }

    public function test_master_list_only_includes_contracts_linked_to_a_non_administrator(): void
    {
        [$tenant, $user, $linkedContract] = $this->tenantScenario('engineer');
        $linkedContract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);
        $unlinkedContract = $tenant->contracts()->create([
            'code' => '002',
            'name' => 'Contrato sem vinculo',
            'status' => 'active',
        ]);

        ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $linkedContract->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto vinculado',
            'document_type' => 'projeto',
            'status' => 'ativo',
        ]);
        ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $unlinkedContract->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto sem vinculo',
            'document_type' => 'projeto',
            'status' => 'ativo',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.projects.master-list.index', [
                'tenant' => $tenant,
                'applied' => 1,
            ]))
            ->assertOk()
            ->assertSee('Projeto vinculado')
            ->assertDontSee('Projeto sem vinculo')
            ->assertInertia(fn (Assert $page) => $page
                ->has('contracts', 1)
                ->where('contracts.0.id', $linkedContract->id)
                ->where('documents.total', 1));
    }

    public function test_master_list_includes_all_contracts_for_an_administrator(): void
    {
        [$tenant, $user, $firstContract] = $this->tenantScenario('tenant_admin');
        $secondContract = $tenant->contracts()->create([
            'code' => '002',
            'name' => 'Segundo contrato',
            'status' => 'active',
        ]);

        foreach ([$firstContract, $secondContract] as $index => $contract) {
            ProjectDocument::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'created_by_id' => $user->id,
                'title' => 'Projeto administrativo '.($index + 1),
                'document_type' => 'projeto',
                'status' => 'ativo',
            ]);
        }

        $this->actingAs($user)
            ->get(route('tenant.projects.master-list.index', [
                'tenant' => $tenant,
                'applied' => 1,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('contracts', 2)
                ->where('documents.total', 2));
    }

    public function test_master_list_exports_pdf_and_xlsx_with_company_branding(): void
    {
        Storage::fake('public');
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $logoImage = imagecreatetruecolor(120, 40);
        imagefill($logoImage, 0, 0, imagecolorallocate($logoImage, 37, 99, 235));
        ob_start();
        imagepng($logoImage);
        $logo = ob_get_clean();
        imagedestroy($logoImage);
        $companies = collect([
            'gerenciadora' => ['name' => 'Gestao Engenharia', 'sigla' => 'GES', 'cnpj' => '11111111000191'],
            'cliente' => ['name' => 'Cliente Institucional', 'sigla' => 'CLI', 'cnpj' => '22222222000191'],
            'construtora' => ['name' => 'Construtora Modelo', 'sigla' => 'CON', 'cnpj' => '33333333000191'],
        ])->map(function (array $company, string $type) use ($tenant, $contract, $logo): Empresa {
            $path = "tenant-{$tenant->id}/empresas/logos/{$type}.png";
            Storage::disk('public')->put($path, $logo);

            return $tenant->empresas()->create([
                'contract_id' => $contract->id,
                'tipo_empresa_id' => TipoEmpresa::query()->where('nome', $type)->value('id'),
                'nome' => $company['name'],
                'cnpj' => $company['cnpj'],
                'sigla' => $company['sigla'],
                'logo_path' => $path,
            ]);
        });

        $contract->update([
            'fiscalizadora_empresa_id' => $companies['gerenciadora']->id,
            'cliente_empresa_id' => $companies['cliente']->id,
            'construtora_empresa_id' => $companies['construtora']->id,
        ]);
        ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto institucional',
            'document_type' => 'projeto',
            'status' => 'ativo',
        ]);
        $parameters = [
            'tenant' => $tenant,
            'applied' => 1,
        ];

        $pdfResponse = $this->actingAs($user)
            ->get(route('tenant.projects.master-list.pdf', $parameters))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdfResponse->getContent());

        $excelResponse = $this->actingAs($user)
            ->get(route('tenant.projects.master-list.excel', $parameters))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $temporaryFile = tempnam(sys_get_temp_dir(), 'master-list-');
        file_put_contents($temporaryFile, $excelResponse->streamedContent());

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($temporaryFile) === true);
        $entries = collect(range(0, $archive->numFiles - 1))
            ->map(fn (int $index): string => (string) $archive->getNameIndex($index));
        $this->assertTrue($entries->contains('xl/worksheets/sheet1.xml'), $entries->implode(', '));
        $this->assertTrue($entries->contains(fn (string $entry): bool => str_starts_with($entry, 'xl/media/')), $entries->implode(', '));
        $archive->close();
        unlink($temporaryFile);
    }

    public function test_project_document_delete_is_soft_delete(): void
    {
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $document = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto Arquitetonico',
            'document_type' => 'projeto',
            'status' => 'ativo',
        ]);

        $this->actingAs($user)
            ->delete(route('tenant.projects.destroy', [$tenant, $document]))
            ->assertRedirect();

        $this->assertSoftDeleted('project_documents', [
            'id' => $document->id,
        ]);
    }

    public function test_project_document_can_be_inactivated_with_reason(): void
    {
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $document = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto Arquitetonico',
            'document_type' => 'projeto',
            'status' => 'ativo',
        ]);
        $document->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $user->id,
            'revision' => 'R00',
            'status' => 'ativo',
            'original_name' => 'projeto.pdf',
            'file_path' => 'tenant-1/projects/projeto.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 120,
        ]);

        $this->actingAs($user)
            ->patch(route('tenant.projects.inactivate', [$tenant, $document]), [
                'inactive_reason' => 'Projeto substituido por nova solucao.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_documents', [
            'id' => $document->id,
            'status' => 'inativo',
            'inactive_by_id' => $user->id,
            'inactive_reason' => 'Projeto substituido por nova solucao.',
        ]);
        $this->assertNotNull($document->fresh()->inactive_at);
    }

    public function test_project_status_can_be_changed_with_reason_and_controls_tree_access(): void
    {
        [$tenant, $admin, $contract] = $this->tenantScenario('tenant_admin');
        $viewer = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $viewer->id,
            'role' => 'team_member',
            'status' => 'active',
            'project_permissions' => [ProjectPermissions::VIEW],
        ]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $viewer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'project_permissions' => [ProjectPermissions::VIEW],
        ]);
        $document = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $admin->id,
            'title' => 'Projeto com bloqueio operacional',
            'code' => '001-001-GER-ARQ-PE-PRJ-001',
            'document_number' => '001',
            'document_type' => 'projeto',
            'status' => 'ativo',
        ]);
        $version = $document->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $admin->id,
            'revision' => 'R00',
            'status' => 'ativo',
            'original_name' => 'projeto.pdf',
            'file_path' => 'tenant-1/projects/projeto.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 120,
        ]);

        $this->actingAs($admin)
            ->patch(route('tenant.projects.status.update', [$tenant, $document]), [
                'project_status' => 'inativo',
                'inactive_reason' => 'Erro tecnico identificado antes da correcao.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_documents', [
            'id' => $document->id,
            'status' => 'inativo',
            'inactive_reason' => 'Erro tecnico identificado antes da correcao.',
        ]);
        $this->assertDatabaseHas('project_document_status_changes', [
            'project_document_id' => $document->id,
            'user_id' => $admin->id,
            'from_status' => 'ativo',
            'to_status' => 'inativo',
            'reason' => 'Erro tecnico identificado antes da correcao.',
        ]);

        $this->actingAs($viewer)
            ->get(route('tenant.projects.visualizar.index', $tenant))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Projects/Tree')
                ->has('documents', 0));

        $this->actingAs($viewer)
            ->get(route('tenant.projects.viewer', [$tenant, $version]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('tenant.projects.status.update', [$tenant, $document]), [
                'project_status' => 'ativo',
                'inactive_reason' => 'Correcao validada pela equipe tecnica.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_documents', [
            'id' => $document->id,
            'status' => 'ativo',
            'inactive_at' => null,
            'inactive_by_id' => null,
            'inactive_reason' => null,
        ]);
        $this->assertDatabaseHas('project_document_status_changes', [
            'project_document_id' => $document->id,
            'from_status' => 'inativo',
            'to_status' => 'ativo',
            'reason' => 'Correcao validada pela equipe tecnica.',
        ]);
    }

    public function test_project_status_change_requires_macro_permission(): void
    {
        [$tenant, $user, $contract] = $this->tenantScenario('team_member', [ProjectPermissions::VIEW]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'project_permissions' => [ProjectPermissions::VIEW],
        ]);
        $document = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto sem permissao de status',
            'document_type' => 'projeto',
            'status' => 'ativo',
        ]);

        $this->actingAs($user)
            ->patch(route('tenant.projects.status.update', [$tenant, $document]), [
                'project_status' => 'inativo',
                'inactive_reason' => 'Tentativa sem permissao.',
            ])
            ->assertForbidden();

        $this->assertSame('ativo', $document->fresh()->status);
    }

    public function test_initial_project_batch_has_independent_versions_without_cap(): void
    {
        Storage::fake('public');
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $obra = Obra::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Lote A', 'codigo' => '001', 'tipo' => 'pai']);
        $arquitetura = Disciplina::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Arquitetura', 'sigla' => 'ARQ', 'cor' => '#2563eb']);
        $estrutura = Disciplina::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Estrutura', 'sigla' => 'EST', 'cor' => '#16a34a']);
        $phase = $this->projectPhase('PE');

        $this->actingAs($user)->post(route('tenant.projects.batches.store', $tenant), [
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'project_phase_id' => $phase->id,
            'document_type' => 'projeto',
            'title' => 'Pacote executivo 01',
            'items' => [
                ['disciplina_id' => $arquitetura->id, 'document_number' => '001', 'title' => 'Arquitetura geral', 'file' => UploadedFile::fake()->create('arquitetura.pdf', 100, 'application/pdf')],
                ['disciplina_id' => $estrutura->id, 'document_number' => '001', 'title' => 'Estrutura geral', 'file' => UploadedFile::fake()->create('estrutura.pdf', 100, 'application/pdf')],
            ],
        ])->assertRedirect();

        $batch = ProjectSubmissionBatch::with('versions.document')->firstOrFail();
        $this->assertSame('LOT-001-'.now()->year, $batch->package_number);
        $this->assertNull($batch->cap_number);
        $this->assertNull($batch->cap_sequence);
        $this->assertFalse($batch->has_revisions);
        $this->assertCount(2, $batch->versions);
        $this->assertSame(2, $batch->versions->pluck('project_document_id')->unique()->count());
        $this->assertSame([$batch->id], $batch->versions->pluck('project_submission_batch_id')->unique()->all());
        $this->assertSame(['001'], $batch->versions->pluck('document.document_number')->unique()->values()->all());
        foreach ($batch->versions as $version) {
            $this->assertSame('local', $version->storage_disk);
            Storage::disk('local')->assertExists($version->file_path);
        }

        $this->actingAs($user)
            ->get(route('tenant.projects.index', $tenant))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Projects/Index')
                ->has('documents', 2)
                ->where('documents.0.submission_batches.0.package_number', $batch->package_number)
                ->where('documents.1.submission_batches.0.package_number', $batch->package_number));

        $this->actingAs($user)
            ->get(route('tenant.projects.batches.cap.pdf', [$tenant, $batch]))
            ->assertNotFound();
    }

    public function test_revision_project_batch_creates_one_consolidated_cap(): void
    {
        Storage::fake('public');
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $obra = Obra::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Lote revisado', 'codigo' => '004', 'tipo' => 'pai']);
        $arquitetura = Disciplina::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Arquitetura', 'sigla' => 'ARQ', 'cor' => '#2563eb']);
        $estrutura = Disciplina::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Estrutura', 'sigla' => 'EST', 'cor' => '#16a34a']);
        $phase = $this->projectPhase('PE');

        $this->actingAs($user)->post(route('tenant.projects.batches.store', $tenant), [
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'project_phase_id' => $phase->id,
            'document_type' => 'projeto',
            'title' => 'Primeira entrega',
            'items' => [
                ['disciplina_id' => $arquitetura->id, 'document_number' => '001', 'title' => 'Arquitetura geral', 'file' => UploadedFile::fake()->create('arquitetura-r00.pdf', 100, 'application/pdf')],
                ['disciplina_id' => $estrutura->id, 'document_number' => '001', 'title' => 'Estrutura geral', 'file' => UploadedFile::fake()->create('estrutura-r00.pdf', 100, 'application/pdf')],
            ],
        ])->assertRedirect();

        $initialBatch = ProjectSubmissionBatch::firstOrFail();
        $this->actingAs($user)->patch(route('tenant.projects.batches.review.update', [$tenant, $initialBatch]), ['action' => 'aprovar']);
        $this->actingAs($user)->patch(route('tenant.projects.batches.review.update', [$tenant, $initialBatch->fresh()]), ['action' => 'aprovar']);

        $this->actingAs($user)->post(route('tenant.projects.batches.store', $tenant), [
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'project_phase_id' => $phase->id,
            'document_type' => 'projeto',
            'title' => 'Revisão executiva 01',
            'cap_reason' => 'Compatibilização entre arquitetura e estrutura.',
            'cap_description' => 'Ajustes coordenados nos dois projetos do pacote.',
            'cap_impacts' => ['compatibilidade'],
            'items' => [
                ['disciplina_id' => $arquitetura->id, 'document_number' => '001', 'file' => UploadedFile::fake()->create('arquitetura-r01.pdf', 100, 'application/pdf')],
                ['disciplina_id' => $estrutura->id, 'document_number' => '001', 'file' => UploadedFile::fake()->create('estrutura-r01.pdf', 100, 'application/pdf')],
            ],
        ])->assertRedirect();

        $batch = ProjectSubmissionBatch::with('versions.document')->latest('id')->firstOrFail();
        $this->assertTrue($batch->has_revisions);
        $this->assertStringContainsString('-MUL-PE-CAP-001-R01', $batch->cap_number);
        $this->assertSame(1, ProjectSubmissionBatch::query()->whereNotNull('cap_number')->count());
        $this->assertSame(['R01'], $batch->versions->pluck('revision')->unique()->all());
        $this->assertCount(2, $batch->versions);

        $this->actingAs($user)
            ->get(route('tenant.projects.batches.cap.pdf', [$tenant, $batch]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_project_batch_review_is_atomic_across_all_projects(): void
    {
        Storage::fake('public');
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $obra = Obra::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Lote B', 'codigo' => '002', 'tipo' => 'pai']);
        $disciplina = Disciplina::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Arquitetura', 'sigla' => 'ARQ', 'cor' => '#2563eb']);
        $phase = $this->projectPhase('PB');

        $this->actingAs($user)->post(route('tenant.projects.batches.store', $tenant), [
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'project_phase_id' => $phase->id,
            'document_type' => 'projeto',
            'title' => 'Pacote para aprovação',
            'items' => [
                ['disciplina_id' => $disciplina->id, 'document_number' => '010', 'title' => 'Projeto 10', 'file' => UploadedFile::fake()->create('10.pdf', 100, 'application/pdf')],
                ['disciplina_id' => $disciplina->id, 'document_number' => '011', 'title' => 'Projeto 11', 'file' => UploadedFile::fake()->create('11.pdf', 100, 'application/pdf')],
            ],
        ])->assertRedirect();

        $batch = ProjectSubmissionBatch::firstOrFail();
        $this->actingAs($user)->patch(route('tenant.projects.batches.review.update', [$tenant, $batch]), [
            'action' => 'aprovar',
            'review_notes' => 'Pacote verificado.',
        ])->assertRedirect();

        $this->assertSame('em_aprovacao', $batch->fresh()->status);
        $this->assertSame(['em_aprovacao'], ProjectDocument::pluck('status')->unique()->all());
        $this->assertSame(['em_aprovacao'], ProjectDocumentVersion::pluck('status')->unique()->all());

        $this->actingAs($user)->patch(route('tenant.projects.batches.review.update', [$tenant, $batch->fresh()]), [
            'action' => 'aprovar',
            'review_notes' => 'Pacote aprovado.',
        ])->assertRedirect();

        $this->assertSame('ativo', $batch->fresh()->status);
        $this->assertSame(['ativo'], ProjectDocument::pluck('status')->unique()->all());
        $this->assertSame(['ativo'], ProjectDocumentVersion::pluck('status')->unique()->all());
    }

    public function test_project_batch_rejects_duplicate_eap_inside_package(): void
    {
        Storage::fake('public');
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $obra = Obra::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Lote C', 'codigo' => '003', 'tipo' => 'pai']);
        $disciplina = Disciplina::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Arquitetura', 'sigla' => 'ARQ', 'cor' => '#2563eb']);
        $phase = $this->projectPhase('PE');

        $this->actingAs($user)->from(route('tenant.projects.index', $tenant))->post(route('tenant.projects.batches.store', $tenant), [
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'project_phase_id' => $phase->id,
            'document_type' => 'projeto',
            'title' => 'Pacote inválido',
            'items' => [
                ['disciplina_id' => $disciplina->id, 'document_number' => '001', 'title' => 'Projeto A', 'file' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf')],
                ['disciplina_id' => $disciplina->id, 'document_number' => '001', 'title' => 'Projeto B', 'file' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf')],
            ],
        ])->assertRedirect(route('tenant.projects.index', $tenant))->assertSessionHasErrors('items.1.document_number');

        $this->assertDatabaseCount('project_submission_batches', 0);
        $this->assertDatabaseCount('project_documents', 0);
    }

    public function test_project_batch_requires_shared_sequence_for_single_projects_from_different_disciplines(): void
    {
        Storage::fake('public');
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $obra = Obra::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Lote D', 'codigo' => '004', 'tipo' => 'pai']);
        $arquitetura = Disciplina::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Arquitetura', 'sigla' => 'ARQ', 'cor' => '#2563eb']);
        $estrutura = Disciplina::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Estrutura', 'sigla' => 'EST', 'cor' => '#16a34a']);
        $phase = $this->projectPhase('PE');

        $this->actingAs($user)->from(route('tenant.projects.index', $tenant))->post(route('tenant.projects.batches.store', $tenant), [
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'project_phase_id' => $phase->id,
            'document_type' => 'projeto',
            'title' => 'Pacote com sequenciais inconsistentes',
            'items' => [
                ['disciplina_id' => $arquitetura->id, 'document_number' => '001', 'title' => 'Arquitetura', 'file' => UploadedFile::fake()->create('arquitetura.pdf', 100, 'application/pdf')],
                ['disciplina_id' => $estrutura->id, 'document_number' => '002', 'title' => 'Estrutura', 'file' => UploadedFile::fake()->create('estrutura.pdf', 100, 'application/pdf')],
            ],
        ])->assertRedirect(route('tenant.projects.index', $tenant))->assertSessionHasErrors('items.1.document_number');

        $this->assertDatabaseCount('project_submission_batches', 0);
        $this->assertDatabaseCount('project_documents', 0);
    }

    public function test_project_batch_requires_exactly_three_digits_in_sequence(): void
    {
        Storage::fake('public');
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin');
        $obra = Obra::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Lote E', 'codigo' => '005', 'tipo' => 'pai']);
        $disciplina = Disciplina::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Arquitetura', 'sigla' => 'ARQ', 'cor' => '#2563eb']);
        $phase = $this->projectPhase('PE');

        $this->actingAs($user)->from(route('tenant.projects.index', $tenant))->post(route('tenant.projects.batches.store', $tenant), [
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'project_phase_id' => $phase->id,
            'document_type' => 'projeto',
            'title' => 'Pacote com sequencial incompleto',
            'items' => [
                ['disciplina_id' => $disciplina->id, 'document_number' => '01', 'title' => 'Projeto A', 'file' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf')],
                ['disciplina_id' => $disciplina->id, 'document_number' => '002', 'title' => 'Projeto B', 'file' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf')],
            ],
        ])->assertRedirect(route('tenant.projects.index', $tenant))->assertSessionHasErrors('items.0.document_number');

        $this->assertDatabaseCount('project_submission_batches', 0);
        $this->assertDatabaseCount('project_documents', 0);
    }

    public function test_batch_submission_and_review_have_independent_macro_permissions(): void
    {
        Notification::fake();
        Storage::fake('public');
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin', [
            ProjectPermissions::VIEW,
            ProjectPermissions::UPLOAD,
        ]);
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Obra pacote',
            'codigo' => '009',
            'tipo' => 'pai',
        ]);
        $disciplina = Disciplina::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#2563eb',
        ]);
        $phase = $this->projectPhase('PE');
        $payload = [
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'project_phase_id' => $phase->id,
            'document_type' => 'projeto',
            'title' => 'Pacote protegido por permissao',
            'items' => [
                ['disciplina_id' => $disciplina->id, 'document_number' => '001', 'title' => 'Projeto 1', 'file' => UploadedFile::fake()->create('projeto-1.pdf', 100, 'application/pdf')],
                ['disciplina_id' => $disciplina->id, 'document_number' => '002', 'title' => 'Projeto 2', 'file' => UploadedFile::fake()->create('projeto-2.pdf', 100, 'application/pdf')],
            ],
        ];

        $this->actingAs($user)
            ->post(route('tenant.projects.batches.store', $tenant), $payload)
            ->assertForbidden();

        $tenant->memberships()->where('user_id', $user->id)->update([
            'project_permissions' => [ProjectPermissions::VIEW, ProjectPermissions::UPLOAD_BATCH],
        ]);

        $this->actingAs($user)
            ->post(route('tenant.projects.batches.store', $tenant), $payload)
            ->assertRedirect();

        $batch = ProjectSubmissionBatch::firstOrFail();
        $tenant->memberships()->where('user_id', $user->id)->update([
            'project_permissions' => [ProjectPermissions::VIEW, ProjectPermissions::REVIEW],
        ]);

        $this->actingAs($user)
            ->patch(route('tenant.projects.batches.review.update', [$tenant, $batch]), ['action' => 'aprovar'])
            ->assertForbidden();

        $tenant->memberships()->where('user_id', $user->id)->update([
            'project_permissions' => [ProjectPermissions::VIEW, ProjectPermissions::REVIEW_BATCH],
        ]);

        $this->actingAs($user)
            ->patch(route('tenant.projects.batches.review.update', [$tenant, $batch]), ['action' => 'aprovar'])
            ->assertRedirect();

        $this->assertSame('em_aprovacao', $batch->fresh()->status);
    }

    public function test_project_comment_management_has_its_own_macro_permission(): void
    {
        Notification::fake();
        [$tenant, $user, $contract] = $this->tenantScenario('tenant_admin', [
            ProjectPermissions::VIEW,
            ProjectPermissions::REVIEW,
        ]);
        $document = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto para comentario',
            'document_type' => 'projeto',
            'status' => 'em_analise',
        ]);
        $version = $document->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $user->id,
            'revision' => 'R00',
            'status' => 'em_analise',
            'original_name' => 'comentario.pdf',
            'file_path' => 'tenant-1/projects/comentario.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 120,
        ]);
        $payload = [
            'title' => 'Conferir interferencia',
            'description' => 'Validar o encontro entre as disciplinas.',
            'assigned_to_ids' => [$user->id],
            'priority' => 'alta',
        ];

        $this->actingAs($user)
            ->post(route('tenant.projects.markups.store', [$tenant, $version]), $payload)
            ->assertForbidden();

        $tenant->memberships()->where('user_id', $user->id)->update([
            'project_permissions' => [ProjectPermissions::VIEW, ProjectPermissions::COMMENTS],
        ]);

        $this->actingAs($user)
            ->post(route('tenant.projects.markups.store', [$tenant, $version]), $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('project_review_markups', [
            'project_document_version_id' => $version->id,
            'title' => 'Conferir interferencia',
        ]);
    }

    private function projectPhase(string $code = 'PE'): ProjectPhase
    {
        return ProjectPhase::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @return array{Tenant, User, Contract}
     */
    private function tenantScenario(string $role, ?array $projectPermissions = null): array
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Tenant Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'project_permissions' => $projectPermissions,
        ]);
        $contract = $tenant->contracts()->create([
            'code' => '001',
            'name' => 'Contrato Teste',
            'status' => 'active',
        ]);

        return [$tenant, $user, $contract];
    }
}

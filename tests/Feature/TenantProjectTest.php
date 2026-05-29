<?php

namespace Tests\Feature;

use App\Models\Disciplina;
use App\Models\Obra;
use App\Models\ProjectDisciplineResponsavel;
use App\Models\ProjectDocument;
use App\Models\ProjectPhase;
use App\Models\ProjectReviewChecklistItem;
use App\Models\ProjectReviewMarkup;
use App\Models\Tenant;
use App\Models\User;
use App\Jobs\ProcessProjectVersionApsJob;
use App\Notifications\ProjectApprovedNotification;
use App\Notifications\ProjectReviewMarkupCreatedNotification;
use App\Notifications\ProjectSubmittedForReviewNotification;
use App\Notifications\ProjectVerifiedForApprovalNotification;
use App\Support\ProjectPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantProjectTest extends TestCase
{
    use RefreshDatabase;

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
            'code' => '001-001-ARQ-PB-PRJ-001',
            'document_number' => '001',
            'document_type' => 'projeto',
            'status' => 'em_analise',
        ]);
        $this->assertSame('R00', $document->latestVersion->revision);
        $this->assertSame('em_analise', $document->latestVersion->status);
        $this->assertSame('planta.pdf', $document->latestVersion->original_name);
        $this->assertSame('001-001-ARQ-PB-PRJ-001-R00.pdf', $document->latestVersion->stored_name);
        $this->assertSame('not_submitted', $document->latestVersion->derivative_status);
        Storage::disk('public')->assertExists($document->latestVersion->file_path);
        $this->assertStringEndsWith('/001-001-ARQ-PB-PRJ-001-R00.pdf', str_replace('\\', '/', $document->latestVersion->file_path));
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
        Queue::assertPushed(ProcessProjectVersionApsJob::class);
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
        $this->assertSame('Projeto Arquitetonico', $document->title);
        $this->assertSame('001-001-ARQ-PB-PRJ-001', $document->code);
        $this->assertSame(['R00', 'R01'], $document->versions->pluck('revision')->all());
        $this->assertSame(['001-001-ARQ-PB-PRJ-001-R00.pdf', '001-001-ARQ-PB-PRJ-001-R01.pdf'], $document->versions->pluck('stored_name')->all());
        $this->assertSame('Alteracao de layout e compatibilizacao com estrutura.', $document->versions->last()->revision_change_summary);
        $this->assertSame('CAP-001-'.now()->year, $document->versions->last()->cap_number);
        $this->assertSame('Compatibilizacao com estrutura.', $document->versions->last()->cap_reason);
        $this->assertSame(['custo', 'prazo'], $document->versions->last()->cap_impacts);
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
        $document->versions()->create([
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
            'derivative_status' => 'not_submitted',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.projects.revisions.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Projects\/Revisions', false)
            ->assertSee('CAP-001-'.now()->year);
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
        $approved->versions()->create([
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
            ->assertDontSee('Projeto aprovado inativo');
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
            Notification::assertSentTo($user, ProjectApprovedNotification::class, function ($notification, array $channels): bool {
                return in_array('database', $channels, true) && in_array('mail', $channels, true);
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

        [$tenant, $user, $contract] = $this->tenantScenario('engineer', [ProjectPermissions::REVIEW]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'project_permissions' => [ProjectPermissions::REVIEW],
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
            'label' => 'Verificar se a EAP está correta (contrato-obra-disciplina-fase-tipo-sequencial-revisão)',
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
                'assigned_to_id' => $user->id,
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
        Notification::assertSentTo($user, ProjectReviewMarkupCreatedNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });

        $markup = ProjectReviewMarkup::where('title', 'Ajustar detalhe')->firstOrFail();
        $this->assertSame('aps_viewer', $markup->markup_payload['source']);
        $this->assertSame('viewport', $markup->markup_payload['visual_anchor']['type']);
        $this->assertSame('arrow', $markup->markup_payload['markups_core_tool']);
        $this->assertStringContainsString('<svg>', $markup->markup_payload['markups_core_svg']);

        $this->actingAs($user)
            ->patch(route('tenant.projects.markups.update', [$tenant, $markup]), [
                'assigned_to_id' => $assignee->id,
            ])
            ->assertRedirect();

        Notification::assertSentTo($assignee, ProjectReviewMarkupCreatedNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });

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
            'code' => '001-001-ARQ-PE-PRJ-007',
        ]);
        $this->assertDatabaseHas('project_document_versions', [
            'stored_name' => '001-001-ARQ-PE-PRJ-007-R00.pdf',
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

    private function projectPhase(string $code = 'PE'): ProjectPhase
    {
        return ProjectPhase::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @return array{Tenant, User, \App\Models\Contract}
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

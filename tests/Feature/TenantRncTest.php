<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Disciplina;
use App\Models\Empresa;
use App\Models\Obra;
use App\Models\ProjectDocument;
use App\Models\RelatorioNaoConformidade;
use App\Models\RelatorioNaoConformidadeAcaoCorretiva;
use App\Models\RelatorioNaoConformidadeEvidencia;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use App\Models\User;
use App\Notifications\RncCorrectiveActionSubmittedNotification;
use App\Notifications\RncCorrectiveActionReviewedNotification;
use App\Notifications\RncEvidenceSubmittedNotification;
use App\Notifications\RncResponsibleNotification;
use App\Support\RncPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantRncTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_user_can_access_rnc_listing(): void
    {
        [$tenant, $user] = $this->tenantScenario();

        $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Qualidade\/RelatorioNaoConformidade\/Index', false);
    }

    public function test_tenant_user_can_access_rnc_dashboard(): void
    {
        [$tenant, $user] = $this->tenantScenario();

        $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.dashboard', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Qualidade\/RelatorioNaoConformidade\/Dashboard', false);
    }

    public function test_non_owner_needs_rnc_permission_to_view_listing(): void
    {
        [$tenant, $owner, $contract] = $this->tenantScenario();
        $admin = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get(route('tenant.qualidade.rnc.index', $tenant))
            ->assertForbidden();

        RelatorioNaoConformidadeResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $admin->id,
            'created_by_id' => $owner->id,
            'status' => 'active',
            'permissions' => [RncPermissions::VIEW],
        ]);

        $this->actingAs($admin)
            ->get(route('tenant.qualidade.rnc.index', $tenant))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('tenant.qualidade.rnc.dashboard', $tenant))
            ->assertForbidden();
    }

    public function test_tenant_user_can_access_rnc_create_form(): void
    {
        [$tenant, $user] = $this->tenantScenario();

        $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.create', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Qualidade\/RelatorioNaoConformidade\/Create', false);
    }

    public function test_tenant_user_can_edit_rnc(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $ambiental = $this->disciplina($tenant, $contract, 'Ambiental', 'AMB');

        $this->postRnc($tenant, $user, $obra, $contratante, $contratada, '2026-05-16');

        $rnc = RelatorioNaoConformidade::query()->firstOrFail();

        $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.edit', [$tenant, $rnc]))
            ->assertOk()
            ->assertSee('Tenant\/Qualidade\/RelatorioNaoConformidade\/Create', false);

        $this->actingAs($user)
            ->patch(route('tenant.qualidade.rnc.update', [$tenant, $rnc]), [
                'obra_id' => $obra->id,
                'contratante_empresa_id' => $contratante->id,
                'contratada_empresa_id' => $contratada->id,
                'opened_at' => '2026-05-17',
                'latitude' => '-23.5510000',
                'longitude' => '-46.6340000',
                'disciplina_id' => $ambiental->id,
                'gravidade' => 'Grave',
                'descricao_problema' => 'Descricao atualizada da RNC.',
                'observacao' => 'Observacao ajustada.',
                'acoes_corretivas_recomendadas' => 'Acao recomendada atualizada.',
                'prazo_resposta_acao_corretiva' => '2026-05-30',
                'sync_existing_photos' => true,
                'existing_photo_ids' => [],
            ])
            ->assertRedirect(route('tenant.qualidade.rnc.show', [$tenant, $rnc]));

        $rnc->refresh();

        $this->assertSame($contract->id, $rnc->contract_id);
        $this->assertSame('001-2026', $rnc->formatted_number);
        $this->assertSame($ambiental->id, $rnc->disciplina_id);
        $this->assertSame('Ambiental', $rnc->natureza);
        $this->assertSame('Grave', $rnc->gravidade);
        $this->assertSame('Descricao atualizada da RNC.', $rnc->descricao_problema);
        $this->assertSame('2026-05-30', $rnc->prazo_resposta_acao_corretiva->toDateString());
    }

    public function test_tenant_user_can_create_rnc_with_photos(): void
    {
        Storage::fake('public');

        [$tenant, $user, $contract, $obra, $contratante, $contratada, $disciplina] = $this->tenantScenario();

        $this->actingAs($user)
            ->post(route('tenant.qualidade.rnc.store', $tenant), [
                'obra_id' => $obra->id,
                'contratante_empresa_id' => $contratante->id,
                'contratada_empresa_id' => $contratada->id,
                'opened_at' => '2026-05-16',
                'latitude' => '-23.5505200',
                'longitude' => '-46.6333080',
                'disciplina_id' => $disciplina->id,
                'gravidade' => 'Grave',
                'descricao_problema' => 'Concreto com acabamento fora do padrão.',
                'observacao' => 'Registro aberto durante inspeção de campo.',
                'acoes_corretivas_recomendadas' => 'Executar correção superficial e enviar evidência.',
                'prazo_resposta_acao_corretiva' => '2026-05-25',
                'photos' => [$this->fakePhotoUpload()],
                'photo_comments' => ['Vista geral da não conformidade.'],
                'photo_positions' => [1],
            ])
            ->assertRedirect(route('tenant.qualidade.rnc.index', $tenant));

        $this->assertDatabaseHas('relatorio_nao_conformidades', [
            'tenant_id' => $tenant->id,
            'sequence_number' => 1,
            'sequence_year' => 2026,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'contratante_empresa_id' => $contratante->id,
            'contratada_empresa_id' => $contratada->id,
            'created_by_id' => $user->id,
            'disciplina_id' => $disciplina->id,
            'natureza' => 'Qualidade',
            'gravidade' => 'Grave',
            'status' => 'aberta',
        ]);

        $this->assertDatabaseHas('relatorio_nao_conformidade_photos', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'position' => 1,
            'comment' => 'Vista geral da não conformidade.',
            'original_name' => 'rnc.png',
        ]);
    }

    public function test_rnc_can_be_linked_to_project_and_flags_open_rnc_in_project_pages(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada, $disciplina] = $this->tenantScenario();
        $project = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto vinculado a RNC',
            'code' => 'CT001-OBR001-ARQ-PB-PRJ-001',
            'document_type' => 'projeto',
            'status' => 'ativo',
            'approved_at' => now(),
        ]);
        $project->versions()->create([
            'tenant_id' => $tenant->id,
            'uploaded_by_id' => $user->id,
            'revision' => 'R00',
            'status' => 'ativo',
            'approved_by_id' => $user->id,
            'approved_at' => now(),
            'original_name' => 'projeto-vinculado.pdf',
            'file_path' => 'tenant-'.$tenant->id.'/projects/projeto-vinculado.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 120,
        ]);

        $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.create', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Qualidade\/RelatorioNaoConformidade\/Create', false);

        $this->actingAs($user)
            ->post(route('tenant.qualidade.rnc.store', $tenant), [
                'obra_id' => $obra->id,
                'project_document_id' => $project->id,
                'contratante_empresa_id' => $contratante->id,
                'contratada_empresa_id' => $contratada->id,
                'opened_at' => '2026-05-16',
                'disciplina_id' => $disciplina->id,
                'gravidade' => 'Leve',
                'descricao_problema' => 'Problema identificado no projeto.',
                'acoes_corretivas_recomendadas' => 'Corrigir e apresentar evidencia.',
                'prazo_resposta_acao_corretiva' => '2026-05-20',
            ])
            ->assertRedirect(route('tenant.qualidade.rnc.index', $tenant));

        $rnc = RelatorioNaoConformidade::query()->firstOrFail();

        $this->assertSame($project->id, $rnc->project_document_id);

        $this->actingAs($user)
            ->get(route('tenant.projects.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Projects/Index')
                ->where('documents.0.open_rncs_count', 1));

        $this->actingAs($user)
            ->get(route('tenant.projects.visualizar.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Projects/Tree')
                ->where('documents.0.open_rncs_count', 1));

        $rnc->forceFill(['status' => 'finalizada'])->save();

        $this->actingAs($user)
            ->get(route('tenant.projects.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Projects/Index')
                ->where('documents.0.open_rncs_count', 0));
    }

    public function test_rnc_number_is_sequential_by_tenant_and_year(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();

        $this->postRnc($tenant, $user, $obra, $contratante, $contratada, '2026-05-16');
        $this->postRnc($tenant, $user, $obra, $contratante, $contratada, '2026-06-01');
        $this->postRnc($tenant, $user, $obra, $contratante, $contratada, '2027-01-10');

        $rncs = RelatorioNaoConformidade::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(['001-2026', '002-2026', '001-2027'], $rncs->pluck('formatted_number')->all());
        $this->assertSame([1, 2, 1], $rncs->pluck('sequence_number')->all());
        $this->assertSame([2026, 2026, 2027], $rncs->pluck('sequence_year')->all());
    }

    public function test_tenant_user_can_generate_rnc_pdf(): void
    {
        Storage::fake('public');

        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);

        $path = $this->fakePhotoUpload()->store("tenant-{$tenant->id}/rnc/{$rnc->id}", 'public');
        $rnc->photos()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'path' => $path,
            'original_name' => 'rnc.png',
            'mime_type' => 'image/png',
            'size' => 120,
            'position' => 1,
            'comment' => 'Foto do problema.',
        ]);

        $response = $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.pdf', [$tenant, $rnc]));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('inline;', (string) $response->headers->get('content-disposition'));
    }

    public function test_tenant_user_can_open_rnc_preview(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);

        $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.show', [$tenant, $rnc]))
            ->assertOk()
            ->assertSee('Tenant\/Qualidade\/RelatorioNaoConformidade\/Show', false);
    }

    public function test_tenant_admin_can_manage_rnc_responsibles_page(): void
    {
        [$tenant, $user] = $this->tenantScenario();

        $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.responsaveis.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Qualidade\/RelatorioNaoConformidade\/Responsaveis', false);
    }

    public function test_tenant_admin_can_register_rnc_responsible_user_for_contract(): void
    {
        [$tenant, $user, $contract] = $this->tenantScenario();
        $responsible = User::factory()->create();

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $responsible->id,
            'side' => 'contractor',
            'role' => 'contractor_lead',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.qualidade.rnc.responsaveis.store', $tenant), [
                'contract_id' => $contract->id,
                'user_id' => $responsible->id,
            ])
            ->assertRedirect();

        $link = RelatorioNaoConformidadeResponsavel::where([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $responsible->id,
        ])->firstOrFail();

        $this->assertSame('active', $link->status);
        $this->assertSame([], $link->permissions);
    }

    public function test_tenant_admin_can_remove_rnc_responsible_user(): void
    {
        [$tenant, $user, $contract] = $this->tenantScenario();
        $responsible = User::factory()->create();
        $link = RelatorioNaoConformidadeResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $responsible->id,
            'created_by_id' => $user->id,
            'status' => 'active',
            'permissions' => [RncPermissions::VIEW],
        ]);

        $this->actingAs($user)
            ->delete(route('tenant.qualidade.rnc.responsaveis.destroy', [$tenant, $link]))
            ->assertRedirect();

        $this->assertDatabaseHas('relatorio_nao_conformidade_responsaveis', [
            'id' => $link->id,
            'status' => 'inactive',
        ]);
        $this->assertSoftDeleted('relatorio_nao_conformidade_responsaveis', [
            'id' => $link->id,
        ]);
    }

    public function test_tenant_user_can_notify_rnc_responsible_users(): void
    {
        Notification::fake();

        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $responsible = User::factory()->create();
        $notConfigured = User::factory()->create();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $responsible->id,
            'side' => 'contractor',
            'role' => 'contractor_lead',
            'status' => 'active',
        ]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $notConfigured->id,
            'side' => 'contractor',
            'role' => 'contractor_member',
            'status' => 'active',
        ]);
        RelatorioNaoConformidadeResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $responsible->id,
            'created_by_id' => $user->id,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.qualidade.rnc.notify', [$tenant, $rnc]))
            ->assertRedirect();

        Notification::assertSentTo($responsible, RncResponsibleNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });
        Notification::assertNotSentTo($user, RncResponsibleNotification::class);
        Notification::assertNotSentTo($notConfigured, RncResponsibleNotification::class);
        Notification::assertCount(1);
        $this->assertNotNull($rnc->fresh()->notified_at);
    }

    public function test_rnc_responsible_can_access_corrective_action_form_after_notification(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $responsible = User::factory()->create();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);
        $rnc->forceFill(['notified_at' => now()])->save();

        $this->makeRncResponsible($tenant, $contract, $responsible, $user);

        $this->actingAs($responsible)
            ->get(route('tenant.qualidade.rnc.acao-corretiva.create', [$tenant, $rnc]))
            ->assertOk()
            ->assertSee('Tenant\/Qualidade\/RelatorioNaoConformidade\/AcaoCorretiva', false);
    }

    public function test_corrective_action_form_requires_rnc_notification(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $responsible = User::factory()->create();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);

        $this->makeRncResponsible($tenant, $contract, $responsible, $user);

        $this->actingAs($responsible)
            ->get(route('tenant.qualidade.rnc.acao-corretiva.create', [$tenant, $rnc]))
            ->assertForbidden();
    }

    public function test_rnc_responsible_can_submit_corrective_action_and_notify_responsibles(): void
    {
        Storage::fake('public');
        Notification::fake();

        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $responsible = User::factory()->create();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);
        $rnc->forceFill(['notified_at' => now()])->save();

        $this->makeRncResponsible($tenant, $contract, $responsible, $user);

        $this->actingAs($responsible)
            ->post(route('tenant.qualidade.rnc.acao-corretiva.store', [$tenant, $rnc]), [
                'descricao_proposta' => 'Executar retrabalho, registrar evidencias e solicitar nova vistoria.',
                'prazo_execucao_proposto' => '2026-06-10',
                'attachment' => UploadedFile::fake()->create('acao-corretiva.zip', 256, 'application/zip'),
            ])
            ->assertRedirect(route('tenant.qualidade.rnc.index', $tenant));

        $acao = RelatorioNaoConformidadeAcaoCorretiva::query()->firstOrFail();

        $this->assertSame($tenant->id, $acao->tenant_id);
        $this->assertSame($rnc->id, $acao->relatorio_nao_conformidade_id);
        $this->assertSame($responsible->id, $acao->user_id);
        $this->assertSame('acao-corretiva.zip', $acao->attachment_original_name);
        $this->assertSame('2026-06-10', $acao->prazo_execucao_proposto->toDateString());
        $this->assertSame('pending', $acao->status);
        Storage::disk('public')->assertExists($acao->attachment_path);

        Notification::assertSentTo($responsible, RncCorrectiveActionSubmittedNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });
        Notification::assertCount(1);
    }

    public function test_tenant_admin_can_open_corrective_action_review_form(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $responsible = User::factory()->create();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);
        $rnc->forceFill(['notified_at' => now()])->save();

        $this->makeRncResponsible($tenant, $contract, $responsible, $user);
        $this->createCorrectiveAction($tenant, $rnc, $responsible);

        $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.analisar-proposta.create', [$tenant, $rnc]))
            ->assertOk()
            ->assertSee('Tenant\/Qualidade\/RelatorioNaoConformidade\/AnalisarProposta', false);
    }

    public function test_tenant_user_can_download_corrective_action_attachment(): void
    {
        Storage::fake('public');

        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $responsible = User::factory()->create();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);
        $rnc->forceFill(['notified_at' => now()])->save();

        $this->makeRncResponsible($tenant, $contract, $responsible, $user);
        $acao = $this->createCorrectiveAction($tenant, $rnc, $responsible);
        Storage::disk('public')->put($acao->attachment_path, 'zip-content');

        $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.acao-corretiva.download', [$tenant, $rnc, $acao]))
            ->assertOk()
            ->assertDownload('acao-corretiva.zip');
    }

    public function test_rnc_responsible_can_access_evidence_form_after_proposal_approval(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $responsible = User::factory()->create();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);
        $rnc->forceFill(['notified_at' => now()])->save();

        $this->makeRncResponsible($tenant, $contract, $responsible, $user);
        $acao = $this->createCorrectiveAction($tenant, $rnc, $responsible);
        $acao->forceFill([
            'status' => 'approved',
            'review_observation' => 'Proposta aprovada.',
            'reviewed_at' => now(),
            'reviewed_by_id' => $user->id,
        ])->save();

        $this->actingAs($responsible)
            ->get(route('tenant.qualidade.rnc.evidencias.create', [$tenant, $rnc]))
            ->assertOk()
            ->assertSee('Tenant\/Qualidade\/RelatorioNaoConformidade\/Evidenciar', false);
    }

    public function test_rnc_responsible_can_submit_evidence_and_finalize_rnc(): void
    {
        Storage::fake('public');
        Notification::fake();

        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $responsible = User::factory()->create();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);
        $rnc->forceFill(['notified_at' => now()])->save();

        $this->makeRncResponsible($tenant, $contract, $responsible, $user);
        $acao = $this->createCorrectiveAction($tenant, $rnc, $responsible);
        $acao->forceFill([
            'status' => 'approved',
            'review_observation' => 'Proposta aprovada.',
            'reviewed_at' => now(),
            'reviewed_by_id' => $user->id,
        ])->save();

        $this->actingAs($responsible)
            ->post(route('tenant.qualidade.rnc.evidencias.store', [$tenant, $rnc]), [
                'evidence_photos' => [$this->fakePhotoUpload()],
                'evidence_photo_comments' => ['Foto da correcao executada.'],
                'evidence_photo_positions' => [1],
                'attachment' => UploadedFile::fake()->create('evidencias.zip', 256, 'application/zip'),
            ])
            ->assertRedirect(route('tenant.qualidade.rnc.index', $tenant));

        $evidencia = RelatorioNaoConformidadeEvidencia::query()->firstOrFail();

        $this->assertSame($tenant->id, $evidencia->tenant_id);
        $this->assertSame($rnc->id, $evidencia->relatorio_nao_conformidade_id);
        $this->assertSame($acao->id, $evidencia->relatorio_nao_conformidade_acao_corretiva_id);
        $this->assertSame($responsible->id, $evidencia->user_id);
        $this->assertSame('evidencias.zip', $evidencia->attachment_original_name);
        Storage::disk('public')->assertExists($evidencia->attachment_path);

        $this->assertDatabaseHas('relatorio_nao_conformidade_evidencia_photos', [
            'tenant_id' => $tenant->id,
            'relatorio_nao_conformidade_evidencia_id' => $evidencia->id,
            'user_id' => $responsible->id,
            'position' => 1,
            'comment' => 'Foto da correcao executada.',
            'original_name' => 'rnc.png',
        ]);

        $rnc->refresh();

        $this->assertSame('finalizada', $rnc->status);
        $this->assertSame($responsible->id, $rnc->finalized_by_id);
        $this->assertNotNull($rnc->finalized_at);

        Notification::assertSentTo($responsible, RncEvidenceSubmittedNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });
        Notification::assertSentTo($user, RncEvidenceSubmittedNotification::class);
        Notification::assertCount(2);
    }

    public function test_evidence_form_requires_approved_proposal(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $responsible = User::factory()->create();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);
        $rnc->forceFill(['notified_at' => now()])->save();

        $this->makeRncResponsible($tenant, $contract, $responsible, $user);
        $this->createCorrectiveAction($tenant, $rnc, $responsible);

        $this->actingAs($responsible)
            ->get(route('tenant.qualidade.rnc.evidencias.create', [$tenant, $rnc]))
            ->assertForbidden();
    }

    public function test_tenant_admin_can_reject_corrective_action_and_notify_responsibles(): void
    {
        Notification::fake();

        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $responsible = User::factory()->create();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);
        $rnc->forceFill(['notified_at' => now()])->save();

        $this->makeRncResponsible($tenant, $contract, $responsible, $user);
        $acao = $this->createCorrectiveAction($tenant, $rnc, $responsible);

        $this->actingAs($user)
            ->post(route('tenant.qualidade.rnc.analisar-proposta.store', [$tenant, $rnc]), [
                'decision' => 'rejected',
                'review_observation' => 'A proposta nao apresenta evidencias suficientes.',
            ])
            ->assertRedirect(route('tenant.qualidade.rnc.show', [$tenant, $rnc]));

        $acao->refresh();

        $this->assertSame('rejected', $acao->status);
        $this->assertSame('A proposta nao apresenta evidencias suficientes.', $acao->review_observation);
        $this->assertSame($user->id, $acao->reviewed_by_id);
        $this->assertNotNull($acao->reviewed_at);

        Notification::assertSentTo($responsible, RncCorrectiveActionReviewedNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });
        Notification::assertCount(1);
    }

    public function test_tenant_admin_can_approve_corrective_action_and_notify_responsibles(): void
    {
        Notification::fake();

        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $responsible = User::factory()->create();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);
        $rnc->forceFill(['notified_at' => now()])->save();

        $this->makeRncResponsible($tenant, $contract, $responsible, $user);
        $acao = $this->createCorrectiveAction($tenant, $rnc, $responsible);

        $this->actingAs($user)
            ->post(route('tenant.qualidade.rnc.analisar-proposta.store', [$tenant, $rnc]), [
                'decision' => 'approved',
                'review_observation' => 'Proposta aceita. Iniciar processo corretivo.',
            ])
            ->assertRedirect(route('tenant.qualidade.rnc.show', [$tenant, $rnc]));

        $acao->refresh();

        $this->assertSame('approved', $acao->status);
        $this->assertSame('Proposta aceita. Iniciar processo corretivo.', $acao->review_observation);
        $this->assertSame($user->id, $acao->reviewed_by_id);

        Notification::assertSentTo($responsible, RncCorrectiveActionReviewedNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });
        Notification::assertCount(1);
    }

    public function test_responsible_can_send_new_corrective_action_only_after_rejection(): void
    {
        Storage::fake('public');
        Notification::fake();

        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $responsible = User::factory()->create();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);
        $rnc->forceFill(['notified_at' => now()])->save();

        $this->makeRncResponsible($tenant, $contract, $responsible, $user);
        $acao = $this->createCorrectiveAction($tenant, $rnc, $responsible);

        $this->actingAs($responsible)
            ->post(route('tenant.qualidade.rnc.acao-corretiva.store', [$tenant, $rnc]), [
                'descricao_proposta' => 'Nova proposta enquanto existe uma pendente.',
                'prazo_execucao_proposto' => '2026-06-10',
                'attachment' => UploadedFile::fake()->create('nova-proposta.zip', 256, 'application/zip'),
            ])
            ->assertForbidden();

        $acao->forceFill([
            'status' => 'rejected',
            'review_observation' => 'Enviar mais evidencias.',
            'reviewed_at' => now(),
            'reviewed_by_id' => $user->id,
        ])->save();

        $this->actingAs($responsible)
            ->post(route('tenant.qualidade.rnc.acao-corretiva.store', [$tenant, $rnc]), [
                'descricao_proposta' => 'Nova proposta com evidencias completas.',
                'prazo_execucao_proposto' => '2026-06-20',
                'attachment' => UploadedFile::fake()->create('nova-proposta.zip', 256, 'application/zip'),
            ])
            ->assertRedirect(route('tenant.qualidade.rnc.index', $tenant));

        $this->assertSame(2, $rnc->acoesCorretivas()->count());
    }

    public function test_tenant_admin_can_soft_delete_rnc(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);

        $this->actingAs($user)
            ->delete(route('tenant.qualidade.rnc.destroy', [$tenant, $rnc]))
            ->assertRedirect(route('tenant.qualidade.rnc.index', $tenant));

        $this->assertSoftDeleted('relatorio_nao_conformidades', [
            'id' => $rnc->id,
            'tenant_id' => $tenant->id,
            'deleted_by_id' => $user->id,
        ]);
        $this->assertDatabaseHas('relatorio_nao_conformidades', [
            'id' => $rnc->id,
            'status' => 'excluida',
        ]);
        $this->assertSame(0, $tenant->relatorioNaoConformidades()->count());
        $this->assertNotNull(RelatorioNaoConformidade::withTrashed()->find($rnc->id));
    }

    public function test_contract_participant_cannot_soft_delete_rnc(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $participant = User::factory()->create();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $participant->id,
            'side' => 'contractor',
            'role' => 'contractor_member',
            'status' => 'active',
        ]);

        $this->actingAs($participant)
            ->delete(route('tenant.qualidade.rnc.destroy', [$tenant, $rnc]))
            ->assertForbidden();

        $this->assertNotSoftDeleted('relatorio_nao_conformidades', [
            'id' => $rnc->id,
        ]);
    }

    public function test_rnc_companies_must_belong_to_the_same_contract_as_obra(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada, $disciplina] = $this->tenantScenario();
        $otherContract = $tenant->contracts()->create([
            'code' => 'CT-002',
            'name' => 'Outro Contrato',
            'status' => 'active',
        ]);
        $otherCompany = $this->empresa($tenant, $otherContract, 'Empresa de Outro Contrato', '44.444.444/0001-44');

        $this->actingAs($user)
            ->post(route('tenant.qualidade.rnc.store', $tenant), [
                'obra_id' => $obra->id,
                'contratante_empresa_id' => $contratante->id,
                'contratada_empresa_id' => $otherCompany->id,
                'opened_at' => '2026-05-16',
                'disciplina_id' => $disciplina->id,
                'gravidade' => 'Leve',
                'descricao_problema' => 'Problema',
                'acoes_corretivas_recomendadas' => 'Ação',
                'prazo_resposta_acao_corretiva' => '2026-05-20',
            ])
            ->assertSessionHasErrors('contratante_empresa_id');

        $this->assertDatabaseMissing('relatorio_nao_conformidades', [
            'contract_id' => $contract->id,
            'contratada_empresa_id' => $otherCompany->id,
        ]);
    }

    public function test_rnc_project_must_belong_to_the_same_obra(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada, $disciplina] = $this->tenantScenario();
        $otherObra = $tenant->obras()->create([
            'contract_id' => $contract->id,
            'nome' => 'Outra Obra',
            'codigo' => 'OBR-002',
            'tipo' => 'pai',
        ]);
        $project = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $otherObra->id,
            'created_by_id' => $user->id,
            'title' => 'Projeto de outra obra',
            'document_type' => 'projeto',
            'status' => 'ativo',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.qualidade.rnc.store', $tenant), [
                'obra_id' => $obra->id,
                'project_document_id' => $project->id,
                'contratante_empresa_id' => $contratante->id,
                'contratada_empresa_id' => $contratada->id,
                'opened_at' => '2026-05-16',
                'disciplina_id' => $disciplina->id,
                'gravidade' => 'Leve',
                'descricao_problema' => 'Problema',
                'acoes_corretivas_recomendadas' => 'Acao',
                'prazo_resposta_acao_corretiva' => '2026-05-20',
            ])
            ->assertSessionHasErrors('project_document_id');

        $this->assertDatabaseMissing('relatorio_nao_conformidades', [
            'project_document_id' => $project->id,
        ]);
    }

    /**
     * @return array{Tenant, User, Contract, Obra, Empresa, Empresa, Disciplina}
     */
    private function tenantScenario(): array
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'tenant_owner',
            'status' => 'active',
        ]);

        $contract = $tenant->contracts()->create([
            'code' => 'CT-001',
            'name' => 'Contrato Teste',
            'status' => 'active',
        ]);

        $obra = $tenant->obras()->create([
            'contract_id' => $contract->id,
            'nome' => 'Obra Teste',
            'codigo' => 'OBR-001',
            'tipo' => 'pai',
        ]);

        $contratante = $this->empresa($tenant, $contract, 'Contratante Alfa', '11.111.111/0001-11');
        $contratada = $this->empresa($tenant, $contract, 'Contratada Beta', '22.222.222/0001-22');
        $disciplina = $this->disciplina($tenant, $contract, 'Qualidade', 'QUA');

        return [$tenant, $user, $contract, $obra, $contratante, $contratada, $disciplina];
    }

    private function empresa(Tenant $tenant, Contract $contract, string $nome, string $cnpj): Empresa
    {
        return $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => TipoEmpresa::query()->firstOrFail()->id,
            'nome' => $nome,
            'cnpj' => $cnpj,
            'sigla' => str($nome)->before(' ')->upper()->substr(0, 10)->toString(),
        ]);
    }

    private function disciplina(Tenant $tenant, Contract $contract, string $nome, string $sigla): Disciplina
    {
        return $tenant->disciplinas()->create([
            'contract_id' => $contract->id,
            'nome' => $nome,
            'sigla' => $sigla,
            'descricao' => null,
            'cor' => '#2563eb',
        ]);
    }

    private function createRnc(Tenant $tenant, User $user, Contract $contract, Obra $obra, Empresa $contratante, Empresa $contratada): RelatorioNaoConformidade
    {
        $disciplina = $tenant->disciplinas()
            ->where('contract_id', $contract->id)
            ->firstOrFail();

        return $tenant->relatorioNaoConformidades()->create([
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'disciplina_id' => $disciplina->id,
            'contratante_empresa_id' => $contratante->id,
            'contratada_empresa_id' => $contratada->id,
            'created_by_id' => $user->id,
            'sequence_number' => 1,
            'sequence_year' => 2026,
            'opened_at' => '2026-05-16',
            'latitude' => '-23.5505200',
            'longitude' => '-46.6333080',
            'natureza' => $disciplina->nome,
            'gravidade' => 'Grave',
            'descricao_problema' => 'Concreto com acabamento fora do padrao.',
            'observacao' => 'Registro aberto durante inspecao de campo.',
            'acoes_corretivas_recomendadas' => 'Executar correcao superficial e enviar evidencia.',
            'prazo_resposta_acao_corretiva' => '2026-05-25',
            'status' => 'aberta',
        ]);
    }

    private function postRnc(Tenant $tenant, User $user, Obra $obra, Empresa $contratante, Empresa $contratada, string $openedAt): void
    {
        $disciplina = $tenant->disciplinas()
            ->where('contract_id', $obra->contract_id)
            ->firstOrFail();

        $this->actingAs($user)
            ->post(route('tenant.qualidade.rnc.store', $tenant), [
                'obra_id' => $obra->id,
                'contratante_empresa_id' => $contratante->id,
                'contratada_empresa_id' => $contratada->id,
                'opened_at' => $openedAt,
                'disciplina_id' => $disciplina->id,
                'gravidade' => 'Leve',
                'descricao_problema' => 'Problema',
                'acoes_corretivas_recomendadas' => 'Acao',
                'prazo_resposta_acao_corretiva' => $openedAt,
            ])
            ->assertRedirect(route('tenant.qualidade.rnc.index', $tenant));
    }

    private function makeRncResponsible(Tenant $tenant, Contract $contract, User $responsible, User $createdBy): void
    {
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $responsible->id,
            'side' => 'contractor',
            'role' => 'contractor_lead',
            'status' => 'active',
        ]);

        RelatorioNaoConformidadeResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $responsible->id,
            'created_by_id' => $createdBy->id,
            'status' => 'active',
            'permissions' => [
                RncPermissions::VIEW,
                RncPermissions::CORRECTIVE_ACTION,
                RncPermissions::EVIDENCE,
            ],
        ]);
    }

    private function createCorrectiveAction(Tenant $tenant, RelatorioNaoConformidade $rnc, User $responsible): RelatorioNaoConformidadeAcaoCorretiva
    {
        return $rnc->acoesCorretivas()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $responsible->id,
            'descricao_proposta' => 'Executar retrabalho e anexar evidencias.',
            'prazo_execucao_proposto' => '2026-06-10',
            'attachment_path' => 'tenant-'.$tenant->id.'/rnc/'.$rnc->id.'/acoes-corretivas/acao-corretiva.zip',
            'attachment_original_name' => 'acao-corretiva.zip',
            'attachment_mime_type' => 'application/zip',
            'attachment_size' => 1024,
            'submitted_at' => now(),
            'status' => 'pending',
        ]);
    }

    private function fakePhotoUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rnc').'.png';

        file_put_contents(
            $path,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='),
        );

        return new UploadedFile($path, 'rnc.png', 'image/png', null, true);
    }
}

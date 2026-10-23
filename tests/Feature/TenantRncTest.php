<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Disciplina;
use App\Models\Empresa;
use App\Models\InstrucaoCampo;
use App\Models\Obra;
use App\Models\ProjectDocument;
use App\Models\RelatorioNaoConformidade;
use App\Models\RelatorioNaoConformidadeAcaoCorretiva;
use App\Models\RelatorioNaoConformidadeEvidencia;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use App\Models\User;
use App\Notifications\RncCorrectiveActionReviewedNotification;
use App\Notifications\RncCorrectiveActionSubmittedNotification;
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

    public function test_tenant_user_can_access_rnc_tour_preview(): void
    {
        [$tenant, $user] = $this->tenantScenario();

        $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.tour-preview', [
                'tenant' => $tenant,
                'screen' => 'corrective-action',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Qualidade/RelatorioNaoConformidade/TourPreview')
                ->where('screen', 'corrective-action'));
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
        [$tenant, $user, $contract, $obra] = $this->tenantScenario();

        $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.create', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Qualidade/RelatorioNaoConformidade/Create')
                ->has('contracts', 1)
                ->where('contracts.0.id', $contract->id)
                ->where('contracts.0.code', $contract->code)
                ->has('obras', 1)
                ->where('obras.0.id', $obra->id)
                ->where('obras.0.contract_id', $contract->id));
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
                'assunto' => 'Não conformidade no concreto',
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
        $this->assertSame('Não conformidade no concreto', $rnc->assunto);
        $this->assertSame('2026-05-30', $rnc->prazo_resposta_acao_corretiva->toDateString());
    }

    public function test_rnc_subject_is_required_and_searchable(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $this->postRnc($tenant, $user, $obra, $contratante, $contratada, '2026-05-16');
        $rnc = RelatorioNaoConformidade::query()->sole();
        $this->assertSame('Não conformidade no concreto', $rnc->assunto);
        $payload = $rnc->only(['obra_id', 'contratante_empresa_id', 'contratada_empresa_id', 'disciplina_id', 'gravidade',
            'descricao_problema', 'acoes_corretivas_recomendadas']);
        $payload += ['opened_at' => '2026-05-16', 'prazo_resposta_acao_corretiva' => '2026-05-25'];
        foreach (['', ' ', str_repeat('x', 256)] as $subject) {
            $this->patch(route('tenant.qualidade.rnc.update', [$tenant, $rnc]), $payload + ['assunto' => $subject])->assertSessionHasErrors('assunto');
        }
        $this->patch(route('tenant.qualidade.rnc.update', [$tenant, $rnc]), $payload + ['assunto' => 'Junta de dilatação'])->assertSessionHasNoErrors();
        $this->get(route('tenant.qualidade.rnc.index', [$tenant, 'q' => 'dilatação']))->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('rncs.total', 1)->where('rncs.data.0.assunto', 'Junta de dilatação'));
    }

    public function test_rnc_can_reference_a_communicated_ic_and_keep_or_clear_that_reference_on_edit(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $base = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);
        $ic = $this->referenceIc($base);
        $this->referenceIc($base, ['status' => 'draft', 'notified_at' => null]);
        $deleted = $this->referenceIc($base);
        $deleted->delete();
        $this->actingAs($user)->get(route('tenant.qualidade.rnc.create', $tenant))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('instrucoesCampo', 1)->where('instrucoesCampo.0.id', $ic->id)->where('instrucoesCampo.0.assunto', $ic->assunto));
        $payload = $this->referenceRncPayload($base);
        $this->post(route('tenant.qualidade.rnc.store', $tenant), $payload + ['instrucao_campo_id' => $ic->id])->assertSessionHasNoErrors()->assertRedirect();
        $rnc = RelatorioNaoConformidade::latest('id')->firstOrFail();
        $this->assertSame($ic->id, $rnc->instrucao_campo_id);
        $this->assertSame('notified', $ic->fresh()->status);
        $this->get(route('tenant.qualidade.rnc.show', [$tenant, $rnc]))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('rnc.instrucao_campo.id', $ic->id)->where('rnc.instrucao_campo.can_view', true)
            ->where('rnc.instrucao_campo.assunto', $ic->assunto)->has('rnc.instrucao_campo.notified_at')->missing('rnc.instrucao_campo.instrucao'));
        $this->get(route('tenant.qualidade.rnc.edit', [$tenant, $rnc]))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('rnc.instrucao_campo_id', $ic->id)->where('rnc.instrucao_campo.id', $ic->id));
        $this->patch(route('tenant.qualidade.rnc.update', [$tenant, $rnc]), $payload)->assertSessionHasNoErrors();
        $this->assertSame($ic->id, $rnc->fresh()->instrucao_campo_id);
        $this->patch(route('tenant.qualidade.rnc.update', [$tenant, $rnc]), $payload + ['instrucao_campo_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($rnc->fresh()->instrucao_campo_id);
    }

    public function test_rnc_rejects_ic_references_from_another_work_tenant_or_uncommunicated_records(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $rnc = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);
        $draft = $this->referenceIc($rnc, ['status' => 'draft', 'notified_at' => null]);
        $uncommunicated = $this->referenceIc($rnc, ['notified_at' => null]);
        $otherWork = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => 'OTHER', 'nome' => 'Outra obra', 'tipo' => 'pai']);
        $differentWork = $this->referenceIc($rnc, ['obra_id' => $otherWork->id]);
        $deleted = $this->referenceIc($rnc);
        $deleted->delete();
        $otherTenant = Tenant::create(['name' => 'Outra empresa', 'slug' => 'other-ic-reference', 'status' => 'active', 'plan' => 'starter']);
        $otherContract = $otherTenant->contracts()->create(['code' => 'OTHER-CT', 'name' => 'Outro contrato', 'status' => 'active']);
        $otherObra = $otherTenant->obras()->create(['contract_id' => $otherContract->id, 'codigo' => 'OTHER-OB', 'nome' => 'Outra obra', 'tipo' => 'pai']);
        $otherDisciplina = $this->disciplina($otherTenant, $otherContract, 'Qualidade', 'QUA');
        $otherCompany = $this->empresa($otherTenant, $otherContract, 'Outra empresa', '33.333.333/0001-33');
        $foreign = $this->referenceIc($rnc, ['tenant_id' => $otherTenant->id, 'contract_id' => $otherContract->id,
            'obra_id' => $otherObra->id, 'disciplina_id' => $otherDisciplina->id,
            'contratante_empresa_id' => $otherCompany->id, 'contratada_empresa_id' => $otherCompany->id]);
        $payload = $this->referenceRncPayload($rnc);
        foreach ([$draft->id, $uncommunicated->id, $differentWork->id, $deleted->id, $foreign->id, 999999] as $id) {
            $this->actingAs($user)->post(route('tenant.qualidade.rnc.store', $tenant), $payload + ['instrucao_campo_id' => $id])->assertSessionHasErrors('instrucao_campo_id');
            $this->patch(route('tenant.qualidade.rnc.update', [$tenant, $rnc]), $payload + ['instrucao_campo_id' => $id])->assertSessionHasErrors('instrucao_campo_id');
        }
        $this->assertDatabaseCount('relatorio_nao_conformidades', 1);
        $this->assertNull($rnc->fresh()->instrucao_campo_id);
    }

    public function test_rnc_ic_selector_respects_ic_permissions_and_keeps_existing_reference_without_granting_access(): void
    {
        [$tenant, $owner, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $rnc = $this->createRnc($tenant, $owner, $contract, $obra, $contratante, $contratada);
        $ic = $this->referenceIc($rnc);
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'team_member', 'status' => 'active']);
        $this->makeRncResponsible($tenant, $contract, $user, $owner);
        RelatorioNaoConformidadeResponsavel::where('user_id', $user->id)->update(['permissions' => [RncPermissions::CREATE, RncPermissions::EDIT, RncPermissions::VIEW]]);
        $payload = $this->referenceRncPayload($rnc);
        $this->actingAs($user)->get(route('tenant.qualidade.rnc.create', $tenant))->assertOk()->assertInertia(fn (Assert $p) => $p->has('instrucoesCampo', 0));
        $this->post(route('tenant.qualidade.rnc.store', $tenant), $payload + ['instrucao_campo_id' => $ic->id])->assertSessionHasErrors('instrucao_campo_id');
        $rnc->update(['instrucao_campo_id' => $ic->id]);
        $this->get(route('tenant.qualidade.rnc.show', [$tenant, $rnc]))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('rnc.instrucao_campo.id', $ic->id)->where('rnc.instrucao_campo.can_view', false));
        $this->patch(route('tenant.qualidade.rnc.update', [$tenant, $rnc]), $payload + ['instrucao_campo_id' => $ic->id])->assertSessionHasNoErrors();
        $this->assertSame($ic->id, $rnc->fresh()->instrucao_campo_id);
        $this->get(route('tenant.qualidade.ic.show', [$tenant, $ic]))->assertForbidden();
    }

    public function test_rnc_workflow_modals_load_authorized_data_keep_list_filters_and_reject_stale_submissions(): void
    {
        Notification::fake();
        Storage::fake('public');
        [$tenant, $owner, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $rnc = $this->createRnc($tenant, $owner, $contract, $obra, $contratante, $contratada);
        $rnc->update(['notified_at' => now()]);
        $outsider = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $outsider->id, 'role' => 'team_member', 'status' => 'active']);
        $contract->participants()->create(['tenant_id' => $tenant->id, 'user_id' => $outsider->id, 'role' => 'team_member', 'side' => 'contractor', 'status' => 'active']);
        $list = route('tenant.qualidade.rnc.index', [$tenant, 'contract_id' => $contract->id, 'q' => 'concreto']);
        $proposalGet = route('tenant.qualidade.rnc.acao-corretiva.create', [$tenant, $rnc]);
        $proposalPost = route('tenant.qualidade.rnc.acao-corretiva.store', [$tenant, $rnc]);
        $reviewGet = route('tenant.qualidade.rnc.analisar-proposta.create', [$tenant, $rnc]);
        $reviewPost = route('tenant.qualidade.rnc.analisar-proposta.store', [$tenant, $rnc]);
        $evidenceGet = route('tenant.qualidade.rnc.evidencias.create', [$tenant, $rnc]);
        $evidencePost = route('tenant.qualidade.rnc.evidencias.store', [$tenant, $rnc]);
        $zip = fn () => UploadedFile::fake()->create('documentos.zip', 8, 'application/zip');

        $this->actingAs($outsider)->getJson($proposalGet)->assertForbidden();
        $this->actingAs($owner)->getJson($proposalGet)->assertOk()->assertJsonPath('rnc.id', $rnc->id)->assertJsonCount(0, 'acoesCorretivas')
            ->assertHeader('Cache-Control', 'no-store, private');
        $proposal = ['from_modal' => true, 'descricao_proposta' => 'Refazer o acabamento.', 'prazo_execucao_proposto' => '2026-06-10'];
        $this->from($list)->post($proposalPost, $proposal + ['attachment' => $zip()])->assertSessionHasNoErrors()->assertRedirect($list);
        $action = $rnc->acoesCorretivas()->sole();
        $this->from($list)->post($proposalPost, $proposal + ['attachment' => $zip()])->assertSessionHasErrors('rnc');
        $this->assertSame(1, $rnc->acoesCorretivas()->count());

        $this->actingAs($outsider)->getJson($reviewGet)->assertForbidden();
        $this->actingAs($owner)->getJson($reviewGet)->assertOk()->assertJsonPath('acaoCorretiva.id', $action->id)
            ->assertJsonPath('acaoCorretiva.status', 'pending')->assertHeader('Cache-Control', 'no-store, private');
        $review = ['from_modal' => true, 'decision' => 'approved', 'review_observation' => 'Proposta aprovada para execução.'];
        $this->from($list)->post($reviewPost, $review + ['proposal_id' => $action->id + 100])->assertSessionHasErrors('rnc');
        $this->assertSame('pending', $action->fresh()->status);
        $this->from($list)->post($reviewPost, $review + ['proposal_id' => $action->id])->assertSessionHasNoErrors()->assertRedirect($list);
        $this->from($list)->post($reviewPost, $review + ['proposal_id' => $action->id])->assertSessionHasErrors('rnc');
        $this->assertSame('approved', $action->fresh()->status);

        $this->actingAs($outsider)->getJson($evidenceGet)->assertForbidden();
        $this->actingAs($owner)->getJson($evidenceGet)->assertOk()->assertJsonPath('acaoCorretiva.id', $action->id)
            ->assertJsonPath('rnc.id', $rnc->id)->assertHeader('Cache-Control', 'no-store, private');
        $this->from($list)->post($evidencePost, ['from_modal' => true, 'proposal_id' => $action->id + 100])->assertSessionHasErrors('rnc');
        $this->assertNull($rnc->fresh()->finalized_at);
        $evidence = ['from_modal' => true, 'proposal_id' => $action->id, 'resolution_result' => 'yes', 'evidence_photos' => [$this->fakePhotoUpload()],
            'evidence_photo_comments' => ['Acabamento corrigido.'], 'evidence_photo_positions' => [1], 'attachment' => $zip()];
        $this->from($list)->post($evidencePost, $evidence)->assertSessionHasNoErrors()->assertRedirect($list);
        $this->assertSame('em_assinatura', $rnc->fresh()->status);
        $this->assertNull($rnc->fresh()->finalized_at);
        $this->assertSame('Acabamento corrigido.', $rnc->evidencias()->sole()->photos()->sole()->comment);
        $this->from($list)->post($evidencePost, ['from_modal' => true, 'proposal_id' => $action->id])->assertSessionHasErrors('rnc');
        $this->assertSame(1, $rnc->evidencias()->count());
        Notification::assertSentTo($owner, RncEvidenceSubmittedNotification::class);
    }

    public function test_rnc_evidence_accepts_photos_or_zip_or_both_and_rejects_empty_uploads(): void
    {
        Notification::fake();
        Storage::fake('public');
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();

        $base = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);
        foreach (['photos', 'zip', 'both'] as $kind) {
            $rnc = $base->replicate();
            $rnc->forceFill(['sequence_number' => RelatorioNaoConformidade::max('sequence_number') + 1, 'notified_at' => now()])->save();
            $action = $this->createCorrectiveAction($tenant, $rnc, $user);
            $action->update(['status' => 'approved', 'reviewed_at' => now(), 'reviewed_by_id' => $user->id]);
            $endpoint = route('tenant.qualidade.rnc.evidencias.store', [$tenant, $rnc]);
            $payload = ['from_modal' => true, 'proposal_id' => $action->id, 'resolution_result' => 'yes'];

            foreach ([[], ['evidence_photos' => [], 'attachment' => null]] as $empty) {
                $this->actingAs($user)->post($endpoint, $payload + $empty)
                    ->assertSessionHasErrors(['attachment', 'evidence_photos']);
                $this->assertSame(0, $rnc->evidencias()->count());
            }
            $this->post($endpoint, $payload + ['attachment' => UploadedFile::fake()->create('texto.txt', 1, 'text/plain')])
                ->assertSessionHasErrors('attachment');
            $this->post($endpoint, $payload + ['evidence_photos' => [UploadedFile::fake()->create('texto.txt', 1, 'text/plain')]])
                ->assertSessionHasErrors('evidence_photos.0');

            $hasPhotos = $kind !== 'zip';
            $hasZip = $kind !== 'photos';
            $this->post($endpoint, $payload + [
                'evidence_photos' => $hasPhotos ? [$this->fakePhotoUpload()] : [],
                'attachment' => $hasZip ? UploadedFile::fake()->create('evidencias.zip', 8, 'application/zip') : null,
            ])->assertSessionHasNoErrors()->assertRedirect();

            $evidence = $rnc->evidencias()->sole();
            $this->assertSame($hasPhotos ? 1 : 0, $evidence->photos()->count());
            $this->assertSame($hasZip ? 'evidencias.zip' : null, $evidence->attachment_original_name);
            if ($hasZip) {
                Storage::disk('public')->assertExists($evidence->attachment_path);
            } else {
                $this->assertNull($evidence->attachment_path);
                $this->assertSame(0, $evidence->attachment_size);
                $this->get(route('tenant.qualidade.rnc.evidencias.download', [$tenant, $rnc, $evidence]))->assertNotFound();
            }
            $this->assertSame('em_assinatura', $rnc->fresh()->status);
            $this->assertNull($rnc->fresh()->finalized_at);
            $this->get(route('tenant.qualidade.rnc.show', [$tenant, $rnc]))->assertOk();
            $this->get(route('tenant.qualidade.rnc.pdf', [$tenant, $rnc, 'scope' => 'evidence']))->assertOk();
        }
    }

    public function test_rnc_evidence_requires_an_answer_and_reasons_for_partial_or_unresolved_results(): void
    {
        Notification::fake();
        Storage::fake('public');
        [$tenant, $user, $contract, $obra, $contratante, $contratada] = $this->tenantScenario();
        $base = $this->createRnc($tenant, $user, $contract, $obra, $contratante, $contratada);
        foreach (RelatorioNaoConformidadeEvidencia::RESOLUTION_RESULTS as $answer => $label) {
            $rnc = $base->replicate();
            $rnc->forceFill(['sequence_number' => RelatorioNaoConformidade::max('sequence_number') + 1, 'notified_at' => now()])->save();
            $action = $this->createCorrectiveAction($tenant, $rnc, $user);
            $action->update(['status' => 'approved', 'reviewed_at' => now(), 'reviewed_by_id' => $user->id]);
            $endpoint = route('tenant.qualidade.rnc.evidencias.store', [$tenant, $rnc]);
            $list = route('tenant.qualidade.rnc.index', $tenant);
            $payload = ['from_modal' => true, 'proposal_id' => $action->id, 'evidence_photos' => [$this->fakePhotoUpload()],
                'attachment' => UploadedFile::fake()->create('evidencias.zip', 8, 'application/zip')];
            $this->actingAs($user)->getJson(route('tenant.qualidade.rnc.evidencias.create', [$tenant, $rnc]))->assertOk()
                ->assertJsonPath('resolutionOptions', RelatorioNaoConformidadeEvidencia::RESOLUTION_RESULTS);
            $this->from($list)->post($endpoint, $payload)->assertSessionHasErrors('resolution_result');
            $this->from($list)->post($endpoint, $payload + ['resolution_result' => 'invalid'])->assertSessionHasErrors('resolution_result');
            if ($answer !== 'yes') {
                foreach ([null, '', '   ', str_repeat('x', 10001)] as $reason) {
                    $this->from($list)->post($endpoint, $payload + ['resolution_result' => $answer, 'resolution_reason' => $reason])->assertSessionHasErrors('resolution_reason');
                }
            }
            $this->assertNull($rnc->fresh()->finalized_at);
            $this->assertSame(0, $rnc->evidencias()->count());
            $this->assertSame([], Storage::disk('public')->allFiles("tenant-{$tenant->id}/rnc/{$rnc->id}/evidencias"));
            $reason = $answer === 'yes' ? 'Texto anterior que deve ser descartado.' : '  O acabamento ainda apresenta falhas no trecho norte.  ';
            $this->from($list)->post($endpoint, $payload + ['resolution_result' => $answer, 'resolution_reason' => $reason])->assertSessionHasNoErrors()->assertRedirect($list);
            $evidence = $rnc->evidencias()->sole();
            $this->assertSame($answer, $evidence->resolution_result);
            $this->assertSame($label, $evidence->resolution_label);
            $this->assertSame($answer === 'yes' ? null : trim($reason), $evidence->resolution_reason);
            $this->get(route('tenant.qualidade.rnc.show', [$tenant, $rnc]))->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('rnc.evidencias.0.resolution_result', $answer)->where('rnc.evidencias.0.resolution_label', $label)
                ->where('rnc.evidencias.0.resolution_reason', $evidence->resolution_reason));
            $notification = new RncEvidenceSubmittedNotification($evidence, $user);
            $this->assertStringContainsString($label, $notification->toArray($user)['body']);
            $mail = view('emails.rnc-evidence-submitted-text', ['rnc' => $rnc, 'evidencia' => $evidence, 'actor' => $user,
                'notifiable' => $user, 'url' => '/', 'systemUrl' => '/'])->render();
            $this->assertStringContainsString($label, $mail);
            if ($evidence->resolution_reason) $this->assertStringContainsString(trim($reason), $mail);
        }
        $this->assertSame('Não informado', (new RelatorioNaoConformidadeEvidencia)->resolution_label);
    }

    private function referenceIc(RelatorioNaoConformidade $rnc, array $overrides = []): InstrucaoCampo
    {
        return InstrucaoCampo::create(array_replace([
            'tenant_id' => $rnc->tenant_id, 'contract_id' => $rnc->contract_id, 'obra_id' => $rnc->obra_id,
            'disciplina_id' => $rnc->disciplina_id, 'contratante_empresa_id' => $rnc->contratante_empresa_id,
            'contratada_empresa_id' => $rnc->contratada_empresa_id, 'created_by_id' => $rnc->created_by_id,
            'sequence_number' => (InstrucaoCampo::withTrashed()->max('sequence_number') ?? 0) + 1, 'sequence_year' => 2026,
            'opened_at' => '2026-05-14', 'notified_at' => '2026-05-15 12:00:00', 'gravidade' => 'Leve',
            'assunto' => 'Aviso sobre acabamento do concreto', 'instrucao' => 'Corrigir o acabamento.', 'status' => 'notified',
        ], $overrides));
    }

    private function referenceRncPayload(RelatorioNaoConformidade $rnc): array
    {
        return $rnc->only(['obra_id', 'disciplina_id', 'contratante_empresa_id', 'contratada_empresa_id', 'gravidade', 'assunto',
            'descricao_problema', 'observacao', 'acoes_corretivas_recomendadas'])
            + ['opened_at' => '2026-05-16', 'prazo_resposta_acao_corretiva' => '2026-05-25'];
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
                'assunto' => 'Não conformidade no concreto',
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
                'assunto' => 'Não conformidade no concreto',
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

    public function test_rnc_can_be_linked_to_multiple_projects(): void
    {
        [$tenant, $user, $contract, $obra, $contratante, $contratada, $disciplina] = $this->tenantScenario();
        $projects = collect([
            ['code' => 'CT001-OBR001-ARQ-PB-PRJ-001', 'title' => 'Projeto arquitetonico'],
            ['code' => 'CT001-OBR001-EST-PB-PRJ-002', 'title' => 'Projeto estrutural'],
        ])->map(fn (array $attributes): ProjectDocument => ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $user->id,
            'document_type' => 'projeto',
            'status' => 'ativo',
            'approved_at' => now(),
            ...$attributes,
        ]));

        $this->actingAs($user)
            ->post(route('tenant.qualidade.rnc.store', $tenant), [
                'obra_id' => $obra->id,
                'project_document_ids' => $projects->pluck('id')->all(),
                'contratante_empresa_id' => $contratante->id,
                'contratada_empresa_id' => $contratada->id,
                'opened_at' => '2026-05-16',
                'disciplina_id' => $disciplina->id,
                'gravidade' => 'Leve',
                'assunto' => 'Não conformidade no concreto',
                'descricao_problema' => 'Problema comum aos dois projetos.',
                'acoes_corretivas_recomendadas' => 'Compatibilizar os projetos.',
                'prazo_resposta_acao_corretiva' => '2026-05-20',
            ])
            ->assertRedirect(route('tenant.qualidade.rnc.index', $tenant));

        $rnc = RelatorioNaoConformidade::query()->firstOrFail();

        $this->assertSame($projects->first()->id, $rnc->project_document_id);
        $this->assertEqualsCanonicalizing(
            $projects->pluck('id')->all(),
            $rnc->projectDocuments()->pluck('project_documents.id')->all()
        );

        foreach ($projects as $project) {
            $this->assertSame(1, $project->openRncs()->count());
            $this->assertDatabaseHas('rnc_project_documents', [
                'relatorio_nao_conformidade_id' => $rnc->id,
                'project_document_id' => $project->id,
            ]);
        }

        $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.show', [$tenant, $rnc]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Qualidade/RelatorioNaoConformidade/Show')
                ->has('rnc.project_documents', 2));
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
        $rnc->update([
            'assunto' => 'Não conformidade no concreto',
            'descricao_problema' => "Primeiro paragrafo.\n\nSegundo paragrafo.",
            'observacao' => "Item 1\nItem 2\n\nItem 3",
            'acoes_corretivas_recomendadas' => "Acao inicial.\n\nAcao complementar.",
        ]);

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
        $this->assertStringContainsString('RNC-001-2026-evidence.pdf', (string) $response->headers->get('content-disposition'));
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
                'responsibility_type' => RncPermissions::RESPONSIBILITY_CONTRACTOR,
            ])
            ->assertRedirect();

        $link = RelatorioNaoConformidadeResponsavel::where([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $responsible->id,
        ])->firstOrFail();

        $this->assertSame('active', $link->status);
        $this->assertSame(RncPermissions::RESPONSIBILITY_CONTRACTOR, $link->responsibility_type);
        $this->assertSame([
            RncPermissions::CORRECTIVE_ACTION,
            RncPermissions::VIEW,
        ], $link->permissions);
    }

    public function test_rnc_manager_keeps_administrative_permission_when_updating_own_profile(): void
    {
        [$tenant, $owner, $contract] = $this->tenantScenario();
        $manager = User::factory()->create();
        $newResponsible = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $manager->id,
            'role' => 'engenheiro_qualidade',
            'status' => 'active',
        ]);

        foreach ([$manager, $newResponsible] as $participant) {
            $contract->participants()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $participant->id,
                'side' => 'manager',
                'role' => 'member',
                'status' => 'active',
            ]);
        }

        RelatorioNaoConformidadeResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $manager->id,
            'created_by_id' => $owner->id,
            'status' => 'active',
            'responsibility_type' => RncPermissions::RESPONSIBILITY_CONTRACTOR,
            'permissions' => [
                RncPermissions::CORRECTIVE_ACTION,
                RncPermissions::VIEW,
                RncPermissions::RESPONSIBLES,
            ],
        ]);

        $this->actingAs($manager)
            ->post(route('tenant.qualidade.rnc.responsaveis.store', $tenant), [
                'contract_id' => $contract->id,
                'user_id' => $manager->id,
                'responsibility_type' => RncPermissions::RESPONSIBILITY_OPERATIONAL,
            ])
            ->assertRedirect();

        $managerLink = RelatorioNaoConformidadeResponsavel::query()
            ->where('contract_id', $contract->id)
            ->where('user_id', $manager->id)
            ->firstOrFail();

        $this->assertSame([
            RncPermissions::CREATE,
            RncPermissions::NOTIFY,
            RncPermissions::REVIEW,
            RncPermissions::EVIDENCE,
            RncPermissions::VIEW,
            RncPermissions::RESPONSIBLES,
        ], $managerLink->permissions);

        $this->actingAs($manager)
            ->post(route('tenant.qualidade.rnc.responsaveis.store', $tenant), [
                'contract_id' => $contract->id,
                'user_id' => $newResponsible->id,
                'responsibility_type' => RncPermissions::RESPONSIBILITY_MONITORING,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('relatorio_nao_conformidade_responsaveis', [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $newResponsible->id,
            'status' => 'active',
        ]);
    }

    public function test_rnc_responsibility_profiles_have_fixed_permissions(): void
    {
        $this->assertSame([
            RncPermissions::CREATE,
            RncPermissions::NOTIFY,
            RncPermissions::REVIEW,
            RncPermissions::EVIDENCE,
            RncPermissions::VIEW,
        ], RncPermissions::permissionsForResponsibility(RncPermissions::RESPONSIBILITY_OPERATIONAL));

        $this->assertSame([
            RncPermissions::CORRECTIVE_ACTION,
            RncPermissions::VIEW,
        ], RncPermissions::permissionsForResponsibility(RncPermissions::RESPONSIBILITY_CONTRACTOR));

        $this->assertSame([
            RncPermissions::VIEW,
        ], RncPermissions::permissionsForResponsibility(RncPermissions::RESPONSIBILITY_MONITORING));
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
            'responsibility_type' => RncPermissions::RESPONSIBILITY_MONITORING,
            'permissions' => RncPermissions::permissionsForResponsibility(RncPermissions::RESPONSIBILITY_MONITORING),
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
                'resolution_result' => 'yes',
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

        $this->assertSame('em_assinatura', $rnc->status);
        $this->assertNull($rnc->finalized_by_id);
        $this->assertNull($rnc->finalized_at);

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
                'assunto' => 'Não conformidade no concreto',
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
                'assunto' => 'Não conformidade no concreto',
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
            'assunto' => 'Não conformidade no concreto',
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
                'assunto' => 'Não conformidade no concreto',
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

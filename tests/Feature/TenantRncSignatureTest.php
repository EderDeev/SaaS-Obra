<?php

namespace Tests\Feature;

use App\Models\{Contract, Disciplina, Empresa, Obra, RelatorioNaoConformidade, RdoSignatureRequest, RncSignatureSetting, Tenant, TipoEmpresa, User};
use App\Services\{RncPdfRenderer, RdoSignatureService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http, Notification, Storage};
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantRncSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $owner;
    private Contract $contract;
    private RelatorioNaoConformidade $rnc;
    private array $parties = [];
    private array $providerSigners = [];
    private string $artifact = '%PDF-1.4 signed-rnc-provider-bytes';
    private array $document = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake(); Storage::fake('public'); Storage::fake('local');
        config(['signatures.driver' => 'opensign', 'signatures.opensign.base_url' => 'https://opensign.example.test/api/v1.2',
            'signatures.opensign.api_key' => 'fake-api-key', 'signatures.opensign.webhook_secret' => 'fake-webhook-secret']);
        $this->tenant = Tenant::create(['name' => 'Assinaturas RNC', 'slug' => 'rnc-signatures', 'status' => 'active', 'plan' => 'starter']);
        $this->owner = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $this->owner->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $this->contract = $this->tenant->contracts()->create(['code' => 'SIGN-RNC', 'name' => 'Contrato de assinatura', 'status' => 'active']);
        $obra = $this->tenant->obras()->create(['contract_id' => $this->contract->id, 'nome' => 'Obra', 'codigo' => 'SIGN-OB', 'tipo' => 'pai']);
        $disciplina = Disciplina::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'nome' => 'Qualidade', 'sigla' => 'QUA', 'cor' => '#000000']);
        $empresa = Empresa::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'nome' => 'Empresa', 'sigla' => 'EMP', 'cnpj' => '11.111.111/0001-11', 'tipo_empresa_id' => TipoEmpresa::firstOrCreate(['nome' => 'gerenciadora'])->id]);
        $this->rnc = RelatorioNaoConformidade::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'obra_id' => $obra->id,
            'disciplina_id' => $disciplina->id, 'contratante_empresa_id' => $empresa->id, 'contratada_empresa_id' => $empresa->id, 'created_by_id' => $this->owner->id,
            'sequence_number' => 1, 'sequence_year' => 2026, 'opened_at' => '2026-10-20', 'notified_at' => now(), 'gravidade' => 'Grave', 'natureza' => 'Qualidade', 'assunto' => 'RNC para assinatura',
            'descricao_problema' => 'OPENING_MARKER', 'acoes_corretivas_recomendadas' => 'Corrigir', 'prazo_resposta_acao_corretiva' => '2026-10-30', 'status' => 'em_assinatura']);
        $action = $this->rnc->acoesCorretivas()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'descricao_proposta' => 'PROPOSAL_MARKER', 'prazo_execucao_proposto' => '2026-10-25',
            'attachment_path' => 'proposal.zip', 'attachment_original_name' => 'proposal.zip', 'status' => 'approved', 'review_observation' => 'REVIEW_MARKER', 'submitted_at' => now(), 'reviewed_at' => now(), 'reviewed_by_id' => $this->owner->id]);
        $this->rnc->evidencias()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'relatorio_nao_conformidade_acao_corretiva_id' => $action->id,
            'attachment_path' => 'evidence.zip', 'attachment_original_name' => 'evidence.zip', 'submitted_at' => now(), 'resolution_result' => 'partial', 'resolution_reason' => 'EVIDENCE_MARKER']);
        foreach (RncSignatureSetting::ROLES as $role => $label) {
            $user = User::factory()->create(['name' => $label, 'email' => $role.'@example.test']);
            $this->tenant->memberships()->create(['user_id' => $user->id, 'role' => 'team_member', 'status' => 'active']);
            $this->contract->participants()->create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => 'team_member', 'side' => 'contractor', 'status' => 'active']);
            $this->parties[$role] = $user;
        }
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/createdocument')) {
                $this->providerSigners = array_map(fn ($signer) => ['email' => $signer['email'], 'status' => 'sent', 'url' => 'https://opensign.example.test/sign/'.urlencode($signer['email'])], $request->data()['signers']);
                return Http::response(['objectId' => 'rnc-doc-1', 'signers' => $this->providerSigners]);
            }
            if (str_contains($request->url(), '/signinglinks/')) return Http::response(['signers' => $this->providerSigners]);
            if (str_contains($request->url(), '/document/')) return Http::response($this->document);
            if (str_ends_with($request->url(), '/api/app/functions/getDocument')) return Http::response([]);
            if ($request->url() === 'https://files.example.test/rnc-signed.pdf') return Http::response($this->artifact, 200, ['Content-Type' => 'application/pdf']);
            throw new \RuntimeException('Unexpected fake HTTP request: '.$request->url());
        });
        $this->actingAs($this->owner);
    }

    private function configure(array $roles = ['gerenciadora', 'construtora']): void
    {
        $signers = array_fill_keys(array_keys(RncSignatureSetting::ROLES), null);
        foreach ($roles as $role) $signers[$role] = $this->parties[$role]->id;
        $this->post(route('tenant.qualidade.rnc.responsaveis.signatures', $this->tenant), ['contract_id' => $this->contract->id, 'signers' => $signers])->assertSessionHasNoErrors();
    }

    private function send(): RdoSignatureRequest
    {
        $this->post(route('tenant.qualidade.rnc.signatures.store', [$this->tenant, $this->rnc]))->assertSessionHasNoErrors()->assertRedirect();
        return $this->rnc->signatureRequests()->with('signers')->firstOrFail();
    }

    private function complete(array $signers): array
    {
        $this->providerSigners = $signers;
        $this->document = ['objectId' => 'rnc-doc-1', 'status' => 'completed', 'SignedUrl' => 'https://files.example.test/rnc-signed.pdf', 'signers' => $signers];
        return ['document_id' => 'rnc-doc-1', 'status' => 'completed', 'signers' => $signers];
    }

    public function test_signature_parties_are_optional_and_only_contract_candidates_can_be_selected(): void
    {
        foreach ([['gerenciadora', 'construtora'], ['cliente', 'gerenciadora', 'construtora'], ['cliente'], []] as $roles) {
            $this->configure($roles);
            $this->assertEqualsCanonicalizing($roles, array_keys(RncSignatureSetting::sole()->signers));
        }
        $foreign = User::factory()->create();
        $this->post(route('tenant.qualidade.rnc.responsaveis.signatures', $this->tenant), ['contract_id' => $this->contract->id, 'signers' => ['cliente' => $foreign->id]])->assertSessionHasErrors('signers.cliente');
        $this->post(route('tenant.qualidade.rnc.responsaveis.signatures', $this->tenant), ['contract_id' => $this->contract->id,
            'signers' => ['cliente' => $this->parties['cliente']->id, 'gerenciadora' => $this->parties['cliente']->id]])->assertSessionHasErrors('signers.gerenciadora');
        $this->actingAs($this->parties['cliente'])->post(route('tenant.qualidade.rnc.responsaveis.signatures', $this->tenant), ['contract_id' => $this->contract->id, 'signers' => []])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_sending_requires_evidence_selected_parties_and_a_configured_provider(): void
    {
        $url = route('tenant.qualidade.rnc.signatures.store', [$this->tenant, $this->rnc]);
        $this->post($url)->assertSessionHasErrors('signatures');
        $this->configure();
        $this->rnc->evidencias()->delete();
        $this->post($url)->assertSessionHasErrors('signatures');
        config(['signatures.driver' => 'local']);
        $this->post($url)->assertSessionHasErrors('signatures');
        $this->assertDatabaseCount('rdo_signature_requests', 0);
        Http::assertNothingSent();
    }

    public function test_selected_parties_receive_one_immutable_document_and_duplicate_sends_are_blocked(): void
    {
        $this->configure();
        $request = $this->send();
        $this->assertSame('sent', $request->status);
        $this->assertNull($request->rdo_diario_id);
        $this->assertSame($this->rnc->id, $request->relatorio_nao_conformidade_id);
        $this->assertSame(['gerenciadora', 'construtora'], $request->signers->pluck('role')->all());
        $this->assertNull($this->rnc->fresh()->finalized_at);
        Storage::disk('local')->assertExists($request->unsigned_pdf_path);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($request->unsigned_pdf_path)), $request->request_payload['unsigned_sha256']);
        Http::assertSent(fn ($http) => str_ends_with($http->url(), '/createdocument') && $http['send_email'] === true && $http['merge_certificate'] === true
            && count($http['signers']) === 2 && str_contains($http['note'], 'RNC') && $http['signers'][0]['widgets'][0]['page'] > 1);
        $this->post(route('tenant.qualidade.rnc.signatures.store', [$this->tenant, $this->rnc]))->assertSessionHasErrors('signatures');
        $this->assertSame(1, Http::recorded(fn ($http) => str_ends_with($http->url(), '/createdocument'))->count());
        $this->assertDatabaseCount('rdo_signature_requests', 1);
        $this->get(route('tenant.qualidade.rnc.edit', [$this->tenant, $this->rnc]))->assertStatus(422);
        $this->delete(route('tenant.qualidade.rnc.destroy', [$this->tenant, $this->rnc]))->assertStatus(422);
        $this->assertNull($this->rnc->fresh()->deleted_at);
        $this->get(route('tenant.qualidade.rnc.show', [$this->tenant, $this->rnc]))->assertInertia(fn (Assert $p) => $p
            ->where('signatureData.request.status', 'sent')->where('signatureData.has_signed_pdf', false)
            ->missing('signatureData.request.signers.0.signing_url')->missing('signatureData.request.provider_payload'));
        $this->configure(['cliente']);
        $this->assertSame(2, $request->fresh()->signers()->count());
    }

    public function test_webhook_requires_all_signatures_and_a_pdf_before_finalizing_and_signed_download_is_exact(): void
    {
        $this->configure(); $request = $this->send();
        $partial = [['email' => 'gerenciadora@example.test', 'status' => 'completed'], ['email' => 'construtora@example.test', 'status' => 'sent']];
        $payload = $this->complete($partial);
        $this->postJson(route('api.webhooks.opensign'), $payload, ['X-OpenSign-Signature' => 'wrong'])->assertForbidden();
        $this->postJson(route('api.webhooks.opensign'), $payload, ['X-OpenSign-Signature' => 'fake-webhook-secret'])->assertOk();
        $this->assertNull($this->rnc->fresh()->finalized_at);
        $this->assertSame('pending', $request->fresh()->status);
        $this->get(route('tenant.qualidade.rnc.pdf', [$this->tenant, $this->rnc, 'scope' => 'signed']))->assertNotFound();
        $payload = $this->complete([['email' => 'gerenciadora@example.test', 'status' => 'completed'], ['email' => 'construtora@example.test', 'status' => 'completed']]);
        $this->postJson(route('api.webhooks.opensign'), $payload, ['X-OpenSign-Signature' => 'fake-webhook-secret'])->assertOk();
        $this->assertNotNull($this->rnc->fresh()->finalized_at);
        $this->assertSame('finalizada', $this->rnc->fresh()->status);
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertSame($this->artifact, Storage::disk('local')->get($request->fresh()->signed_pdf_path));
        $this->get(route('tenant.qualidade.rnc.pdf', [$this->tenant, $this->rnc, 'scope' => 'signed']))->assertOk()->assertDownload();
        $this->get(route('tenant.qualidade.rnc.show', [$this->tenant, $this->rnc]))->assertInertia(fn (Assert $p) => $p->where('signatureData.has_signed_pdf', true));
        $completed = $this->rnc->fresh()->finalized_at->toISOString();
        $this->postJson(route('api.webhooks.opensign'), ['document_id' => 'rnc-doc-1', 'status' => 'sent'], ['X-OpenSign-Signature' => 'fake-webhook-secret'])->assertOk();
        $this->assertSame($completed, $this->rnc->fresh()->finalized_at->toISOString());
        $this->assertSame('completed', $request->fresh()->status);
        $this->actingAs($this->parties['cliente'])->get(route('tenant.qualidade.rnc.signatures.unsigned', [$this->tenant, $this->rnc, $request]))->assertForbidden();
    }

    public function test_completed_provider_status_without_valid_pdf_does_not_finalize_and_refresh_can_recover(): void
    {
        $this->configure(['cliente', 'gerenciadora', 'construtora']); $request = $this->send();
        $payload = $this->complete(array_map(fn ($user) => ['email' => $user->email, 'status' => 'completed'], array_values($this->parties)));
        $this->artifact = '<html>provider error</html>';
        app(RdoSignatureService::class)->applyWebhook($payload);
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertNull($this->rnc->fresh()->finalized_at);
        $this->assertNull($request->fresh()->signed_pdf_path);
        $this->artifact = '%PDF-1.4 recovered signed PDF';
        $this->post(route('tenant.qualidade.rnc.signatures.refresh', [$this->tenant, $this->rnc, $request]))->assertSessionHasNoErrors();
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertNotNull($this->rnc->fresh()->finalized_at);
    }

    public function test_inspection_model_is_sent_for_signature_and_later_settings_changes_preserve_the_original(): void
    {
        // Existing RNC from before this feature: preserve its company image even before sending.
        $oldLogo = UploadedFile::fake()->image('legacy.png', 300, 100);
        Storage::disk('public')->put('legacy.png', file_get_contents($oldLogo->getRealPath()));
        $this->rnc->contratante->update(['logo_path' => 'legacy.png']);
        $this->rnc->forceFill(['pdf_logo_snapshot' => null])->saveQuietly();
        $url = route('tenant.qualidade.rnc.settings.store', $this->tenant);
        $options = \App\Support\RncPdfTemplates::defaults('inspection_form');
        $options['title'] = 'Modelo do contrato para assinatura';
        $this->post($url, ['contract_id' => $this->contract->id, 'layout' => 'inspection_form', 'options' => $options, 'left_logo' => UploadedFile::fake()->image('original.png')])->assertSessionHasNoErrors();
        $this->configure();
        $request = $this->send();
        $original = Storage::disk('local')->get($request->unsigned_pdf_path);
        $this->assertSame('inspection_form', $request->request_payload['pdf_template']['layout']);
        $originalLogo = $request->request_payload['pdf_template']['options']['left_logo_path'];
        $this->assertSame($this->rnc->fresh()->pdf_logo_snapshot['left_logo_path'], $originalLogo);
        Storage::disk('local')->assertExists($originalLogo);
        $page = $request->request_payload['signature_page'];
        $this->assertGreaterThanOrEqual(4, $page);
        $sent = Http::recorded(fn ($http) => str_ends_with($http->url(), '/createdocument'))->first()[0]->data();
        foreach ($sent['signers'] as $signer) {
            $this->assertSame($page, $signer['widgets'][0]['page']);
            $this->assertSame(710, $signer['widgets'][0]['y']);
        }
        $this->post($url, ['contract_id' => $this->contract->id, 'layout' => 'standard', 'options' => \App\Support\RncPdfTemplates::defaults('standard'), 'left_logo' => UploadedFile::fake()->image('replacement.png')])->assertSessionHasNoErrors();
        $this->get(route('tenant.qualidade.rnc.signatures.unsigned', [$this->tenant, $this->rnc, $request]))->assertOk()->assertDownload();
        $this->assertSame($original, Storage::disk('local')->get($request->fresh()->unsigned_pdf_path));
        $this->assertSame('inspection_form', $request->fresh()->request_payload['pdf_template']['layout']);
        $this->assertSame($originalLogo, $request->fresh()->request_payload['pdf_template']['options']['left_logo_path']);
        $this->assertNotSame($originalLogo, \App\Support\RncPdfTemplates::forContract($this->tenant, $this->contract->id)['options']['left_logo_path']);
        Storage::disk('local')->assertExists($originalLogo);
    }

    public function test_pdf_scopes_include_only_the_requested_steps(): void
    {
        $renderer = app(RncPdfRenderer::class);
        foreach (['opening', 'analysis', 'evidence'] as $scope) {
            $pdf = $renderer->render($this->tenant, $this->rnc->fresh(), $scope);
            $text = $pdf->getDomPDF()->getDom()->textContent;
            $this->assertStringContainsString('OPENING_MARKER', $text);
            if ($scope === 'opening') {
                $this->assertStringNotContainsString('PROPOSAL_MARKER', $text);
                $this->assertStringNotContainsString('REVIEW_MARKER', $text);
            } else {
                $this->assertStringContainsString('PROPOSAL_MARKER', $text);
                $this->assertStringContainsString('REVIEW_MARKER', $text);
            }
            if ($scope === 'evidence') $this->assertStringContainsString('EVIDENCE_MARKER', $text);
            else $this->assertStringNotContainsString('EVIDENCE_MARKER', $text);
        }
        $this->get(route('tenant.qualidade.rnc.pdf', [$this->tenant, $this->rnc, 'scope' => 'invalid']))->assertSessionHasErrors('scope');
    }

    public function test_signature_endpoints_do_not_accept_another_rnc_or_tenant(): void
    {
        $this->configure(['cliente']); $request = $this->send();
        $other = $this->rnc->replicate();
        $other->forceFill(['sequence_number' => 2])->save();
        $this->get(route('tenant.qualidade.rnc.signatures.unsigned', [$this->tenant, $other, $request]))->assertNotFound();
        $this->post(route('tenant.qualidade.rnc.signatures.refresh', [$this->tenant, $other, $request]))->assertNotFound();
        $otherTenant = Tenant::create(['name' => 'Outro tenant', 'slug' => 'rnc-signatures-foreign', 'status' => 'active', 'plan' => 'starter']);
        $otherTenant->memberships()->create(['user_id' => $this->owner->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $this->get(route('tenant.qualidade.rnc.signatures.unsigned', [$otherTenant, $this->rnc, $request]))->assertNotFound();
        $this->post(route('tenant.qualidade.rnc.signatures.store', [$otherTenant, $this->rnc]))->assertNotFound();
        $this->actingAs($this->parties['cliente'])->post(route('tenant.qualidade.rnc.signatures.store', [$this->tenant, $this->rnc]))->assertForbidden();
        $this->post(route('tenant.qualidade.rnc.signatures.refresh', [$this->tenant, $this->rnc, $request]))->assertForbidden();
    }

    public function test_revoked_signer_membership_blocks_sending_before_calling_the_provider(): void
    {
        $this->configure();
        $this->tenant->memberships()->where('user_id', $this->parties['construtora']->id)->update(['status' => 'inactive']);
        $this->post(route('tenant.qualidade.rnc.signatures.store', [$this->tenant, $this->rnc]))->assertSessionHasErrors('signatures');
        $this->assertDatabaseCount('rdo_signature_requests', 0);
        Http::assertNothingSent();
    }

    public function test_explicit_signature_request_can_include_an_earlier_closure_without_losing_its_audit(): void
    {
        $this->configure(['gerenciadora']);
        $previous = now()->subDay()->startOfSecond();
        $this->rnc->update(['status' => 'finalizada', 'finalized_at' => $previous, 'finalized_by_id' => $this->owner->id]);
        $request = $this->send();
        $this->assertNull($this->rnc->fresh()->finalized_at);
        $this->assertSame($previous->toISOString(), $request->request_payload['previous_closure']['at']);
        $this->assertSame($this->owner->id, $request->request_payload['previous_closure']['by']);
        $this->assertSame(1, $request->signers->count());
    }

    public function test_webhook_claims_do_not_finalize_without_confirmation_from_the_authenticated_provider_api(): void
    {
        $this->configure(); $request = $this->send();
        $this->document = ['objectId' => 'rnc-doc-1', 'status' => 'sent', 'signers' => $this->providerSigners,
            'file' => 'https://files.example.test/rnc-signed.pdf'];
        $this->postJson(route('api.webhooks.opensign'), ['document_id' => 'rnc-doc-1', 'status' => 'completed',
            'SignedUrl' => 'https://files.example.test/rnc-signed.pdf',
            'signers' => [['email' => 'gerenciadora@example.test', 'status' => 'completed'], ['email' => 'construtora@example.test', 'status' => 'completed']]],
            ['X-OpenSign-Signature' => 'fake-webhook-secret'])->assertOk();
        $this->assertNull($this->rnc->fresh()->finalized_at);
        $this->assertNull($request->fresh()->signed_pdf_path);
        $this->assertNotSame('completed', $request->fresh()->status);
        Http::assertNotSent(fn ($http) => $http->url() === 'https://files.example.test/rnc-signed.pdf');
    }
}

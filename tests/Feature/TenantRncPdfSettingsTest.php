<?php

namespace Tests\Feature;

use App\Models\{Contract, RelatorioNaoConformidade, RelatorioNaoConformidadeResponsavel, RncPdfSetting, Tenant, User};
use App\Services\{RncPdfPreview, RncPdfRenderer};
use App\Support\{RncPdfTemplates, RncPermissions};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Notification, Storage};
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantRncPdfSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $owner;
    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); Notification::fake(); Storage::fake('public');
        $this->tenant = Tenant::create(['name' => 'Modelos RNC', 'slug' => 'rnc-models', 'status' => 'active', 'plan' => 'starter']);
        $this->owner = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $this->owner->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $this->contract = $this->tenant->contracts()->create(['code' => 'CT-MODELO', 'name' => 'Contrato de inspeção', 'status' => 'active']);
        $this->actingAs($this->owner);
    }

    private function payload(?Contract $contract = null, string $layout = 'inspection_form'): array
    {
        return ['contract_id' => ($contract ?? $this->contract)->id, 'layout' => $layout, 'options' => RncPdfTemplates::defaults($layout)];
    }

    public function test_contracts_use_standard_by_default_and_can_choose_independent_models(): void
    {
        $other = $this->tenant->contracts()->create(['code' => 'CT-OUTRO', 'name' => 'Outro contrato', 'status' => 'active']);
        $this->get(route('tenant.qualidade.rnc.settings.index', $this->tenant))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Tenant/Qualidade/RelatorioNaoConformidade/PdfSettings')->has('templates', 2)->has('contracts', 2)->where('settings', []));
        $this->assertSame('standard', RncPdfTemplates::forContract($this->tenant, $other->id)['layout']);
        $payload = $this->payload(); $payload['options']['title'] = 'Registro personalizado';
        $payload['options']['contract_notes'] = 'REFERENCIA_CONTRATUAL';
        $this->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('rnc_pdf_settings', ['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'layout' => 'inspection_form']);
        $this->assertSame('standard', RncPdfTemplates::forContract($this->tenant, $other->id)['layout']);
        $renderer = app(RncPdfRenderer::class);
        $custom = $renderer->render($this->tenant, app(RncPdfPreview::class)->make($this->tenant, $this->contract), 'opening')->getDomPDF()->getDom()->textContent;
        $standard = $renderer->render($this->tenant, app(RncPdfPreview::class)->make($this->tenant, $other), 'opening')->getDomPDF()->getDom()->textContent;
        $this->assertStringContainsString('Registro personalizado', $custom);
        $this->assertStringContainsString('REFERENCIA_CONTRATUAL', $custom);
        $this->assertStringContainsString('Descrição da Ocorrência', $custom);
        $this->assertStringNotContainsString('REFERENCIA_CONTRATUAL', $standard);
        $this->assertStringContainsString('Relatório de Não Conformidade', $standard);
        $this->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), $this->payload(layout: 'standard'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('rnc_pdf_settings', 1);
        $this->assertSame('standard', RncPdfTemplates::forContract($this->tenant, $this->contract->id)['layout']);
    }

    public function test_settings_require_contract_management_permission_and_reject_foreign_contracts(): void
    {
        $member = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $member->id, 'role' => 'engineer', 'status' => 'active']);
        $this->contract->participants()->create(['tenant_id' => $this->tenant->id, 'user_id' => $member->id, 'status' => 'active', 'side' => 'manager', 'role' => 'team_member']);
        $responsible = RelatorioNaoConformidadeResponsavel::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id,
            'user_id' => $member->id, 'status' => 'active', 'responsibility_type' => 'monitoring', 'permissions' => [RncPermissions::VIEW]]);
        $index = route('tenant.qualidade.rnc.settings.index', $this->tenant);
        $store = route('tenant.qualidade.rnc.settings.store', $this->tenant);
        $preview = route('tenant.qualidade.rnc.settings.preview', $this->tenant);
        $this->actingAs($member)->get($index)->assertForbidden();
        $this->post($store, $this->payload())->assertForbidden();
        $this->post($preview, $this->payload())->assertForbidden();
        $responsible->update(['permissions' => [RncPermissions::VIEW, RncPermissions::RESPONSIBLES]]);
        $other = $this->tenant->contracts()->create(['code' => 'RESTRITO', 'name' => 'Restrito', 'status' => 'active']);
        $this->get($index)->assertOk()->assertInertia(fn (Assert $p) => $p->has('contracts', 1)->where('contracts.0.id', $this->contract->id));
        $this->post($store, $this->payload($other))->assertForbidden();
        $this->post($preview, $this->payload($other))->assertForbidden();
        $foreign = Tenant::create(['name' => 'Outro tenant', 'slug' => 'pdf-other', 'status' => 'active', 'plan' => 'starter']);
        $foreignContract = $foreign->contracts()->create(['code' => 'EXTERNO', 'name' => 'Externo', 'status' => 'active']);
        $this->post($store, $this->payload($foreignContract))->assertSessionHasErrors('contract_id');
        $this->get(route('tenant.qualidade.rnc.settings.index', $foreign))->assertForbidden();
        $this->assertDatabaseCount('rnc_pdf_settings', 0);
    }

    public function test_preview_is_a_pdf_without_saving_settings_rncs_or_sending_messages_and_inputs_are_validated(): void
    {
        $response = $this->post(route('tenant.qualidade.rnc.settings.preview', $this->tenant), $this->payload());
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertDatabaseCount('rnc_pdf_settings', 0);
        $this->assertDatabaseCount('relatorio_nao_conformidades', 0);
        $this->assertDatabaseCount('rdo_signature_requests', 0);
        Notification::assertNothingSent(); Http::assertNothingSent();
        foreach ([['layout' => '../../other'], ['options' => ['color' => 'red; background:url(http://example.test)']], ['options' => ['unexpected' => 'value']]] as $change) {
            $payload = array_replace_recursive($this->payload(), $change);
            $this->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), $payload)->assertSessionHasErrors();
        }
    }

    public function test_new_model_preserves_scopes_and_keeps_signature_fields_on_the_last_page(): void
    {
        $this->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), $this->payload())->assertSessionHasNoErrors();
        $rnc = app(RncPdfPreview::class)->make($this->tenant, $this->contract);
        $rnc->descricao_problema = 'ABERTURA_MARCADOR';
        $rnc->acoesCorretivas->first()->descricao_proposta = 'PROPOSTA_MARCADOR';
        $rnc->acoesCorretivas->first()->review_observation = 'ANALISE_MARCADOR';
        $rnc->evidencias->first()->resolution_reason = 'EVIDENCIA_MARCADOR';
        $renderer = app(RncPdfRenderer::class);
        foreach (['opening', 'analysis', 'evidence'] as $scope) {
            $pdf = $renderer->render($this->tenant, $rnc, $scope);
            $text = $pdf->getDomPDF()->getDom()->textContent;
            $this->assertStringContainsString('ABERTURA_MARCADOR', $text);
            foreach (['PROPOSTA_MARCADOR', 'ANALISE_MARCADOR'] as $marker) {
                if ($scope === 'opening') $this->assertStringNotContainsString($marker, $text);
                else $this->assertStringContainsString($marker, $text);
            }
            if ($scope === 'evidence') $this->assertStringContainsString('EVIDENCIA_MARCADOR', $text);
            else $this->assertStringNotContainsString('EVIDENCIA_MARCADOR', $text);
        }
        $signers = [['role' => 'construtora', 'name' => 'Construtora', 'email' => 'signer@example.test']];
        $pdf = $renderer->render($this->tenant, $rnc, 'evidence', $signers);
        $dom = $pdf->getDomPDF()->getDom();
        $xpath = new \DOMXPath($dom);
        $this->assertStringContainsString('IDENTIFICAÇÃO', $dom->textContent);
        $this->assertStringContainsString('OBSERVAÇÕES', $dom->textContent);
        $this->assertSame(2, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " photo-section ")]')->length);
        foreach ($xpath->query('//table[@class="photos"]') as $photoTable) {
            $this->assertLessThanOrEqual(4, $xpath->query('.//div[@class="photo-frame"]', $photoTable)->length);
        }
        $this->assertSame(1, $xpath->query('//*[@class="signature-widget"]')->length);
        $this->assertStringContainsString('42pt', $xpath->query('//*[@class="signature-widget"]')->item(0)->getAttribute('style'));
        $bytes = $pdf->output();
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThanOrEqual(4, $pdf->getDomPDF()->getCanvas()->get_page_count());
        if ($folder = getenv('RNC_PDF_CAPTURE_DIR')) {
            if (! is_dir($folder)) mkdir($folder, 0777, true);
            $sample = app(RncPdfPreview::class)->make($this->tenant, $this->contract);
            $sample->descricao_problema = 'Na inspeção de campo foram identificadas falhas no acabamento do concreto na região da junta. O registro abaixo reúne a descrição da ocorrência e as orientações para correção.';
            $allSigners = collect(\App\Models\RncSignatureSetting::ROLES)->map(fn ($label, $role) => ['role' => $role, 'name' => 'Responsável '.$label, 'email' => $role.'@example.test'])->values()->all();
            foreach (['standard', 'inspection_form'] as $layout) {
                $example = $renderer->render($this->tenant, $sample, 'evidence', $allSigners, [...RncPdfTemplates::configuration($layout), 'preview' => true]);
                file_put_contents($folder.'/'.$layout.'.pdf', $example->output());
            }
            $response = $this->get(route('tenant.qualidade.rnc.settings.index', $this->tenant))->assertOk();
            file_put_contents($folder.'/settings.html', $response->getContent());
            file_put_contents($folder.'/settings.json', json_encode($response->viewData('page')));
        }
    }

    public function test_form_handles_long_text_and_embedded_opening_and_evidence_images(): void
    {
        $rnc = app(RncPdfPreview::class)->make($this->tenant, $this->contract);
        $rnc->descricao_problema = str_repeat("Texto detalhado da ocorrência para verificar a continuidade e a paginação.\n", 100).'FIM_DA_DESCRICAO';
        $image = imagecreatetruecolor(1000, 680);
        imagefill($image, 0, 0, imagecolorallocate($image, 220, 235, 245));
        imagestring($image, 5, 80, 80, 'REGISTRO FOTOGRAFICO DE TESTE', imagecolorallocate($image, 25, 70, 110));
        ob_start(); imagejpeg($image); $jpeg = ob_get_clean(); imagedestroy($image);
        Storage::disk('public')->put('test-photo.jpg', $jpeg);
        foreach ($rnc->photos as $photo) $photo->path = 'test-photo.jpg';
        $rnc->evidencias->first()->setRelation('photos', new \Illuminate\Database\Eloquent\Collection([
            new \App\Models\RelatorioNaoConformidadeEvidenciaPhoto(['position' => 1, 'path' => 'test-photo.jpg', 'mime_type' => 'image/jpeg', 'comment' => 'Correção do trecho']),
        ]));
        $options = RncPdfTemplates::defaults('inspection_form');
        $options['work_type'] = 'Pista Nova'; $options['pavement_type'] = 'Flexível';
        $pdf = app(RncPdfRenderer::class)->render($this->tenant, $rnc, 'evidence', [], ['layout' => 'inspection_form', 'options' => $options]);
        $dom = $pdf->getDomPDF()->getDom();
        $this->assertStringContainsString('FIM_DA_DESCRICAO', $dom->textContent);
        $this->assertSame(7, $dom->getElementsByTagName('img')->length);
        $this->assertStringContainsString('Pista Nova', $dom->textContent);
        $this->assertStringContainsString('Flexível', $dom->textContent);
        $bytes = $pdf->output();
        $this->assertStringStartsWith('%PDF-', $bytes);
        if ($folder = getenv('RNC_PDF_CAPTURE_DIR')) file_put_contents($folder.'/long-with-photos.pdf', $bytes);
    }

    public function test_inspection_photos_keep_the_top_and_bottom_of_portrait_images(): void
    {
        $image = imagecreatetruecolor(200, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 50, 180));
        imagefilledrectangle($image, 0, 0, 199, 40, imagecolorallocate($image, 240, 20, 20));
        imagefilledrectangle($image, 0, 360, 199, 399, imagecolorallocate($image, 20, 200, 20));
        ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
        Storage::disk('public')->put('portrait.png', $png);
        $rnc = app(RncPdfPreview::class)->make($this->tenant, $this->contract);
        $photo = $rnc->photos->first(); $photo->path = 'portrait.png'; $photo->mime_type = 'image/png';
        $rnc->setRelation('photos', new \Illuminate\Database\Eloquent\Collection([$photo]));
        $pdf = app(RncPdfRenderer::class)->render($this->tenant, $rnc, 'opening', [], RncPdfTemplates::configuration('inspection_form'));
        $uri = $pdf->getDomPDF()->getDom()->getElementsByTagName('img')->item(0)->getAttribute('src');
        $embedded = imagecreatefromstring(base64_decode(explode(',', $uri, 2)[1]));
        $this->assertSame(1000, imagesx($embedded)); $this->assertSame(680, imagesy($embedded));
        $top = imagecolorsforindex($embedded, imagecolorat($embedded, 500, 10));
        $bottom = imagecolorsforindex($embedded, imagecolorat($embedded, 500, 670));
        $this->assertGreaterThan(200, $top['red']); $this->assertLessThan(50, $top['green']);
        $this->assertGreaterThan(170, $bottom['green']); $this->assertLessThan(50, $bottom['red']);
        imagedestroy($embedded);
    }
}

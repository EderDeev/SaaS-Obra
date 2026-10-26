<?php

namespace Tests\Feature;

use App\Models\{Contract, RelatorioNaoConformidade, RelatorioNaoConformidadeResponsavel, RncPdfSetting, Tenant, User};
use App\Services\{RncPdfPreview, RncPdfRenderer};
use App\Support\{RncPdfTemplates, RncPermissions};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        Http::preventStrayRequests(); Notification::fake(); Storage::fake('public'); Storage::fake('local');
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

    private function createRnc(): RelatorioNaoConformidade
    {
        $work = $this->tenant->obras()->firstOrCreate(['contract_id' => $this->contract->id, 'codigo' => 'RNC-OB'], ['nome' => 'Obra', 'tipo' => 'pai']);
        $company = $this->tenant->empresas()->firstOrCreate(['contract_id' => $this->contract->id, 'cnpj' => '11.111.111/0001-11'], [
            'nome' => 'Empresa original', 'sigla' => 'EMP', 'tipo_empresa_id' => \App\Models\TipoEmpresa::firstOrCreate(['nome' => 'gerenciadora'])->id,
        ]);
        return RelatorioNaoConformidade::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id,
            'obra_id' => $work->id, 'contratante_empresa_id' => $company->id, 'contratada_empresa_id' => $company->id,
            'created_by_id' => $this->owner->id, 'opened_at' => '2026-10-20', 'natureza' => 'Qualidade', 'gravidade' => 'Grave',
            'assunto' => 'Preservar a identidade', 'descricao_problema' => 'Problema', 'acoes_corretivas_recomendadas' => 'Corrigir',
            'prazo_resposta_acao_corretiva' => '2026-10-30', 'status' => 'aberta']);
    }

    private function headerImages(RelatorioNaoConformidade $rnc, string $layout = 'standard', string $scope = 'opening'): array
    {
        $template = RncPdfTemplates::configuration($layout, RncPdfTemplates::forContract($this->tenant, $this->contract->id)['options']);
        $images = app(RncPdfRenderer::class)->render($this->tenant, $rnc->fresh(), $scope, [], $template)->getDomPDF()->getDom()->getElementsByTagName('img');
        return array_map(fn ($image) => $image->getAttribute('src'), iterator_to_array($images));
    }

    public function test_every_rnc_keeps_its_creation_logos_after_replacement_removal_and_company_changes(): void
    {
        $url = route('tenant.qualidade.rnc.settings.store', $this->tenant);
        $this->post($url, [...$this->payload(), 'left_logo' => UploadedFile::fake()->image('original.png', 300, 100), 'right_logo' => UploadedFile::fake()->image('right.png', 100, 300)])->assertSessionHasNoErrors();
        $old = $this->createRnc();
        $snapshot = $old->pdf_logo_snapshot;
        $images = $this->headerImages($old);
        $this->assertCount(2, $images);
        $this->assertFalse($old->signatureRequests()->exists());
        $this->assertArrayNotHasKey('pdf_logo_snapshot', $old->toArray());
        $this->post($url, [...$this->payload(), 'left_logo' => UploadedFile::fake()->image('new.png', 100, 300), 'remove_right_logo' => true])->assertSessionHasNoErrors();
        $new = $this->createRnc();
        $this->assertNotSame($snapshot['left_logo_path'], $new->pdf_logo_snapshot['left_logo_path']);
        $this->assertSame('', $new->pdf_logo_snapshot['right_logo_path']);
        $this->assertCount(1, $this->headerImages($new));
        $old->contratante->update(['nome' => 'Empresa alterada', 'logo_path' => 'inexistente.png']);
        $old->update(['status' => 'notificada', 'descricao_problema' => 'Problema editado']);
        $other = $this->tenant->contracts()->create(['code' => 'NOVO-CONTRATO', 'name' => 'Outro', 'status' => 'active']);
        $old->update(['contract_id' => $other->id]);
        foreach (array_keys(RncPdfTemplates::VIEWS) as $layout) {
            foreach (['opening', 'analysis', 'evidence'] as $scope) $this->assertSame($images, $this->headerImages($old, $layout, $scope));
        }
        $this->assertSame($snapshot, $old->fresh()->pdf_logo_snapshot);
        foreach (['left', 'right'] as $side) Storage::disk('local')->assertExists($snapshot[$side.'_logo_path']);
        $this->assertDatabaseCount('rdo_signature_requests', 0);
    }

    public function test_an_rnc_created_without_logos_stays_without_logos_after_an_upload(): void
    {
        $old = $this->createRnc();
        $this->assertSame('', $old->pdf_logo_snapshot['left_logo_path']);
        $this->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), [...$this->payload(), 'left_logo' => UploadedFile::fake()->image('first.png')])->assertSessionHasNoErrors();
        $this->assertSame([], $this->headerImages($old));
        $new = $this->createRnc();
        $this->assertCount(1, $this->headerImages($new));
    }

    public function test_legacy_backfill_preserves_company_files_all_statuses_and_deleted_records_without_workflow_changes(): void
    {
        $logo = UploadedFile::fake()->image('company.png', 300, 100);
        Storage::disk('public')->put('company.png', file_get_contents($logo->getRealPath()));
        $rncs = collect(['aberta', 'notificada', 'em_analise', 'em_assinatura', 'finalizada'])->map(function ($status) {
            $rnc = $this->createRnc();
            $rnc->contratante->update(['logo_path' => 'company.png']);
            $rnc->forceFill(['pdf_logo_snapshot' => null, 'status' => $status])->saveQuietly();
            return $rnc;
        });
        $rncs->last()->delete();
        $before = $rncs->mapWithKeys(fn ($rnc) => [$rnc->id => $rnc->fresh()->getRawOriginal()]);
        $this->artisan('rnc:freeze-pdf-logos')->expectsOutput('Logos preservadas em 5 RNC(s).')->assertSuccessful();
        foreach ($rncs as $rnc) {
            $fresh = RelatorioNaoConformidade::withTrashed()->findOrFail($rnc->id);
            $this->assertNotNull($fresh->pdf_logo_snapshot);
            foreach (['status', 'created_at', 'updated_at', 'deleted_at'] as $key) $this->assertSame($before[$rnc->id][$key], $fresh->getRawOriginal($key));
            foreach (['left', 'right'] as $side) Storage::disk('local')->assertExists($fresh->pdf_logo_snapshot[$side.'_logo_path']);
        }
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->artisan('rnc:freeze-pdf-logos')->expectsOutput('Logos preservadas em 0 RNC(s).')->assertSuccessful();
        $images = $this->headerImages($rncs->first());
        Storage::disk('public')->delete('company.png');
        $this->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), [...$this->payload(), 'left_logo' => UploadedFile::fake()->image('replacement.png', 100, 300)])->assertSessionHasNoErrors();
        $this->assertSame($images, $this->headerImages($rncs->first()));
        Notification::assertNothingSent(); Http::assertNothingSent();
    }

    public function test_saving_settings_freezes_legacy_rncs_before_changing_branding(): void
    {
        $old = $this->createRnc();
        $old->forceFill(['pdf_logo_snapshot' => null])->saveQuietly();
        $this->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), [...$this->payload(), 'left_logo' => UploadedFile::fake()->image('new.png')])->assertSessionHasNoErrors();
        $snapshot = $old->fresh()->pdf_logo_snapshot;
        $this->assertSame('', $snapshot['left_logo_path']);
        $this->assertSame('Empresa original', $snapshot['left_logo_label']);
        $this->assertSame([], $this->headerImages($old));
        $this->assertCount(1, $this->headerImages($this->createRnc()));
    }

    public function test_webp_logo_upload_is_embedded_in_the_pdf(): void
    {
        $image = imagecreatetruecolor(240, 80);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 70, 200));
        ob_start(); imagewebp($image); $bytes = ob_get_clean(); imagedestroy($image);
        $file = UploadedFile::fake()->createWithContent('logo.webp', $bytes);
        $this->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), [...$this->payload(), 'left_logo' => $file])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->headerImages($this->createRnc()));
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

    public function test_preview_uses_contract_identity_and_has_no_demonstration_notices(): void
    {
        $client = new \App\Models\Empresa(['tenant_id' => $this->tenant->id, 'nome' => 'Cliente do contrato']);
        $contractor = new \App\Models\Empresa(['tenant_id' => $this->tenant->id, 'nome' => 'Construtora vinculada']);
        $work = new \App\Models\Obra(['tenant_id' => $this->tenant->id, 'nome' => 'Obra do contrato']);
        $this->contract->setRelations(['clienteEmpresa' => $client, 'construtoraEmpresa' => $contractor, 'obra' => $work]);
        $preview = app(RncPdfPreview::class);
        $rnc = $preview->make($this->tenant, $this->contract, $this->owner);
        $this->assertSame($client, $rnc->contratante);
        $this->assertSame($contractor, $rnc->contratada);
        $this->assertSame($work, $rnc->obra);
        $this->assertSame($this->owner, $rnc->creator);
        foreach (array_keys(RncPdfTemplates::VIEWS) as $layout) {
            $pdf = app(RncPdfRenderer::class)->render($this->tenant, $rnc, 'evidence', [], [...RncPdfTemplates::configuration($layout), 'preview' => true]);
            $text = $pdf->getDomPDF()->getDom()->textContent;
            foreach (['Cliente do contrato', 'Construtora vinculada', 'Obra do contrato'] as $name) $this->assertStringContainsString($name, $text);
            foreach (['exemplo', 'EXEMPLO', 'FICTÍCIOS', 'SEM VALOR DE ASSINATURA', 'Este texto demonstra'] as $notice) $this->assertStringNotContainsString($notice, $text);
        }
        $client->tenant_id = $this->tenant->id + 100;
        $work->tenant_id = $this->tenant->id + 100;
        $isolated = $preview->make($this->tenant, $this->contract);
        $this->assertNotSame($client, $isolated->contratante);
        $this->assertNotSame($work, $isolated->obra);
        $this->assertSame('Contratante', $isolated->contratante->nome);
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

    public function test_uploaded_logos_are_private_and_fixed_by_contract_for_both_models(): void
    {
        $payload = [...$this->payload(), 'left_logo' => UploadedFile::fake()->image('cliente.png', 300, 100), 'right_logo' => UploadedFile::fake()->image('supervisao.jpg', 100, 300)];
        $this->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), $payload)->assertSessionHasNoErrors();
        $config = RncPdfTemplates::forContract($this->tenant, $this->contract->id);
        foreach (['left', 'right'] as $side) {
            $path = $config['options'][$side.'_logo_path'];
            $this->assertTrue(RncPdfTemplates::validLogoPath($this->tenant, $this->contract->id, $path));
            Storage::disk('local')->assertExists($path); Storage::disk('public')->assertMissing($path);
        }
        $renderer = app(RncPdfRenderer::class);
        $rnc = app(RncPdfPreview::class)->make($this->tenant, $this->contract);
        $sources = [];
        foreach (array_keys(RncPdfTemplates::VIEWS) as $layout) {
            $pdf = $renderer->render($this->tenant, $rnc, 'opening', [], RncPdfTemplates::configuration($layout, $config['options']));
            $images = $pdf->getDomPDF()->getDom()->getElementsByTagName('img');
            $this->assertSame(2, $images->length);
            $sources[$layout] = [$images->item(0)->getAttribute('src'), $images->item(1)->getAttribute('src')];
        }
        $this->assertSame($sources['standard'], $sources['inspection_form']);
        $rnc->setRelation('contratante', new \App\Models\Empresa(['tenant_id' => $this->tenant->id, 'nome' => 'Outra empresa da RNC', 'logo_path' => 'outra.png']));
        $pdf = $renderer->render($this->tenant, $rnc, 'opening');
        $images = $pdf->getDomPDF()->getDom()->getElementsByTagName('img');
        $this->assertSame($sources['inspection_form'], [$images->item(0)->getAttribute('src'), $images->item(1)->getAttribute('src')]);
        $other = $this->tenant->contracts()->create(['code' => 'SEM-LOGO', 'name' => 'Outro', 'status' => 'active']);
        $pdf = $renderer->render($this->tenant, app(RncPdfPreview::class)->make($this->tenant, $other), 'opening');
        $this->assertSame(0, $pdf->getDomPDF()->getDom()->getElementsByTagName('img')->length);
        $this->get(route('tenant.qualidade.rnc.settings.index', $this->tenant))->assertInertia(fn (Assert $p) => $p
            ->has('logos.'.$this->contract->id.'.left.url')->missing('settings.'.$this->contract->id.'.options.left_logo_path'));
    }

    public function test_logo_preview_does_not_save_files_and_untrusted_paths_and_invalid_images_are_rejected(): void
    {
        $payload = [...$this->payload(), 'left_logo' => UploadedFile::fake()->image('left.png', 300, 100), 'right_logo' => UploadedFile::fake()->image('right.png', 100, 300)];
        $response = $this->post(route('tenant.qualidade.rnc.settings.preview', $this->tenant), $payload)->assertOk();
        $this->assertGreaterThanOrEqual(2, preg_match_all('~/Subtype\s*/Image\b~', $response->getContent()));
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('rnc_pdf_settings', 0); $this->assertDatabaseCount('relatorio_nao_conformidades', 0);
        Notification::assertNothingSent(); Http::assertNothingSent();
        $payload = $this->payload(); $payload['options']['left_logo_path'] = 'tenants/99/secret.png';
        $this->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), $payload)->assertSessionHasErrors('options');
        foreach ([UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'), UploadedFile::fake()->image('large.png')->size(4097), UploadedFile::fake()->image('wide.png', 5000, 10)] as $file) {
            $this->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), [...$this->payload(), 'left_logo' => $file])->assertSessionHasErrors('left_logo');
        }
        $this->assertDatabaseCount('rnc_pdf_settings', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_replacing_or_removing_a_logo_preserves_other_logos_and_immutable_versions(): void
    {
        $url = route('tenant.qualidade.rnc.settings.store', $this->tenant);
        $this->post($url, [...$this->payload(), 'left_logo' => UploadedFile::fake()->image('first.png'), 'right_logo' => UploadedFile::fake()->image('right.png')])->assertSessionHasNoErrors();
        $original = RncPdfTemplates::forContract($this->tenant, $this->contract->id);
        $this->post($url, $this->payload(layout: 'standard'))->assertSessionHasNoErrors();
        $this->assertSame($original['options']['left_logo_path'], RncPdfTemplates::forContract($this->tenant, $this->contract->id)['options']['left_logo_path']);
        $this->post($url, [...$this->payload(), 'left_logo' => UploadedFile::fake()->image('new.png')])->assertSessionHasNoErrors();
        $updated = RncPdfTemplates::forContract($this->tenant, $this->contract->id);
        $this->assertNotSame($original['options']['left_logo_path'], $updated['options']['left_logo_path']);
        $this->assertSame($original['options']['right_logo_path'], $updated['options']['right_logo_path']);
        Storage::disk('local')->assertExists($original['options']['left_logo_path']);
        $this->post($url, [...$this->payload(), 'remove_right_logo' => true])->assertSessionHasNoErrors();
        $this->assertSame('', RncPdfTemplates::forContract($this->tenant, $this->contract->id)['options']['right_logo_path']);
        Storage::disk('local')->assertExists($original['options']['right_logo_path']);
        $this->get(route('tenant.qualidade.rnc.settings.logo', [$this->tenant, $this->contract, 'right']))->assertNotFound();
    }

    public function test_logo_endpoints_require_contract_permission_and_do_not_expose_foreign_paths(): void
    {
        $this->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), [...$this->payload(), 'left_logo' => UploadedFile::fake()->image('private.png')])->assertSessionHasNoErrors();
        $url = route('tenant.qualidade.rnc.settings.logo', [$this->tenant, $this->contract, 'left']);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $member = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $member->id, 'role' => 'engineer', 'status' => 'active']);
        $this->actingAs($member)->get($url)->assertForbidden();
        $this->actingAs($member)->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), [...$this->payload(), 'left_logo' => UploadedFile::fake()->image('denied.png')])->assertForbidden();
        $this->actingAs($this->owner);
        $other = $this->tenant->contracts()->create(['code' => 'ISOLADO', 'name' => 'Outro', 'status' => 'active']);
        $config = RncPdfTemplates::forContract($this->tenant, $this->contract->id);
        RncPdfSetting::create(['tenant_id' => $this->tenant->id, 'contract_id' => $other->id, ...$config]);
        $this->get(route('tenant.qualidade.rnc.settings.logo', [$this->tenant, $other, 'left']))->assertNotFound();
        $pdf = app(RncPdfRenderer::class)->render($this->tenant, app(RncPdfPreview::class)->make($this->tenant, $other), 'opening');
        $this->assertSame(0, $pdf->getDomPDF()->getDom()->getElementsByTagName('img')->length);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }
}

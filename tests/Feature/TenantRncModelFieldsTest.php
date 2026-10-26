<?php

namespace Tests\Feature;

use App\Models\{Disciplina, RelatorioNaoConformidade, RncPdfSetting, Tenant, TipoEmpresa, User};
use App\Services\{RncDocumentSnapshots, RncPdfRenderer};
use App\Support\{RncModelFields, RncPdfTemplates};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Notification, Storage};
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantRncModelFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private $contract;
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake(); Http::preventStrayRequests(); Storage::fake('public'); Storage::fake('local');
        $this->tenant = Tenant::create(['name' => 'Campos RNC', 'slug' => 'campos-rnc', 'status' => 'active', 'plan' => 'starter']);
        $user = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $user->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $this->actingAs($user);
        $this->contract = $this->tenant->contracts()->create(['code' => 'FIELDS', 'name' => 'Inspeção', 'status' => 'active']);
        $work = $this->tenant->obras()->create(['contract_id' => $this->contract->id, 'codigo' => 'OB', 'nome' => 'Obra', 'tipo' => 'pai']);
        $discipline = Disciplina::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'nome' => 'Qualidade', 'sigla' => 'QUA', 'cor' => '#000000']);
        $companies = collect([1, 2])->map(fn ($id) => $this->tenant->empresas()->create(['contract_id' => $this->contract->id,
            'nome' => 'Empresa '.$id, 'sigla' => 'EMP'.$id, 'cnpj' => (string) $id, 'tipo_empresa_id' => TipoEmpresa::firstOrCreate(['nome' => 'gerenciadora'])->id]));
        $this->payload = ['obra_id' => $work->id, 'contratante_empresa_id' => $companies[0]->id, 'contratada_empresa_id' => $companies[1]->id,
            'opened_at' => '2026-10-26', 'disciplina_id' => $discipline->id, 'gravidade' => 'Grave', 'assunto' => 'Falha de execução',
            'descricao_problema' => 'Problema', 'acoes_corretivas_recomendadas' => 'Corrigir', 'prazo_resposta_acao_corretiva' => '2026-11-02'];
    }

    private function settings(string $layout = 'inspection_form', ?array $fields = null): array
    {
        return ['contract_id' => $this->contract->id, 'layout' => $layout, 'options' => RncPdfTemplates::defaults($layout),
            'fields_json' => json_encode($fields ?? RncModelFields::defaults($layout))];
    }

    private function saveSettings(array $settings): void
    {
        $this->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), $settings)->assertSessionHasNoErrors();
    }

    private function createRnc(array $values): RelatorioNaoConformidade
    {
        $this->post(route('tenant.qualidade.rnc.store', $this->tenant), [...$this->payload, 'model_field_values' => $values])->assertSessionHasNoErrors()->assertRedirect();
        return $this->tenant->relatorioNaoConformidades()->latest('id')->firstOrFail();
    }

    public function test_inspection_fields_follow_contract_and_answers_are_visible_editable_and_printed_in_header(): void
    {
        $this->saveSettings($this->settings());
        if ($folder = getenv('RNC_FIELDS_CAPTURE_DIR')) {
            if (!is_dir($folder)) mkdir($folder, 0777, true);
            foreach (['create', 'settings.index'] as $screen) {
                $response = $this->get(route('tenant.qualidade.rnc.'.$screen, $this->tenant))->assertOk();
                file_put_contents($folder.'/'.$screen.'.html', $response->getContent());
                file_put_contents($folder.'/'.$screen.'.json', json_encode($response->viewData('page')));
            }
        }
        $this->get(route('tenant.qualidade.rnc.create', $this->tenant))->assertInertia(fn (Assert $p) => $p
            ->has('documentModels.'.$this->contract->id.'.field_definitions', 2)
            ->where('documentModels.'.$this->contract->id.'.field_definitions.0.key', 'work_type'));
        $rnc = $this->createRnc(['work_type' => 'Pista Nova', 'pavement_type' => 'Rígido']);
        $this->assertSame('inspection_form', $rnc->pdf_template_snapshot['layout']);
        $this->assertSame('Pista Nova', $rnc->model_field_values['work_type']);
        $this->get(route('tenant.qualidade.rnc.show', [$this->tenant, $rnc]))->assertInertia(fn (Assert $p) => $p
            ->where('modelFields.0.value', 'Pista Nova')->where('modelFields.1.value', 'Rígido')->missing('rnc.pdf_template_snapshot'));
        $this->get(route('tenant.qualidade.rnc.edit', [$this->tenant, $rnc]))->assertInertia(fn (Assert $p) => $p
            ->where('documentModel.layout', 'inspection_form')->where('modelFieldValues.work_type', 'Pista Nova')->has('documentModel.field_definitions', 2));
        $this->patch(route('tenant.qualidade.rnc.update', [$this->tenant, $rnc]), [...$this->payload, 'model_field_values' => ['work_type' => 'Pista Existente', 'pavement_type' => 'Flexível']])->assertSessionHasNoErrors();
        foreach (['opening', 'analysis', 'evidence'] as $scope) {
            $pdf = app(RncPdfRenderer::class)->render($this->tenant, $rnc->fresh(), $scope);
            $dom = $pdf->getDomPDF()->getDom();
            $xpath = new \DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//span[contains(@class,"choice")][contains(.,"Pista Existente")]/span[normalize-space(.)="X"]')->length);
            $this->assertSame(1, $xpath->query('//span[contains(@class,"choice")][contains(.,"Flexível")]/span[normalize-space(.)="X"]')->length);
            if ($folder = getenv('RNC_FIELDS_CAPTURE_DIR')) { if (!is_dir($folder)) mkdir($folder, 0777, true); file_put_contents($folder.'/inspection-'.$scope.'.pdf', $pdf->output()); }
        }
    }

    public function test_old_rnc_keeps_model_schema_labels_and_options_after_contract_changes(): void
    {
        $fields = [...RncModelFields::defaults('inspection_form'), ['key' => 'equipment', 'label' => 'Equipamento', 'type' => 'text', 'required' => true, 'options' => []]];
        $this->saveSettings($this->settings(fields: $fields));
        $rnc = $this->createRnc(['equipment' => 'P10', 'work_type' => 'Pista Nova']);
        $original = $rnc->pdf_template_snapshot;
        $this->saveSettings($this->settings('standard'));
        $this->assertSame($original, $rnc->fresh()->pdf_template_snapshot);
        $this->get(route('tenant.qualidade.rnc.edit', [$this->tenant, $rnc]))->assertInertia(fn (Assert $p) => $p
            ->where('documentModel.layout', 'inspection_form')->where('documentModel.field_definitions.2.label', 'Equipamento'));
        $this->patch(route('tenant.qualidade.rnc.update', [$this->tenant, $rnc]), [...$this->payload, 'model_field_values' => ['equipment' => 'P11']])->assertSessionHasNoErrors();
        $pdf = app(RncPdfRenderer::class)->render($this->tenant, $rnc->fresh(), 'opening');
        $this->assertStringContainsString('Equipamento', $pdf->getDomPDF()->getDom()->textContent);
        $this->assertStringContainsString('P11', $pdf->getDomPDF()->getDom()->textContent);
        $new = $this->createRnc([]);
        $this->assertSame('standard', $new->pdf_template_snapshot['layout']);
        $this->assertSame([], $new->pdf_template_snapshot['field_definitions']);
        $this->assertNotSame($original['version'], $new->pdf_template_snapshot['version']);
    }

    public function test_all_custom_field_types_validate_answers_and_reject_unknown_or_invalid_values(): void
    {
        $fields = collect(['text', 'textarea', 'number', 'date', 'select', 'boolean'])->map(fn ($type) => [
            'key' => 'custom_'.$type, 'label' => 'Campo '.$type, 'type' => $type, 'required' => true, 'options' => $type === 'select' ? ['A', 'B'] : [],
        ])->all();
        $this->saveSettings($this->settings('standard', $fields));
        $url = route('tenant.qualidade.rnc.store', $this->tenant);
        $this->post($url, $this->payload)->assertSessionHasErrors('model_field_values.custom_text');
        $valid = ['custom_text' => 'Trecho', 'custom_textarea' => "Detalhes\nAdicionais", 'custom_number' => '12.5', 'custom_date' => '2026-10-26', 'custom_select' => 'B', 'custom_boolean' => 'no'];
        foreach (['custom_text' => ['array'], 'custom_number' => 'abc', 'custom_date' => '2026-02-31', 'custom_select' => 'C', 'custom_boolean' => 'maybe', 'not_allowed' => 'x'] as $key => $value) {
            $this->post($url, [...$this->payload, 'model_field_values' => [...$valid, $key => $value]])->assertSessionHasErrors();
        }
        $rnc = $this->createRnc($valid);
        $this->assertSame($valid, $rnc->model_field_values);
        $text = app(RncPdfRenderer::class)->render($this->tenant, $rnc, 'opening')->getDomPDF()->getDom()->textContent;
        foreach (['Campo number', '12.5', '26/10/2026', 'Não'] as $marker) $this->assertStringContainsString($marker, $text);
    }

    public function test_field_definitions_cannot_inject_code_duplicate_keys_or_remove_inspection_fields(): void
    {
        $url = route('tenant.qualidade.rnc.settings.store', $this->tenant);
        foreach ([[], [['key' => 'invalid.key', 'label' => 'Nome', 'type' => 'text', 'required' => false, 'options' => []]],
            [...RncModelFields::defaults('inspection_form'), RncModelFields::defaults('inspection_form')[0]],
            [['key' => 'html', 'label' => 'Código', 'type' => 'html', 'required' => false, 'options' => []]],
        ] as $fields) $this->post($url, $this->settings(fields: $fields))->assertSessionHasErrors();
        $this->assertDatabaseCount('rnc_pdf_settings', 0);
        $this->saveSettings($this->settings());
        $response = $this->post(route('tenant.qualidade.rnc.settings.preview', $this->tenant), $this->settings())->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertDatabaseCount('relatorio_nao_conformidades', 0);
        Notification::assertNothingSent(); Http::assertNothingSent();
    }

    public function test_legacy_backfill_keeps_existing_fixed_values_and_does_not_change_dates_or_status(): void
    {
        $this->saveSettings($this->settings());
        $rnc = $this->createRnc([]);
        $setting = RncPdfSetting::firstOrFail();
        $setting->update(['options' => [...$setting->options, 'work_type' => 'Pista Nova', 'pavement_type' => 'Flexível']]);
        DB::table('relatorio_nao_conformidades')->where('id', $rnc->id)->update(['pdf_template_snapshot' => null, 'model_field_values' => null]);
        $before = $rnc->fresh()->getRawOriginal();
        $this->artisan('rnc:freeze-document-models')->expectsOutput('Modelos preservados em 1 RNC(s).')->assertSuccessful();
        $fresh = $rnc->fresh();
        $this->assertSame(['work_type' => 'Pista Nova', 'pavement_type' => 'Flexível'], $fresh->model_field_values);
        foreach (['updated_at', 'created_at', 'status'] as $key) $this->assertSame($before[$key], $fresh->getRawOriginal($key));
        $this->saveSettings($this->settings('standard'));
        $this->assertSame('inspection_form', $fresh->fresh()->pdf_template_snapshot['layout']);
        $this->artisan('rnc:freeze-document-models')->expectsOutput('Modelos preservados em 0 RNC(s).')->assertSuccessful();
    }

    public function test_saving_new_settings_preserves_unmigrated_and_soft_deleted_documents(): void
    {
        $this->saveSettings($this->settings());
        $rnc = $this->createRnc(['work_type' => 'Pista Existente']);
        $rnc->delete();
        DB::table('relatorio_nao_conformidades')->where('id', $rnc->id)->update(['pdf_template_snapshot' => null]);
        $this->saveSettings($this->settings('standard'));
        $old = RelatorioNaoConformidade::withTrashed()->findOrFail($rnc->id);
        $this->assertSame('inspection_form', $old->pdf_template_snapshot['layout']);
        $this->assertSame('Pista Existente', $old->model_field_values['work_type']);
        $this->assertNotNull($old->deleted_at);
    }
}

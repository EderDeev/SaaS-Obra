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

    private function legacyInspectionRnc(array $fields, array $values): RelatorioNaoConformidade
    {
        $this->saveSettings($this->settings());
        $rnc = $this->createRnc(array_intersect_key($values, array_flip(['work_type', 'pavement_type'])));
        // Fixture of a document created before manual fields became standard-only.
        $snapshot = [...$rnc->pdf_template_snapshot, 'field_definitions' => $fields];
        $rnc->forceFill(['pdf_template_snapshot' => $snapshot, 'model_field_values' => $values])->saveQuietly();
        return $rnc->fresh();
    }

    public function test_dashboard_selection_filters_follow_contract_and_preserve_historical_choices(): void
    {
        $field = ['key' => 'lote', 'label' => 'Lote', 'type' => 'select', 'required' => false, 'options' => ['Antigo', '0']];
        $text = ['key' => 'observacao', 'label' => 'Observação', 'type' => 'text', 'required' => false, 'options' => []];
        $this->saveSettings($this->settings('standard', [$field, $text]));
        $this->createRnc(['lote' => 'Antigo']);
        $this->saveSettings($this->settings('standard', [[...$field, 'options' => ['Atual', '0']], $text]));
        $this->createRnc(['lote' => 'Atual']);
        $this->createRnc(['lote' => '0']);
        $other = $this->tenant->contracts()->create(['code' => 'OTHER', 'name' => 'Outro', 'status' => 'active']);
        $url = route('tenant.qualidade.rnc.dashboard', $this->tenant);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->has('modelSelectFields', 0)->where('metrics.total', 3));
        $this->get($url.'?'.http_build_query(['contract_id' => $other->id]))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('modelSelectFields', 0)->where('metrics.total', 0));
        foreach (['Antigo', 'Atual', '0'] as $value) {
            $this->get($url.'?'.http_build_query(['contract_id' => $this->contract->id, 'model_fields' => ['lote' => $value]]))->assertOk()
                ->assertInertia(fn (Assert $p) => $p->has('modelSelectFields', 1)->where('modelSelectFields.0.key', 'lote')
                    ->where('modelSelectFields.0.options', ['Atual', '0', 'Antigo'])->where('metrics.total', 1)
                    ->where('filters.model_fields.lote', $value));
        }
        $this->get($url.'?'.http_build_query(['contract_id' => $this->contract->id, 'gravidade' => 'Leve', 'model_fields' => ['lote' => 'Atual']]))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->where('metrics.total', 0));
    }

    public function test_dashboard_rejects_unknown_nonselection_and_cross_contract_filters(): void
    {
        $field = ['key' => 'lote', 'label' => 'Lote', 'type' => 'select', 'required' => false, 'options' => ['A']];
        $this->saveSettings($this->settings('standard', [$field]));
        $other = $this->tenant->contracts()->create(['code' => 'OTHER', 'name' => 'Outro', 'status' => 'active']);
        $url = route('tenant.qualidade.rnc.dashboard', $this->tenant);
        foreach ([
            ['model_fields' => ['lote' => 'A']],
            ['contract_id' => $other->id, 'model_fields' => ['lote' => 'A']],
            ['contract_id' => $this->contract->id, 'model_fields' => ['texto' => 'A']],
        ] as $query) $this->get($url.'?'.http_build_query($query))->assertSessionHasErrors('model_fields');
        $this->get($url.'?'.http_build_query(['contract_id' => $this->contract->id, 'model_fields' => ['lote' => 'Inválido']]))
            ->assertSessionHasErrors('model_fields.lote');
    }

    public function test_dashboard_does_not_match_text_answers_against_a_selection_with_the_same_key(): void
    {
        $field = ['key' => 'lote', 'label' => 'Lote', 'type' => 'text', 'required' => false, 'options' => []];
        $this->saveSettings($this->settings('standard', [$field]));
        $this->createRnc(['lote' => 'A']);
        $this->saveSettings($this->settings('standard', [[...$field, 'type' => 'select', 'options' => ['A']]]));
        $this->createRnc(['lote' => 'A']);
        $this->get(route('tenant.qualidade.rnc.dashboard', $this->tenant).'?'.http_build_query([
            'contract_id' => $this->contract->id, 'model_fields' => ['lote' => 'A'],
        ]))->assertOk()->assertInertia(fn (Assert $p) => $p->where('metrics.total', 1));
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
            ->has('documentModels.'.$this->contract->id.'.field_definitions', 5)
            ->where('documentModels.'.$this->contract->id.'.field_definitions.0.key', 'work_type'));
        $rnc = $this->createRnc(['work_type' => 'Pista Nova', 'pavement_type' => 'Rígido']);
        $this->assertSame('inspection_form', $rnc->pdf_template_snapshot['layout']);
        $this->assertSame('Pista Nova', $rnc->model_field_values['work_type']);
        $this->get(route('tenant.qualidade.rnc.show', [$this->tenant, $rnc]))->assertInertia(fn (Assert $p) => $p
            ->where('modelFields.0.value', 'Pista Nova')->where('modelFields.1.value', 'Rígido')->missing('rnc.pdf_template_snapshot'));
        $this->get(route('tenant.qualidade.rnc.edit', [$this->tenant, $rnc]))->assertInertia(fn (Assert $p) => $p
            ->where('documentModel.layout', 'inspection_form')->where('modelFieldValues.work_type', 'Pista Nova')->has('documentModel.field_definitions', 5));
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
        $rnc = $this->legacyInspectionRnc($fields, ['equipment' => 'P10', 'work_type' => 'Pista Nova']);
        $original = $rnc->pdf_template_snapshot;
        $this->saveSettings($this->settings('standard'));
        $this->assertSame($original, $rnc->fresh()->pdf_template_snapshot);
        $this->get(route('tenant.qualidade.rnc.edit', [$this->tenant, $rnc]))->assertInertia(fn (Assert $p) => $p
            ->where('documentModel.layout', 'inspection_form')->where('documentModel.field_definitions.5.label', 'Equipamento'));
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
        $this->assertSame(['work_type' => 'Pista Nova', 'pavement_type' => 'Flexível', 'document_contract' => null, 'document_km' => null, 'document_location' => null], $fresh->model_field_values);
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

    public function test_custom_fields_use_each_models_native_identification_header(): void
    {
        $custom = [
            ['key' => 'lot', 'label' => 'Lote', 'type' => 'text', 'required' => false, 'options' => []],
            ['key' => 'location_km', 'label' => 'Localização da RNC (Km)', 'type' => 'number', 'required' => false, 'options' => []],
            ['key' => 'road', 'label' => 'Rodovia', 'type' => 'select', 'required' => false, 'options' => ['BR-316', 'BR-010']],
            ['key' => 'interdicted', 'label' => 'Trecho interditado', 'type' => 'boolean', 'required' => false, 'options' => []],
        ];
        $values = ['lot' => 'Lote 02', 'location_km' => '12.5', 'road' => 'BR-316', 'interdicted' => 'no'];
        foreach (['standard', 'inspection_form'] as $layout) {
            if ($layout === 'standard') {
                $this->saveSettings($this->settings($layout, $custom));
                $rnc = $this->createRnc($values);
            } else {
                $rnc = $this->legacyInspectionRnc([...RncModelFields::defaults($layout), ...$custom], $values);
            }
            foreach (['opening', 'evidence'] as $scope) {
                $pdf = app(RncPdfRenderer::class)->render($this->tenant, $rnc, $scope);
                $dom = $pdf->getDomPDF()->getDom();
                $xpath = new \DOMXPath($dom);
                $headerFields = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," header ")]//*[contains(concat(" ",normalize-space(@class)," ")," model-header-fields ")]');
                $this->assertGreaterThan(0, $headerFields->length);
                $this->assertStringContainsString('Lote 02', $headerFields->item(0)->textContent);
                $this->assertStringContainsString('12.5', $headerFields->item(0)->textContent);
                $this->assertStringNotContainsString('Informações do modelo', $dom->textContent);
                if ($layout === 'inspection_form') {
                    $this->assertSame(2, $xpath->query('//table[contains(@class,"model-header-fields")]//span[contains(@class,"check")][normalize-space(.)="X"]')->length);
                    $this->assertSame(2, $xpath->query('//table[contains(@class,"model-header-fields")]//span[contains(@class,"field-box")]')->length);
                } else {
                    $this->assertSame(4, $xpath->query('//*[contains(@class,"model-header-fields")]//span[@class="label"]')->length);
                }
                $bytes = $pdf->output();
                $this->assertStringStartsWith('%PDF-', $bytes);
                if ($folder = getenv('RNC_FIELDS_CAPTURE_DIR')) {
                    if (!is_dir($folder)) mkdir($folder, 0777, true);
                    file_put_contents($folder.'/'.$layout.'-header-'.$scope.'.pdf', $bytes);
                }
            }
        }
    }

    public function test_many_custom_fields_and_wrapped_values_keep_the_inspection_header_in_document_flow(): void
    {
        $custom = collect(range(1, 12))->map(fn ($index) => [
            'key' => 'item_'.$index, 'label' => 'Identificação complementar '.$index,
            'type' => 'text', 'required' => false, 'options' => [],
        ])->all();
        $values = collect($custom)->mapWithKeys(fn ($field) => [$field['key'] => 'Trecho da pista com identificação detalhada e informação complementar '.$field['key']])->all();
        $rnc = $this->legacyInspectionRnc([...RncModelFields::defaults('inspection_form'), ...$custom], $values);
        $signers = [['name' => 'Responsável', 'role' => 'gerenciadora', 'email' => 'signer@example.test']];
        $pdf = app(RncPdfRenderer::class)->render($this->tenant, $rnc, 'evidence', $signers);
        $dom = $pdf->getDomPDF()->getDom();
        $this->assertStringContainsString('position: static;', $dom->textContent);
        $this->assertStringContainsString('item_12', $dom->textContent);
        $this->assertStringContainsString('Problema', $dom->textContent);
        $bytes = $pdf->output();
        $this->assertStringStartsWith('%PDF-', $bytes);
        if ($folder = getenv('RNC_FIELDS_CAPTURE_DIR')) file_put_contents($folder.'/inspection-many-fields.pdf', $bytes);
    }

    public function test_long_header_answers_continue_without_losing_content_or_creating_unbounded_rows(): void
    {
        $field = ['key' => 'details', 'label' => 'Detalhamento do trecho', 'type' => 'textarea', 'required' => false, 'options' => []];
        $answer = str_repeat("Verificação complementar do trecho inspecionado.\n", 80).'FIM_DO_CAMPO';
        $rnc = $this->legacyInspectionRnc([...RncModelFields::defaults('inspection_form'), $field], ['details' => $answer]);
        $pdf = app(RncPdfRenderer::class)->render($this->tenant, $rnc, 'opening');
        $dom = $pdf->getDomPDF()->getDom();
        $xpath = new \DOMXPath($dom);
        $values = $xpath->query('//table[contains(@class,"model-header-fields")]//span[contains(@class,"model-field-value")]');
        $this->assertGreaterThan(1, $values->length);
        // nl2br adds a DOM line break; compare the text preserved across all rows.
        $this->assertSame($answer, implode('', array_map(fn ($node) => $node->textContent, iterator_to_array($values))));
        $bytes = $pdf->output();
        $this->assertStringStartsWith('%PDF-', $bytes);
        if ($folder = getenv('RNC_FIELDS_CAPTURE_DIR')) file_put_contents($folder.'/inspection-long-header.pdf', $bytes);
    }

    public function test_only_standard_model_accepts_manual_fields_and_inspection_uses_its_defined_fields(): void
    {
        $extra = ['key' => 'lot', 'label' => 'Lote', 'type' => 'text', 'required' => false, 'options' => []];
        $url = route('tenant.qualidade.rnc.settings.store', $this->tenant);
        $this->saveSettings($this->settings('standard', [$extra]));
        $this->assertSame(['lot'], array_column(RncPdfTemplates::forContract($this->tenant, $this->contract->id)['field_definitions'], 'key'));
        $this->post($url, $this->settings(fields: [...RncModelFields::defaults('inspection_form'), $extra]))->assertSessionHasErrors('field_definitions');
        $this->assertSame('standard', RncPdfSetting::firstOrFail()->layout);
        $this->saveSettings($this->settings());
        $this->assertSame(['work_type', 'pavement_type', 'document_contract', 'document_km', 'document_location'], array_column(RncPdfTemplates::forContract($this->tenant, $this->contract->id)['field_definitions'], 'key'));
        $this->post(route('tenant.qualidade.rnc.store', $this->tenant), [...$this->payload, 'model_field_values' => ['lot' => 'Lote 01']])->assertSessionHasErrors('model_field_values');
        $this->createRnc(['work_type' => 'Pista Nova', 'pavement_type' => 'Rígido']);
    }

    public function test_existing_inspection_settings_extras_do_not_apply_to_new_documents_but_old_versions_survive(): void
    {
        $extra = ['key' => 'lot', 'label' => 'Lote', 'type' => 'text', 'required' => false, 'options' => []];
        $fields = [...RncModelFields::defaults('inspection_form'), $extra];
        $old = $this->legacyInspectionRnc($fields, ['lot' => 'Lote original']);
        RncPdfSetting::firstOrFail()->update(['field_definitions' => $fields]);
        $this->get(route('tenant.qualidade.rnc.settings.index', $this->tenant))->assertInertia(fn (Assert $p) => $p
            ->has('settings.'.$this->contract->id.'.field_definitions', 5));
        $this->get(route('tenant.qualidade.rnc.create', $this->tenant))->assertInertia(fn (Assert $p) => $p
            ->has('documentModels.'.$this->contract->id.'.field_definitions', 5));
        $new = $this->createRnc([]);
        $this->assertCount(5, $new->pdf_template_snapshot['field_definitions']);
        $this->get(route('tenant.qualidade.rnc.edit', [$this->tenant, $old]))->assertInertia(fn (Assert $p) => $p
            ->has('documentModel.field_definitions', 6)->where('modelFieldValues.lot', 'Lote original'));
        DB::table('relatorio_nao_conformidades')->where('id', $old->id)->update(['pdf_template_snapshot' => null]);
        $this->saveSettings($this->settings());
        $this->assertSame($fields, $old->fresh()->pdf_template_snapshot['field_definitions']);
        $this->assertSame('Lote original', $old->fresh()->model_field_values['lot']);
    }

    public function test_inspection_contract_selection_is_independent_from_the_system_contract(): void
    {
        $fields = array_map(fn ($field) => $field['key'] === 'document_contract'
            ? [...$field, 'options' => ['CT-EXTERNO 045/2026', 'Contrato digitado atualizado']] : $field, RncModelFields::defaults('inspection_form'));
        $this->saveSettings($this->settings(fields: $fields));
        $rnc = $this->createRnc(['document_contract' => 'CT-EXTERNO 045/2026']);
        $this->assertSame($this->contract->id, $rnc->contract_id);
        $this->assertSame('CT-EXTERNO 045/2026', $rnc->model_field_values['document_contract']);
        $this->get(route('tenant.qualidade.rnc.edit', [$this->tenant, $rnc]))->assertInertia(fn (Assert $p) => $p
            ->where('modelFieldValues.document_contract', 'CT-EXTERNO 045/2026'));
        foreach (['opening', 'analysis', 'evidence'] as $scope) {
            $pdf = app(RncPdfRenderer::class)->render($this->tenant, $rnc->fresh(), $scope);
            $dom = $pdf->getDomPDF()->getDom(); $xpath = new \DOMXPath($dom);
            $contract = $xpath->query('//span[contains(@class,"document-contract")]')->item(0);
            $this->assertSame('CT-EXTERNO 045/2026', trim($contract->textContent));
            $this->assertSame(0, $xpath->query('//table[contains(@class,"model-header-fields")]')->length);
            if ($scope === 'opening' && ($folder = getenv('RNC_FIELDS_CAPTURE_DIR'))) {
                if (!is_dir($folder)) mkdir($folder, 0777, true);
                file_put_contents($folder.'/inspection-free-contract.pdf', $pdf->output());
            }
        }
        $this->patch(route('tenant.qualidade.rnc.update', [$this->tenant, $rnc]), [...$this->payload, 'model_field_values' => ['document_contract' => 'Contrato digitado atualizado']])->assertSessionHasNoErrors();
        $this->assertSame('Contrato digitado atualizado', $rnc->fresh()->model_field_values['document_contract']);
        $this->assertSame($this->contract->id, $rnc->fresh()->contract_id);
    }

    public function test_empty_inspection_contract_does_not_use_system_code_and_old_documents_keep_original_header(): void
    {
        $this->saveSettings($this->settings());
        $rnc = $this->createRnc([]);
        $dom = app(RncPdfRenderer::class)->render($this->tenant, $rnc, 'opening')->getDomPDF()->getDom();
        $this->assertSame('-', trim((new \DOMXPath($dom))->query('//span[contains(@class,"document-contract")]')->item(0)->textContent));
        // Existing document version from before the free-text contract field.
        $snapshot = $rnc->pdf_template_snapshot;
        $snapshot['field_definitions'] = array_values(array_filter($snapshot['field_definitions'], fn ($field) => $field['key'] !== 'document_contract'));
        $rnc->forceFill(['pdf_template_snapshot' => $snapshot])->saveQuietly();
        $oldDom = app(RncPdfRenderer::class)->render($this->tenant, $rnc->fresh(), 'opening')->getDomPDF()->getDom();
        $this->assertSame($this->contract->code, trim((new \DOMXPath($oldDom))->query('//span[contains(@class,"document-contract")]')->item(0)->textContent));
        $this->assertSame($snapshot, $rnc->fresh()->pdf_template_snapshot);
    }

    public function test_inspection_km_accepts_free_text_and_keeps_it_when_editing_and_printing(): void
    {
        $this->saveSettings($this->settings());
        $rnc = $this->createRnc(['document_km' => '12+500 ao 14+200']);
        $this->assertSame('12+500 ao 14+200', $rnc->model_field_values['document_km']);
        $this->get(route('tenant.qualidade.rnc.edit', [$this->tenant, $rnc]))->assertInertia(fn (Assert $p) => $p
            ->where('modelFieldValues.document_km', '12+500 ao 14+200'));
        foreach (['opening', 'analysis', 'evidence'] as $scope) {
            $pdf = app(RncPdfRenderer::class)->render($this->tenant, $rnc->fresh(), $scope);
            $dom = $pdf->getDomPDF()->getDom(); $xpath = new \DOMXPath($dom);
            $this->assertSame('12+500 ao 14+200', trim($xpath->query('//span[contains(@class,"document-km")]')->item(0)->textContent));
            $this->assertSame(0, $xpath->query('//table[contains(@class,"model-header-fields")]')->length);
            if ($scope === 'opening' && ($folder = getenv('RNC_FIELDS_CAPTURE_DIR'))) {
                if (!is_dir($folder)) mkdir($folder, 0777, true);
                file_put_contents($folder.'/inspection-free-km.pdf', $pdf->output());
            }
        }
        $this->patch(route('tenant.qualidade.rnc.update', [$this->tenant, $rnc]), [...$this->payload, 'model_field_values' => ['document_km' => 'Trecho urbano / estaca 120']])->assertSessionHasNoErrors();
        $this->assertSame('Trecho urbano / estaca 120', $rnc->fresh()->model_field_values['document_km']);
        $this->assertSame($this->contract->id, $rnc->fresh()->contract_id);
        $zero = $this->createRnc(['document_km' => '0']);
        $dom = app(RncPdfRenderer::class)->render($this->tenant, $zero, 'opening')->getDomPDF()->getDom();
        $this->assertSame('0', trim((new \DOMXPath($dom))->query('//span[contains(@class,"document-km")]')->item(0)->textContent));
    }

    public function test_inspection_location_is_free_text_and_printed_in_its_original_header_box(): void
    {
        $this->saveSettings($this->settings());
        $rnc = $this->createRnc(['document_location' => 'Ponte sobre o Rio Azul / margem direita']);
        $this->get(route('tenant.qualidade.rnc.edit', [$this->tenant, $rnc]))->assertInertia(fn (Assert $p) => $p
            ->where('modelFieldValues.document_location', 'Ponte sobre o Rio Azul / margem direita'));
        foreach (['opening', 'analysis', 'evidence'] as $scope) {
            $pdf = app(RncPdfRenderer::class)->render($this->tenant, $rnc->fresh(), $scope);
            $dom = $pdf->getDomPDF()->getDom(); $xpath = new \DOMXPath($dom);
            $this->assertSame('Ponte sobre o Rio Azul / margem direita', trim($xpath->query('//span[contains(@class,"document-location")]')->item(0)->textContent));
            $this->assertSame(0, $xpath->query('//table[contains(@class,"model-header-fields")]')->length);
            if ($scope === 'opening' && ($folder = getenv('RNC_FIELDS_CAPTURE_DIR'))) {
                if (!is_dir($folder)) mkdir($folder, 0777, true);
                file_put_contents($folder.'/inspection-location.pdf', $pdf->output());
            }
        }
        $this->patch(route('tenant.qualidade.rnc.update', [$this->tenant, $rnc]), [...$this->payload, 'model_field_values' => ['document_location' => 'Acesso ao canteiro']])->assertSessionHasNoErrors();
        $this->assertSame('Acesso ao canteiro', $rnc->fresh()->model_field_values['document_location']);
        $this->assertSame($this->payload['obra_id'], $rnc->fresh()->obra_id);
    }

    public function test_contract_options_are_scoped_validated_and_preserved_in_existing_document_versions(): void
    {
        $fields = array_map(fn ($field) => $field['key'] === 'document_contract' ? [...$field, 'options' => ['CT-A', 'CT-B']] : $field, RncModelFields::defaults('inspection_form'));
        $this->saveSettings($this->settings(fields: $fields));
        $this->get(route('tenant.qualidade.rnc.create', $this->tenant))->assertInertia(fn (Assert $p) => $p
            ->where('documentModels.'.$this->contract->id.'.field_definitions.2.type', 'select')
            ->where('documentModels.'.$this->contract->id.'.field_definitions.2.options', ['CT-A', 'CT-B']));
        $other = $this->tenant->contracts()->create(['code' => 'OTHER', 'name' => 'Outro', 'status' => 'active']);
        RncPdfSetting::create(['tenant_id' => $this->tenant->id, 'contract_id' => $other->id, 'layout' => 'inspection_form', 'options' => RncPdfTemplates::defaults('inspection_form')]);
        $this->assertSame([], collect(RncPdfTemplates::forContract($this->tenant, $other->id)['field_definitions'])->firstWhere('key', 'document_contract')['options']);
        $this->post(route('tenant.qualidade.rnc.store', $this->tenant), [...$this->payload, 'model_field_values' => ['document_contract' => 'NÃO CADASTRADO']])->assertSessionHasErrors('model_field_values.document_contract');
        $old = $this->createRnc(['document_contract' => 'CT-B']);
        $newFields = array_map(fn ($field) => $field['key'] === 'document_contract' ? [...$field, 'options' => ['CT-C']] : $field, $fields);
        $this->saveSettings($this->settings(fields: $newFields));
        $this->get(route('tenant.qualidade.rnc.edit', [$this->tenant, $old]))->assertInertia(fn (Assert $p) => $p
            ->where('documentModel.field_definitions.2.options', ['CT-A', 'CT-B'])->where('modelFieldValues.document_contract', 'CT-B'));
        $this->patch(route('tenant.qualidade.rnc.update', [$this->tenant, $old]), [...$this->payload, 'model_field_values' => ['document_contract' => 'CT-A']])->assertSessionHasNoErrors();
        $this->assertSame('CT-A', $old->fresh()->model_field_values['document_contract']);
        $new = $this->createRnc(['document_contract' => 'CT-C']);
        $this->assertSame(['CT-C'], $new->pdf_template_snapshot['field_definitions'][2]['options']);
        foreach ([array_fill(0, 31, 'Opção'), [str_repeat('x', 121)]] as $invalidOptions) {
            $invalid = array_map(fn ($field) => $field['key'] === 'document_contract' ? [...$field, 'options' => $invalidOptions] : $field, $fields);
            $this->post(route('tenant.qualidade.rnc.settings.store', $this->tenant), $this->settings(fields: $invalid))->assertSessionHasErrors();
        }
    }

    public function test_old_free_text_contract_values_are_preserved_after_switching_to_registered_options(): void
    {
        $legacyFields = array_map(fn ($field) => $field['key'] === 'document_contract' ? [...$field, 'type' => 'text', 'options' => []] : $field, RncModelFields::defaults('inspection_form'));
        $old = $this->legacyInspectionRnc($legacyFields, ['document_contract' => 'Contrato antigo digitado']);
        $fields = array_map(fn ($field) => $field['key'] === 'document_contract' ? [...$field, 'options' => ['Contrato cadastrado']] : $field, RncModelFields::defaults('inspection_form'));
        $this->saveSettings($this->settings(fields: $fields));
        $this->get(route('tenant.qualidade.rnc.edit', [$this->tenant, $old]))->assertInertia(fn (Assert $p) => $p
            ->where('documentModel.field_definitions.2.type', 'text')->where('modelFieldValues.document_contract', 'Contrato antigo digitado'));
        $this->assertSame('Contrato antigo digitado', $old->fresh()->model_field_values['document_contract']);
    }
}

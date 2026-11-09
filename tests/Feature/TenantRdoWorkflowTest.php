<?php

namespace Tests\Feature;

use App\Models\{RdoConfiguracao, RdoDiario, RdoResponsavel, Tenant, User};
use App\Services\RdoDailyGenerator;
use App\Support\RdoWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Notification, Storage};
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantRdoWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake(); Http::preventStrayRequests(); Storage::fake('public'); Storage::fake('local');
    }

    public function test_pdf_exports_filter_stages_and_fronts_without_changing_the_diary(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'construtora_gerenciadora_cliente']);
        $other = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => '200', 'nome' => 'FRENTE EXCLUIDA', 'tipo' => 'pai']);
        $rdo->configuracao->obras()->attach($other);
        $this->fillParty($tenant, $owner, $obra, $rdo, 'construtora', 'CONSTRUTORA INCLUIDA');
        $this->fillParty($tenant, $owner, $other, $rdo, 'construtora', 'CONSTRUTORA EXCLUIDA');
        foreach ([$obra, $other] as $front) {
            foreach (['gerenciadora', 'cliente'] as $party) {
                $rdo->analises()->create(['tenant_id' => $tenant->id, 'obra_id' => $front->id, 'user_id' => $owner->id, 'etapa' => $party, 'decisao' => 'approve', 'comentario' => strtoupper($party).' ANALISE '.$front->id, 'status_anterior' => 'em_analise_gerenciadora', 'status_novo' => 'arquivado']);
            }
            $rdo->fiscalizacaoRegistros()->create(['tenant_id' => $tenant->id, 'obra_id' => $front->id, 'user_id' => $owner->id, 'version_number' => 1, 'status' => 'concluido', 'resumo_fiscalizacao' => 'INSPECAO '.$front->id]);
        }
        foreach (['construtora', 'gerenciadora', 'cliente'] as $stage) {
            $pdf = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $rdo->fresh(), null, ['stage' => $stage, 'obra_ids' => [$obra->id]]);
            $text = $pdf->getDomPDF()->getDom()->textContent;
            $this->assertStringContainsString('CONSTRUTORA INCLUIDA', $text);
            $this->assertStringNotContainsString('Histórico de análise e aprovação', $text);
            $this->assertStringNotContainsString('Versões do RDO', $text);
            $this->assertStringNotContainsString('Assinaturas das partes', $text);
            $this->assertStringNotContainsString('rdo-final-signature-table', $pdf->getDomPDF()->getDom()->saveHTML());
            $this->assertStringNotContainsString('CONSTRUTORA EXCLUIDA', $text);
            $this->assertStringNotContainsString('FRENTE EXCLUIDA', $text);
            $this->assertStringNotContainsString('INSPECAO '.$other->id, $text);
            $this->assertStringNotContainsString('CLIENTE ANALISE '.$other->id, $text);
            if ($stage === 'construtora') {
                $this->assertStringNotContainsString('INSPECAO '.$obra->id, $text);
                $this->assertStringNotContainsString('GERENCIADORA ANALISE '.$obra->id, $text);
            } else {
                $this->assertStringContainsString('INSPECAO '.$obra->id, $text);
                $this->assertStringContainsString('GERENCIADORA ANALISE '.$obra->id, $text);
            }
            if ($stage === 'cliente') $this->assertStringContainsString('CLIENTE ANALISE '.$obra->id, $text);
            else $this->assertStringNotContainsString('CLIENTE ANALISE '.$obra->id, $text);
            if ($folder = getenv('RDO_EXPORT_CAPTURE_DIR')) {
                if (! is_dir($folder)) mkdir($folder, 0777, true);
                file_put_contents($folder.'/'.$stage.'.pdf', $pdf->output());
            }
        }
        $this->assertSame(12, $rdo->secoes()->count());
        $this->assertSame(4, $rdo->analises()->count());
        $this->assertCount(2, $rdo->configuracao->fresh()->obras);
        $url = route('tenant.diario-obra.rdo.pdf', [$tenant, $rdo]);
        $this->get($url.'?'.http_build_query(['stage' => 'gerenciadora', 'obra_ids' => [$obra->id]]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->getJson($url.'?'.http_build_query(['obra_ids' => []]))->assertOk(); // omitted query keeps the complete legacy download
        $this->getJson($url.'?obra_ids=')->assertUnprocessable();
        $this->getJson($url.'?obra_ids[]=999999')->assertUnprocessable();
        $this->getJson($url.'?stage=signed')->assertUnprocessable();
        $this->getJson($url.'?stage=invalid')->assertUnprocessable();
    }

    public function test_pdf_renders_only_registered_activity_and_resource_rows_for_each_party(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'gerenciadora_construtora']);
        foreach (['construtora' => [5, 1], 'gerenciadora' => [12, 3]] as $party => [$activityCount, $equipmentCount]) {
            $activities = collect(range(1, $activityCount))->map(fn ($number) => [
                'titulo' => "Atividade {$number}",
                'ocorrencia' => 'Execução acompanhada na frente.',
            ])->all();
            $equipment = collect(range(1, $equipmentCount))->mapWithKeys(fn ($id) => [$id => ['quantidade' => 1]])->all();
            foreach (['atividades' => ['atividades' => $activities], 'equipamentos' => ['registros' => $equipment]] as $section => $data) {
                $rdo->secoes()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'filling_party' => $party, 'secao' => $section, 'dados' => $data]);
            }
        }

        $pdf = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $rdo->fresh());
        $xpath = new \DOMXPath($pdf->getDomPDF()->getDom());
        $pages = $xpath->query('//section[.//tr[@class="activity"]]');
        $this->assertCount(2, $pages);
        foreach ([[5, 1], [12, 3]] as $index => [$activityCount, $equipmentCount]) {
            $page = $pages->item($index);
            $this->assertCount($activityCount, $xpath->query('.//tr[@class="activity"]', $page));
            $this->assertCount($equipmentCount, $xpath->query('.//tr[@class="resource-row"]', $page));
        }
        if ($folder = getenv('RDO_ROW_CAPTURE_DIR')) {
            if (! is_dir($folder)) mkdir($folder, 0777, true);
            file_put_contents($folder.'/rdo-linhas.pdf', $pdf->output());
        }
    }

    public function test_pdf_export_stages_follow_each_workflow_model(): void
    {
        foreach (array_keys(RdoWorkflow::MODELS) as $model) {
            [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => $model]);
            $url = route('tenant.diario-obra.rdo.pdf', [$tenant, $rdo]);
            foreach (array_keys(RdoWorkflow::PARTIES) as $stage) {
                $response = $this->getJson($url.'?'.http_build_query(['stage' => $stage, 'obra_ids' => [$obra->id]]));
                if (in_array($stage, RdoWorkflow::parties($rdo), true)) $response->assertOk()->assertHeader('content-type', 'application/pdf');
                else $response->assertUnprocessable();
            }
        }
    }

    public function test_every_workflow_can_be_submitted_and_approved_without_photos_even_with_legacy_photo_requirement(): void
    {
        foreach (array_keys(RdoWorkflow::MODELS) as $model) {
            [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => $model]);
            $rdo->configuracao->update(['require_photos' => true]);
            foreach (RdoWorkflow::fillParties($rdo) as $party) {
                $this->fillParty($tenant, $owner, $obra, $rdo, $party, 'Sem atividade nesta obra.');
            }
            $rdo->secoes()->where('secao', 'fotos')->delete();
            $progress = new \ReflectionMethod(\App\Http\Controllers\Tenant\RdoController::class, 'rdoCompletionPercent');
            $this->assertSame(100, $progress->invoke(app(\App\Http\Controllers\Tenant\RdoController::class), $rdo->fresh()));
            $url = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
            $this->post($url, ['action' => 'submit'])->assertSessionHasNoErrors()->assertRedirect();
            if (in_array('gerenciadora', RdoWorkflow::reviews($rdo), true)) {
                $this->post(route('tenant.diario-obra.rdo.inspection.store', [$tenant, $rdo]), ['obra_id' => $obra->id, 'atividades' => [['titulo' => 'Sem atividade', 'ocorrencia' => 'Obra sem serviços executados no dia.']], 'comentario' => 'Sem atividade nesta obra.', 'evidencias' => [], 'status' => 'concluido'])->assertSessionHasNoErrors();
                $this->post($url, ['action' => 'approve', 'comments_by_obra' => [$obra->id => 'Sem atividade, registro conferido sem fotografias.']])->assertSessionHasNoErrors()->assertRedirect();
            }
            if (in_array('cliente', RdoWorkflow::reviews($rdo), true)) {
                $this->post($url, ['action' => 'approve_with_reservations', 'comments_by_obra' => [$obra->id => 'Ausência de registro fotográfico orientado.'], 'reservation_obra_ids' => [$obra->id], 'reservations_by_obra' => [$obra->id => ['obligation' => 'Apresentar as fotografias solicitadas.', 'responsible' => 'Construtora', 'due_date' => now()->addDay()->toDateString()]]])->assertSessionHasNoErrors();
                $this->assertSame('pendente_comprovacao', $rdo->fresh()->status);
            } else $this->assertSame('arquivado', $rdo->fresh()->status);
            $this->assertCount(0, Storage::disk('public')->allFiles());
            $this->assertCount(0, Storage::disk('local')->allFiles());
            $pdf = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $rdo->fresh(), null, ['stage' => 'gerenciadora']);
            $text = $pdf->getDomPDF()->getDom()->textContent;
            $this->assertStringContainsString('Nenhuma fotografia registrada nesta frente.', $text);
            if (in_array('gerenciadora', RdoWorkflow::reviews($rdo), true)) $this->assertStringContainsString('Nenhuma evidência anexada.', $text);
            if ($model === 'construtora_gerenciadora_cliente' && ($folder = getenv('RDO_OPTIONAL_PHOTO_CAPTURE_DIR'))) {
                if (!is_dir($folder)) mkdir($folder, 0777, true);
                file_put_contents($folder.'/rdo-sem-fotos.pdf', $pdf->output());
            }
        }
    }

    public function test_mobile_rdo_can_sync_without_photo_section_and_submit_without_images(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $rdo->configuracao->update(['require_photos' => true]);
        \App\Models\MobileApiToken::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'name' => 'Sem fotos', 'token_hash' => hash('sha256', 'mobile-no-photos'), 'expires_at' => now()->addDay()]);
        $headers = ['Authorization' => 'Bearer mobile-no-photos'];
        $sections = array_fill_keys(['clima', 'mao_obra', 'equipamentos', 'atividades', 'comentarios'], []);
        $sections['comentarios'] = ['gerenciadora' => 'Sem atividade e sem imagens no dia.'];
        $this->postJson(route('api.mobile.rdo.offline.store'), ['local_uuid' => 'no-photo-1', 'is_holiday' => true, 'rdo_diario_id' => $rdo->id, 'obra_id' => $obra->id, 'reference_date' => $rdo->reference_date->format('Y-m-d'), 'filling_party' => 'gerenciadora', 'revision' => \App\Http\Controllers\Api\Mobile\MobileRdoController::revision($rdo, $obra->id, 'gerenciadora'), 'secoes' => $sections], $headers)->assertCreated();
        $this->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->assertSame('em_aprovacao_cliente', $rdo->fresh()->status);
        $this->assertTrue($rdo->fresh()->is_holiday);
        $this->assertEmpty($rdo->secoes()->where('secao', 'fotos')->first()->dados['arquivos'] ?? []);
    }

    public function test_rdo_pdf_viewer_sets_current_favicon_and_keeps_validated_pdf_scope(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'construtora_gerenciadora_cliente']);
        $url = route('tenant.diario-obra.rdo.pdf', [$tenant, $rdo]);
        $this->get($url.'?'.http_build_query(['viewer' => 1, 'stage' => 'gerenciadora', 'obra_ids' => [$obra->id]]))
            ->assertOk()->assertViewIs('pdf.viewer')
            ->assertSee('brand/sigworks-mark.svg?v=', false)->assertSee('<iframe', false)
            ->assertViewHas('pdfUrl', function ($pdfUrl) use ($obra) {
                parse_str(parse_url($pdfUrl, PHP_URL_QUERY), $query);
                return $query === ['stage' => 'gerenciadora', 'obra_ids' => [(string) $obra->id]];
            });
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson($url.'?viewer=1&obra_ids[]=999999')->assertUnprocessable();
    }

    public function test_concremat_pdf_layout_is_scoped_to_contract_and_preserves_old_documents_and_workflow(): void
    {
        [$tenant, $owner, $contract, $obra, $old, $payload] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $document = ['layout' => 'concremat_diary', 'title' => 'Diário de Obra', 'color' => '#2f75b5', 'title_color' => '#ffffff'];
        $settings = route('tenant.diario-obra.rdo.settings.store', $tenant);
        $this->post($settings, [...$payload, 'configuration_id' => $old->rdo_configuracao_id, 'document_configuration' => $document])->assertSessionHasNoErrors();
        $this->assertSame('standard', \App\Support\RdoPdfTemplates::forDiary($old->fresh())['layout']);
        $new = app(RdoDailyGenerator::class)->generateForConfiguration($old->configuracao->fresh(), CarbonImmutable::parse($old->reference_date)->addDay(), false, $owner->id);
        $this->assertSame('concremat_diary', $new->pdf_configuration_snapshot['layout']);
        $this->assertSame($old->workflow_snapshot, $new->workflow_snapshot);
        $otherContract = $tenant->contracts()->create(['code' => 'OTHER', 'name' => 'Outro contrato', 'status' => 'active']);
        $this->assertSame('standard', \App\Support\RdoPdfTemplates::forContract($tenant, $otherContract->id)['layout']);
        $this->post($settings, [...$payload, 'configuration_id' => $old->rdo_configuracao_id, 'document_configuration' => [...$document, 'layout' => 'standard']])->assertSessionHasNoErrors();
        $this->assertSame('concremat_diary', $new->fresh()->pdf_configuration_snapshot['layout']);
        $this->post($settings, [...$payload, 'configuration_id' => $old->rdo_configuracao_id, 'workflow' => ['model' => 'gerenciadora'], 'document_configuration' => $document])->assertSessionHasErrors('document_configuration.layout');
    }

    public function test_concremat_pdf_preview_uses_existing_fields_and_places_signatures_at_the_end(): void
    {
        [$tenant, $owner, $contract, $obra] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $contract->starts_at = '2026-06-11'; $contract->ends_at = '2027-05-11';
        $contract->description = 'Gerenciamento e consultoria técnica de controle de obras de engenharia relacionados aos serviços de alargamento das rampas e duplicação de passarelas.';
        $obra->nome = 'Alargamento das rampas e duplicação das passarelas';
        $preview = app(\App\Services\RdoPdfPreview::class);
        $document = ['layout' => 'concremat_diary', 'title' => 'Diário de Obra', 'color' => '#2f75b5', 'title_color' => '#ffffff', 'require_photos' => true, 'logos' => ['gerenciadora' => ['data_uri' => 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('brand/concremat-logo.png')))]]];
        $diary = $preview->make($tenant, $contract, 'gerenciadora_cliente', $document, $obra, $owner);
        $section = $diary->secoes->firstWhere('secao', 'atividades');
        $section->dados = ['atividades' => [
            ['titulo' => 'Engenharia', 'ocorrencia' => 'Acompanhamento da concretagem dos blocos de fundação e conferência do alinhamento das formas.'],
            ['titulo' => 'Segurança do trabalho', 'ocorrencia' => 'Inspeção das condições de içamento de peças e isolamento da área de movimentação de cargas.'],
            ['titulo' => 'Meio ambiente', 'ocorrencia' => 'Verificação da organização das áreas de apoio e da segregação dos resíduos da obra.'],
        ]];
        $renderer = app(\App\Services\RdoPdfRenderer::class);
        foreach (['completo' => [], 'gerenciadora' => ['stage' => 'gerenciadora', 'obra_ids' => [$obra->id]]] as $name => $export) {
            $pdf = $renderer->render($tenant, $diary, $preview, $export);
            $dom = $pdf->getDomPDF()->getDom();
            $text = $dom->textContent;
            $xpath = new \DOMXPath($dom);
            $this->assertSame('Frente de serviço'.$diary->configuracao->obras->sole()->nome, trim($xpath->evaluate('string(//thead/tr/td[strong="Frente de serviço"])')));
            foreach (['DIÁRIO DE OBRA', 'Prazo contratual', 'Saldo de prazo', 'Segurança do trabalho', 'Meio ambiente', 'Equipe envolvida', 'Comentários'] as $value) {
                if ($value === 'Comentários' && $name === 'gerenciadora') continue;
                $this->assertStringContainsString($value, $text);
            }
            $this->assertStringContainsString('Acompanhamento da concretagem', $text);
            $this->assertStringContainsString('Inspeção das condições de içamento', $text);
            $this->assertStringContainsString('segregação dos resíduos', $text);
            $this->assertStringNotContainsString('V01', $text);
            $this->assertStringNotContainsString('Fluxo de análise e aprovação', $text);
            $this->assertStringNotContainsString('Comentário da gerenciadora', $text);
            $bytes = $pdf->output();
            if ($name === 'completo') {
                $placement = $renderer->signaturePlacement($pdf);
                $this->assertSame(['gerenciadora', 'cliente'], array_keys($placement['signature_widgets']));
                $this->assertSame($pdf->getDomPDF()->getCanvas()->get_page_count(), $placement['signature_page']);
                $this->assertStringContainsString('Comentários EMAP', $text);
            } else $this->assertStringNotContainsString('Assinaturas das partes', $text);
            if ($folder = getenv('RDO_CONCREMAT_CAPTURE_DIR')) {
                if (!is_dir($folder)) mkdir($folder, 0777, true);
                file_put_contents($folder.'/concremat-'.$name.'.pdf', $bytes);
            }
        }
        $url = route('tenant.diario-obra.rdo.settings.preview', $tenant);
        $payload = ['contract_id' => $contract->id, 'obra_id' => $obra->id, 'model' => 'gerenciadora_cliente', ...$document];
        unset($payload['logos']);
        $this->post($url, $payload)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post($url, [...$payload, 'model' => 'gerenciadora_construtora'])->assertSessionHasErrors('layout');
    }

    public function test_concremat_reference_text_is_preview_only_and_header_uses_one_aligned_grid(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $name = $owner->name; $description = $contract->description; $workName = $obra->nome;
        $preview = app(\App\Services\RdoPdfPreview::class);
        $document = ['layout' => 'concremat_diary', 'title' => 'Diário de Obra', 'color' => '#2f75b5', 'title_color' => '#ffffff', 'require_photos' => true];
        $diary = $preview->make($tenant, $contract, 'gerenciadora_cliente', $document, $obra, $owner);
        $pdf = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $diary, $preview);
        $dom = $pdf->getDomPDF()->getDom(); $text = $dom->textContent;
        foreach (['MAGNO RIBEIRO LOPES', '30/09/2026', '111 dias', '223 dias', '334 dias', 'Foi realizado o acompanhamento das atividades:', 'Inspeção de segurança nas atividades:', 'Inspeção ambiental nas atividades:', 'Mini Van', 'Container', 'COM DUPLICAÇÃO DE PASSARELAS, LOCALIZADO EM SÃO LUÍS – MA.'] as $value) $this->assertStringContainsString($value, $text);
        $this->assertSame($name, $owner->name); $this->assertSame($name, $owner->fresh()->name);
        $this->assertSame($description, $contract->description); $this->assertSame($description, $contract->fresh()->description);
        $this->assertSame($workName, $obra->nome); $this->assertSame($workName, $obra->fresh()->nome);
        $grid = (new \DOMXPath($dom))->query('//table[@class="identification"]')->item(0);
        $this->assertNotNull($grid);
        $this->assertSame(1, (new \DOMXPath($dom))->query('//table[@class="identification"]')->length);
        $this->assertSame(0, $grid->getElementsByTagName('table')->length);
        $this->assertStringNotContainsString('Noite', $grid->textContent);
        if ($folder = getenv('RDO_CONCREMAT_CAPTURE_DIR')) {
            if (!is_dir($folder)) mkdir($folder, 0777, true);
            file_put_contents($folder.'/referencia-alinhada.pdf', $pdf->output());
        }
        $rdo->pdf_configuration_snapshot = \App\Support\RdoPdfTemplates::configuration($document);
        $real = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $rdo);
        $this->assertStringNotContainsString('MAGNO RIBEIRO LOPES', $real->getDomPDF()->getDom()->textContent);
    }

    public function test_manual_holiday_is_shared_by_all_works_saved_atomically_and_cannot_be_changed_after_submission(): void
    {
        [$tenant, $owner, $contract, $first, $rdo] = $this->scenario(['model' => 'construtora_gerenciadora_cliente']);
        $second = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => '102', 'nome' => 'Segunda obra', 'tipo' => 'pai']);
        $rdo->configuracao->obras()->attach($second);
        $make = fn ($obra) => ['obra_id' => $obra->id, 'secoes' => ['clima' => [], 'mao_obra' => [], 'equipamentos' => [], 'atividades' => [], 'comentarios' => ['construtora' => 'Sem atividade.']]];
        $url = route('tenant.diario-obra.rdo.sections.store-works', [$tenant, $rdo]);
        $this->assertNull($rdo->is_holiday);
        $this->post($url, ['is_holiday' => true, 'obras' => [$make($first), $make($second)]])->assertSessionHasNoErrors();
        $this->assertTrue($rdo->fresh()->is_holiday);
        $this->assertSame(12, $rdo->secoes()->count());
        $pdf = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $rdo->fresh(), null, ['stage' => 'construtora']);
        $this->assertStringContainsString('Feriado', $pdf->getDomPDF()->getDom()->textContent);
        $this->post($url, ['is_holiday' => false, 'obras' => [$make($second)]])->assertSessionHasNoErrors();
        $this->assertFalse($rdo->fresh()->is_holiday);
        $this->postJson($url, ['is_holiday' => 'invalid', 'obras' => [$make($first)]])->assertUnprocessable();
        $this->postJson($url, ['is_holiday' => true, 'obras' => [$make($first), [...$make($second), 'obra_id' => 999999]]])->assertForbidden();
        $this->assertFalse($rdo->fresh()->is_holiday);
        $this->post($url, ['is_holiday' => null, 'obras' => [$make($first)]])->assertSessionHasNoErrors();
        $this->assertNull($rdo->fresh()->is_holiday);
        foreach ([$first, $second] as $front) $this->fillParty($tenant, $owner, $front, $rdo, 'gerenciadora', 'Registro da fiscalização.');
        $this->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->postJson($url, ['filling_party' => 'construtora', 'is_holiday' => true, 'obras' => [$make($first)]])->assertForbidden();
        $this->assertNull($rdo->fresh()->is_holiday);
    }

    public function test_custom_diary_subject_selection_is_saved_validated_and_controls_pdf_groups(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $show = route('tenant.diario-obra.rdo.show', [$tenant, $rdo]);
        $this->get($show)->assertInertia(fn (Assert $p) => $p->where('rdo.activity_subjects', []));
        $rdo->update(['pdf_configuration_snapshot' => \App\Support\RdoPdfTemplates::configuration(['layout' => 'concremat_diary', 'title' => 'Diário de Obra'])]);
        $this->get($show)->assertInertia(fn (Assert $p) => $p->has('rdo.activity_subjects', 3));
        $this->assertCount(3, \App\Http\Controllers\Api\Mobile\MobileRdoController::state($rdo->fresh(), $obra->id, 'gerenciadora')['activity_subjects']);
        $activities = [
            ['assunto' => 'engenharia', 'titulo' => 'Segurança no título', 'ocorrencia' => 'MARCADOR ENGENHARIA'],
            ['assunto' => 'seguranca_trabalho', 'titulo' => 'Acompanhamento', 'ocorrencia' => 'MARCADOR SEGURANCA'],
            ['assunto' => 'meio_ambiente', 'titulo' => 'Acompanhamento', 'ocorrencia' => 'MARCADOR AMBIENTE'],
        ];
        $data = ['obra_id' => $obra->id, 'secoes' => ['atividades' => ['atividades' => $activities], 'comentarios' => ['gerenciadora' => 'COMENTARIO SOMENTE SISTEMA']]];
        $this->post(route('tenant.diario-obra.rdo.sections.store-works', [$tenant, $rdo]), ['obras' => [$data]])->assertSessionHasNoErrors();
        $this->assertSame($activities, $rdo->secoes()->where('secao', 'atividades')->first()->dados['atividades']);
        $pdf = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $rdo->fresh());
        $dom = $pdf->getDomPDF()->getDom(); $xpath = new \DOMXPath($dom);
        foreach (['Engenharia' => 'MARCADOR ENGENHARIA', 'Segurança do trabalho' => 'MARCADOR SEGURANCA', 'Meio ambiente' => 'MARCADOR AMBIENTE'] as $label => $marker) {
            $block = $xpath->query('//div[@class="section-title" and contains(., "'.$label.'")]/following-sibling::div[1]')->item(0);
            $this->assertNotNull($block); $this->assertStringContainsString($marker, $block->textContent);
        }
        $this->assertStringNotContainsString('COMENTARIO SOMENTE SISTEMA', $dom->textContent);
        $this->assertStringNotContainsString('Fluxo de análise e aprovação', $dom->textContent);
        $this->assertStringContainsString('Assinaturas das partes', $dom->textContent);
        $this->postJson(route('tenant.diario-obra.rdo.sections.store-all', [$tenant, $rdo]), ['obra_id' => $obra->id, 'secoes' => ['atividades' => ['atividades' => [['assunto' => 'invalid', 'titulo' => 'Inválida']]]]])->assertUnprocessable();
        $this->postJson(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, 'atividades']), ['obra_id' => $obra->id, 'dados' => ['atividades' => [['assunto' => 'invalid']]]])->assertUnprocessable();
        $this->assertSame($activities, $rdo->secoes()->where('secao', 'atividades')->first()->dados['atividades']);
    }

    private function scenario(array $workflow): array
    {
        $tenant = Tenant::create(['slug' => 'rdo-flow-'.Tenant::count(), 'name' => 'Fluxos', 'status' => 'active', 'plan' => 'starter']);
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $this->actingAs($user);
        $contract = $tenant->contracts()->create(['code' => 'FLOW', 'name' => 'Fluxo', 'status' => 'active']);
        $obra = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => '100', 'nome' => 'Frente', 'tipo' => 'pai']);
        $payload = ['contract_id' => $contract->id, 'obra_ids' => [$obra->id], 'workflow' => $workflow,
            'start_date' => now()->subDay()->toDateString(), 'timezone' => 'America/Sao_Paulo', 'generation_weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'generate_on_holidays' => true, 'copy_previous_day' => false, 'copy_workforce' => true, 'copy_equipment' => true,
            'copy_pending_activities' => true, 'require_photos' => false, 'digital_signature_enabled' => true,
            'submission_deadline_days' => 7, 'active' => true];
        $this->post(route('tenant.diario-obra.rdo.settings.store', $tenant), $payload)->assertSessionHasNoErrors();
        $rdo = RdoDiario::where('tenant_id', $tenant->id)->firstOrFail();
        return [$tenant, $user, $contract, $obra, $rdo, $payload];
    }

    private function fill($tenant, $user, $obra, $rdo): void
    {
        foreach (RdoWorkflow::fillParties($rdo) as $party) {
            $this->fillParty($tenant, $user, $obra, $rdo, $party, 'Registro da parte selecionada.');
        }
    }

    public function test_mobile_models_move_all_works_together_including_manager_only_finalization(): void
    {
        foreach (array_keys(RdoWorkflow::MODELS) as $model) {
            [$tenant, $user, $contract, $obra, $rdo] = $this->scenario(['model' => $model]);
            $other = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => '200', 'nome' => 'Segunda obra', 'tipo' => 'pai']);
            $rdo->configuracao->obras()->attach($other->id);
            $token = 'model-'.$model;
            \App\Models\MobileApiToken::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'name' => 'SigWorks']);
            $headers = ['Authorization' => 'Bearer '.$token];
            $bootstrap = $this->getJson(route('api.mobile.bootstrap'), $headers)->assertOk()->json('rdo_diarios');
            $this->assertCount(2 * count(RdoWorkflow::fillParties($rdo)), $bootstrap);
            $this->assertSame($model, $bootstrap[0]['workflow']['model']);
            foreach ($bootstrap as $index => $front) {
                $sections = array_fill_keys(['clima', 'mao_obra', 'equipamentos', 'atividades'], []);
                $sections['comentarios'] = [$front['filling_party'] => 'Conferência da obra '.$front['obra_id']];
                $this->postJson(route('api.mobile.rdo.offline.store'), ['local_uuid' => $token.'-'.$index, 'rdo_diario_id' => $rdo->id, 'obra_id' => $front['obra_id'], 'reference_date' => $front['reference_date'], 'filling_party' => $front['filling_party'], 'revision' => $front['revision'], 'secoes' => $sections], $headers)->assertCreated();
                if ($index === 0) $this->postJson(route('api.mobile.rdo.submit', $rdo->id), [], $headers)->assertUnprocessable();
            }
            $this->assertSame('rascunho', $rdo->fresh()->status);
            $this->postJson(route('api.mobile.rdo.submit', $rdo->id), [], $headers)->assertOk();
            $this->assertSame(match ($model) {
                'gerenciadora' => 'arquivado',
                'gerenciadora_cliente' => 'em_aprovacao_cliente',
                default => 'em_analise_gerenciadora',
            }, $rdo->fresh()->status);
            $this->postJson(route('api.mobile.rdo.submit', $rdo->id), [], $headers)->assertConflict();
        }
    }

    public function test_constructor_models_allow_parallel_mobile_sync_and_keep_manager_inspection_separate(): void
    {
        foreach (['construtora_gerenciadora_cliente', 'gerenciadora_construtora'] as $model) {
            [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => $model]);
            $headers = [];
            $people = [];
            foreach (['construtora', 'gerenciadora'] as $party) {
                $person = User::factory()->create(['must_change_password' => false]);
                $people[$party] = $person;
                $tenant->memberships()->create(['user_id' => $person->id, 'role' => 'member', 'status' => 'active']);
                RdoResponsavel::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $obra->id, 'user_id' => $person->id, 'modulo' => 'rdo', 'etapa' => $party, 'status' => 'active']);
                $token = $model.'-'.$party;
                \App\Models\MobileApiToken::create(['tenant_id' => $tenant->id, 'user_id' => $person->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'name' => $party]);
                $headers[$party] = ['Authorization' => 'Bearer '.$token];
            }
            // Exercise a draft created before the parallel filling rollout.
            $rdo->update(['workflow_snapshot' => [...$rdo->workflow_snapshot, 'fill_parties' => ['construtora']]]);
            $front = $this->getJson(route('api.mobile.bootstrap'), $headers['construtora'])->assertOk()->assertJsonCount(1, 'rdo_diarios')->json('rdo_diarios.0');
            $this->assertSame('construtora', $front['filling_party']);
            $managerFront = $this->getJson(route('api.mobile.bootstrap'), $headers['gerenciadora'])->assertOk()->assertJsonCount(1, 'rdo_diarios')->json('rdo_diarios.0');
            $this->assertSame('gerenciadora', $managerFront['filling_party']);
            $this->assertFalse($managerFront['can_set_holiday']);
            $sections = array_fill_keys(['clima', 'mao_obra', 'equipamentos', 'atividades', 'fotos'], []);
            $sections['comentarios'] = ['construtora' => 'Concretagem executada no trecho 101.'];
            $payload = ['local_uuid' => $model.'-offline', 'rdo_diario_id' => $rdo->id, 'obra_id' => $obra->id, 'reference_date' => $front['reference_date'], 'filling_party' => 'construtora', 'revision' => $front['revision'], 'secoes' => $sections];
            $url = route('api.mobile.rdo.offline.store');
            $this->postJson($url, $payload, $headers['gerenciadora'])->assertForbidden();
            $managerPayload = [...$payload, 'local_uuid' => $model.'-manager', 'filling_party' => 'gerenciadora',
                'revision' => $managerFront['revision'],
                'secoes' => [...$sections, 'equipamentos' => ['observacoes' => 'Equipamento verificado pela gerenciadora.'],
                    'comentarios' => ['gerenciadora' => 'Registro de campo da gerenciadora.']]];
            $managerSync = $this->postJson($url, $managerPayload, $headers['gerenciadora'])->assertCreated();
            $managerEquipment = $rdo->secoes()->where('filling_party', 'gerenciadora')->where('secao', 'equipamentos')->sole();
            $this->assertSame('Equipamento verificado pela gerenciadora.', $managerEquipment->dados['observacoes']);
            $evidenceUrl = route('tenant.diario-obra.rdo.inspection.store', [$tenant, $rdo]);
            $this->actingAs($people['gerenciadora'])->post($evidenceUrl, [
                'obra_id' => $obra->id, 'status' => 'concluido',
                'evidencias' => [\Illuminate\Http\UploadedFile::fake()->create('relatorio-de-campo.pdf', 3, 'application/pdf')],
            ])->assertSessionHasNoErrors();
            $this->assertSame('rascunho', $rdo->fresh()->status);
            $this->postJson(route('api.mobile.rdo.submit', $rdo->id), [], $headers['gerenciadora'])->assertUnprocessable();
            $this->postJson($url, [...$payload, 'is_holiday' => true], $headers['construtora'])->assertCreated();
            $this->postJson($url, [...$managerPayload, 'local_uuid' => $model.'-manager-next',
                'revision' => $managerSync->json('rdo.revision'), 'is_holiday' => false], $headers['gerenciadora'])->assertCreated();
            $this->assertTrue($rdo->fresh()->is_holiday);
            $this->postJson(route('api.mobile.rdo.submit', $rdo->id), [], $headers['gerenciadora'])->assertUnprocessable();
            $this->postJson(route('api.mobile.rdo.submit', $rdo->id), [], $headers['construtora'])->assertOk();
            $this->assertSame('em_analise_gerenciadora', $rdo->fresh()->status);
            $this->assertSame(['construtora', 'gerenciadora'], $rdo->fresh()->workflow_snapshot['fill_parties']);
            $this->getJson(route('api.mobile.bootstrap'), $headers['construtora'])->assertOk()->assertJsonCount(0, 'rdo_diarios');
            $reviewFront = $this->getJson(route('api.mobile.bootstrap'), $headers['gerenciadora'])->assertOk()->assertJsonCount(1, 'rdo_diarios')->json('rdo_diarios.0');
            $this->assertSame('Registro de campo da gerenciadora.', $reviewFront['sections']['comentarios']['gerenciadora']);
            $this->postJson($url, [...$managerPayload, 'local_uuid' => $model.'-manager-review', 'revision' => $reviewFront['revision'],
                'secoes' => [...$sections, 'comentarios' => ['gerenciadora' => 'Registro complementado durante a análise.']]], $headers['gerenciadora'])->assertCreated();
            $this->actingAs($people['gerenciadora'])->post($evidenceUrl, [
                'obra_id' => $obra->id, 'status' => 'concluido',
                'evidencias' => [\Illuminate\Http\UploadedFile::fake()->image('vistoria.jpg')],
            ])->assertSessionHasNoErrors();
            $record = \App\Models\RdoFiscalizacaoRegistro::where('rdo_diario_id', $rdo->id)->sole();
            $this->assertSame($people['gerenciadora']->id, $record->user_id);
            $this->assertSame((int) $rdo->fresh()->current_version, (int) $record->version_number);
            $this->assertSame(['relatorio-de-campo.pdf', 'vistoria.jpg'], collect($record->evidencias)->pluck('name')->all());
            $comments = $rdo->secoes()->where('secao', 'comentarios')->get()->keyBy('filling_party');
            $this->assertSame('Concretagem executada no trecho 101.', $comments['construtora']->dados['construtora']);
            $this->assertSame('Registro complementado durante a análise.', $comments['gerenciadora']->dados['gerenciadora']);
            $this->postJson($url, [...$payload, 'local_uuid' => $model.'-late'], $headers['construtora'])->assertForbidden();
            $this->assertSame($record->evidencias, $record->fresh()->evidencias);
        }
    }

    public function test_legacy_constructor_manager_diary_uses_the_current_manager_form_and_preserves_inspection_data(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'gerenciadora_construtora']);
        $this->fillParty($tenant, $owner, $obra, $rdo, 'construtora', 'Registro da construtora.');
        $flow = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
        $this->post($flow, ['action' => 'submit'])->assertSessionHasNoErrors();
        $rdo->refresh();
        $legacyWorkflow = [...$rdo->workflow_snapshot, 'fill_parties' => ['construtora']];
        $rdo->update(['workflow_snapshot' => $legacyWorkflow]);
        $rdo->fiscalizacaoRegistros()->create([
            'tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'user_id' => $owner->id,
            'version_number' => $rdo->current_version, 'status' => 'concluido',
            'clima' => ['manha' => 'nublado'], 'comentario' => 'Comentário preservado.',
            'resumo_fiscalizacao' => 'Vistoria anterior.', 'submitted_at' => now(),
        ]);

        $show = route('tenant.diario-obra.rdo.show', [$tenant, $rdo]);
        $this->get($show)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rdo.fill_party', 'gerenciadora')
            ->where('rdo.can_edit', true)
            ->where('rdo.manager_forward_ready', true)
            ->where("rdo.sections.{$obra->id}.clima.manha", 'nublado')
            ->where("rdo.sections.{$obra->id}.comentarios.gerenciadora", 'Comentário preservado.'));
        $state = \App\Http\Controllers\Api\Mobile\MobileRdoController::state($rdo, $obra->id, 'gerenciadora');
        $this->assertSame('Vistoria anterior.', $state['sections']['atividades']['atividades'][0]['ocorrencia']);

        $this->post(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, 'equipamentos']), [
            'obra_id' => $obra->id, 'filling_party' => 'gerenciadora', 'dados' => ['observacoes' => 'Escavadeira conferida.'],
        ])->assertSessionHasNoErrors();
        $this->post($flow, ['action' => 'approve', 'comments_by_obra' => [$obra->id => 'Registros conferidos.']])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('arquivado', $rdo->fresh()->status);
        $this->assertSame($legacyWorkflow, $rdo->fresh()->workflow_snapshot);
        $this->get($show.'?filling_party=gerenciadora')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rdo.fill_parties', ['construtora', 'gerenciadora'])
            ->where('rdo.can_edit', false)
            ->where("rdo.sections.{$obra->id}.equipamentos.observacoes", 'Escavadeira conferida.')
            ->where("rdo.sections.{$obra->id}.comentarios.gerenciadora", 'Comentário preservado.'));
    }

    private function inspect($tenant, $obra, $rdo): void
    {
        $this->post(route('tenant.diario-obra.rdo.inspection.store', [$tenant, $rdo]), [
            'obra_id' => $obra->id, 'resumo_fiscalizacao' => 'Conferido', 'servicos_atestados' => 'Serviços conferidos', 'status' => 'concluido',
        ])->assertSessionHasNoErrors();
    }

    private function fillParty($tenant, $user, $obra, $rdo, string $party, string $text, bool $photo = false): void
    {
        $sections = array_fill_keys(['clima', 'mao_obra', 'equipamentos', 'atividades', 'fotos'], []);
        $sections['clima'] = ['observacoes' => $text];
        $sections['comentarios'] = [$party => $text];
        $this->actingAs($user)->post(route('tenant.diario-obra.rdo.sections.store-all', [$tenant, $rdo]), [
            'obra_id' => $obra->id, 'filling_party' => $party, 'secoes' => $sections,
            'fotos' => $photo ? [\Illuminate\Http\UploadedFile::fake()->image($party.'.jpg')] : [],
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_parties_keep_independent_sections_photos_snapshots_and_pdf(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['fill_parties' => ['construtora', 'gerenciadora'], 'review_parties' => ['cliente']]);
        $flowUrl = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
        $this->fillParty($tenant, $owner, $obra, $rdo, 'construtora', 'EXCLUSIVO CONSTRUTORA', true);
        $this->post($flowUrl, ['action' => 'submit'])->assertStatus(422);
        $this->fillParty($tenant, $owner, $obra, $rdo, 'gerenciadora', 'EXCLUSIVO GERENCIADORA', true);
        $this->assertSame(12, $rdo->secoes()->count());
        $photos = $rdo->secoes()->where('secao', 'fotos')->get()->keyBy('filling_party');
        $constructorPath = $photos['construtora']->dados['arquivos'][0]['path'];
        $managerPath = $photos['gerenciadora']->dados['arquivos'][0]['path'];
        $this->assertNotSame($constructorPath, $managerPath);
        Storage::disk('public')->assertExists([$constructorPath, $managerPath]);
        $signature = new \App\Models\RdoSignatureRequest;
        $signature->setRelation('rdo', $rdo->fresh(['secoes', 'configuracao.obras']));
        $method = new \ReflectionMethod(\App\Services\Signatures\OpenSignSignatureProvider::class, 'rdoMainPageNumbers');
        $this->assertSame([1, 3], $method->invoke(app(\App\Services\Signatures\OpenSignSignatureProvider::class), $signature));
        $show = route('tenant.diario-obra.rdo.show', [$tenant, $rdo]);
        foreach (['construtora', 'gerenciadora'] as $party) {
            $this->get($show.'?filling_party='.$party)->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('rdo.fill_party', $party)->where('rdo.fill_parties', ['construtora', 'gerenciadora'])
                ->where('rdo.sections.'.$obra->id.'.comentarios.'.$party, 'EXCLUSIVO '.strtoupper($party)));
        }
        $this->post($flowUrl, ['action' => 'submit', 'filling_party' => 'gerenciadora'])->assertSessionHasNoErrors();
        $this->assertSame('em_aprovacao_cliente', $rdo->fresh()->status);
        $snapshot = $rdo->versoes()->firstOrFail()->sections_snapshot;
        $this->assertSame('EXCLUSIVO CONSTRUTORA', $snapshot['sections_by_party']['construtora'][$obra->id]['comentarios']['construtora']);
        $this->assertSame('EXCLUSIVO GERENCIADORA', $snapshot['sections_by_party']['gerenciadora'][$obra->id]['comentarios']['gerenciadora']);
        $this->post(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, 'clima']), ['obra_id' => $obra->id, 'filling_party' => 'gerenciadora', 'dados' => ['observacoes' => 'Indevido']])->assertForbidden();
        $pdf = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $rdo->fresh());
        $text = $pdf->getDomPDF()->getDom()->textContent;
        $this->assertStringContainsString('EXCLUSIVO CONSTRUTORA', $text);
        $this->assertStringContainsString('EXCLUSIVO GERENCIADORA', $text);
        $this->assertStringContainsString('Preenchimento: Construtora', $text);
        $this->assertStringContainsString('Preenchimento: Gerenciadora', $text);
        if ($folder = getenv('RDO_PARTY_CAPTURE_DIR')) {
            if (!is_dir($folder)) mkdir($folder, 0777, true);
            file_put_contents($folder.'/rdo-two-parties.pdf', $pdf->output());
        }
        $this->post($flowUrl, ['action' => 'approve', 'comments_by_obra' => [$obra->id => 'Conferidos os dois registros']])->assertSessionHasNoErrors();
        $this->assertSame('arquivado', $rdo->fresh()->status);
    }

    public function test_a_party_cannot_overwrite_or_reuse_the_other_partys_records_and_photos(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['fill_parties' => ['construtora', 'gerenciadora'], 'review_parties' => []]);
        $users = [];
        foreach (['construtora', 'gerenciadora'] as $party) {
            $user = User::factory()->create(); $users[$party] = $user;
            $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'member', 'status' => 'active']);
            RdoResponsavel::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $obra->id, 'user_id' => $user->id, 'modulo' => 'rdo', 'etapa' => $party, 'status' => 'active']);
        }
        $this->fillParty($tenant, $users['construtora'], $obra, $rdo, 'construtora', 'Registro original', true);
        $this->actingAs($users['gerenciadora'])->post(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, 'clima']), [
            'obra_id' => $obra->id, 'filling_party' => 'construtora', 'dados' => ['observacoes' => 'Sobrescrever'],
        ])->assertForbidden();
        $path = $rdo->secoes()->where('filling_party', 'construtora')->where('secao', 'fotos')->first()->dados['arquivos'][0]['path'];
        $this->post(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, 'fotos']), [
            'obra_id' => $obra->id, 'filling_party' => 'gerenciadora', 'dados' => ['arquivos' => [['path' => $path]]],
        ])->assertSessionHasErrors('fotos');
        $this->assertSame('Registro original', $rdo->secoes()->where('filling_party', 'construtora')->where('secao', 'clima')->first()->dados['observacoes']);
        $this->fillParty($tenant, $users['gerenciadora'], $obra, $rdo, 'gerenciadora', 'Registro da gerenciadora');
        $this->get(route('tenant.diario-obra.rdo.show', [$tenant, $rdo]))->assertOk()->assertInertia(fn (Assert $p) => $p->where('rdo.fill_party', 'gerenciadora'));
        $this->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), ['action' => 'submit', 'filling_party' => 'gerenciadora'])->assertSessionHasNoErrors();
        $this->assertSame('arquivado', $rdo->fresh()->status);
    }

    public function test_a_filling_party_can_also_have_a_separate_review_stage(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['fill_parties' => ['construtora', 'gerenciadora'], 'review_parties' => ['gerenciadora', 'cliente']]);
        foreach (['construtora', 'gerenciadora'] as $party) $this->fillParty($tenant, $owner, $obra, $rdo, $party, 'Registro '.$party);
        $url = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
        $this->post($url, ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->assertSame('em_analise_gerenciadora', $rdo->fresh()->status);
        $this->inspect($tenant, $obra, $rdo);
        $this->post($url, ['action' => 'approve', 'comments_by_obra' => [$obra->id => 'Analisado']])->assertSessionHasNoErrors();
        $this->assertSame('em_aprovacao_cliente', $rdo->fresh()->status);
        $this->post($url, ['action' => 'approve', 'comments_by_obra' => [$obra->id => 'Aprovado']])->assertSessionHasNoErrors();
        $this->assertSame('arquivado', $rdo->fresh()->status);
    }

    public function test_all_ten_ordered_combinations_complete_without_unselected_parties(): void
    {
        foreach (RdoWorkflow::FILLING_PARTIES as $fill) {
            $others = array_values(array_diff(array_keys(RdoWorkflow::PARTIES), [$fill]));
            foreach ([[], [$others[0]], [$others[1]], $others, array_reverse($others)] as $reviews) {
                $workflow = ['fill_party' => $fill, 'review_parties' => $reviews];
                [$tenant, $user, $contract, $obra, $rdo] = $this->scenario($workflow);
                $this->assertSame($workflow, $rdo->workflow_snapshot);
                $this->fill($tenant, $user, $obra, $rdo);
                $url = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
                $this->post($url, ['action' => 'submit'])->assertSessionHasNoErrors()->assertRedirect();
                foreach ($reviews as $party) {
                    $this->assertSame(RdoWorkflow::REVIEW_STATUSES[$party], $rdo->fresh()->status);
                    if ($party === 'gerenciadora') $this->inspect($tenant, $obra, $rdo);
                    $this->post($url, ['action' => 'approve', 'comments_by_obra' => [$obra->id => 'Parecer '.$party]])->assertSessionHasNoErrors();
                }
                $this->assertSame('arquivado', $rdo->fresh()->status);
                $this->assertNotNull($rdo->fresh()->approved_at);
                $this->assertNotNull($rdo->fresh()->submitted_at);
                $this->assertSame([$fill, ...$reviews], $rdo->analises()->orderBy('id')->pluck('etapa')->all());
                $this->get(route('tenant.diario-obra.rdo.show', [$tenant, $rdo]))->assertOk()
                    ->assertInertia(fn (Assert $p) => $p->where('rdo.workflow', $workflow)->where('rdo.participating_parties', [$fill, ...$reviews]));
            }
        }
    }

    public function test_new_settings_do_not_rewrite_old_or_legacy_diary_flows(): void
    {
        [$tenant, $user, $contract, $obra, $rdo, $payload] = $this->scenario(RdoWorkflow::DEFAULT);
        $legacy = $rdo->replicate(['sequence_number', 'code', 'reference_date']);
        $legacy->forceFill(['sequence_number' => 99, 'code' => 'LEGACY', 'reference_date' => now()->subDay(), 'workflow_snapshot' => null])->save();
        $updated = ['fill_party' => 'gerenciadora', 'review_parties' => []];
        $configuration = $rdo->configuracao;
        $this->post(route('tenant.diario-obra.rdo.settings.store', $tenant), [...$payload, 'configuration_id' => $configuration->id, 'workflow' => $updated])->assertSessionHasNoErrors();
        $this->assertSame(RdoWorkflow::DEFAULT, RdoWorkflow::definition($rdo->fresh()));
        $this->assertSame(RdoWorkflow::DEFAULT, RdoWorkflow::definition($legacy->fresh()));
        $new = app(RdoDailyGenerator::class)->generateForConfiguration($configuration->fresh(), CarbonImmutable::now()->addDay()->startOfDay());
        $this->assertSame($updated, $new->workflow_snapshot);
    }

    public function test_return_and_reservations_go_back_to_the_selected_filling_party(): void
    {
        [$tenant, $user, $contract, $obra, $rdo] = $this->scenario(['fill_party' => 'gerenciadora', 'review_parties' => ['cliente']]);
        $this->fill($tenant, $user, $obra, $rdo);
        $url = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
        $this->post($url, ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->post($url, ['action' => 'return', 'return_to' => 'gerenciadora', 'comments_by_obra' => [$obra->id => 'Ajustar']])->assertSessionHasNoErrors();
        $this->assertSame('gerenciadora', RdoWorkflow::activeParty($rdo->fresh()));
        $this->post($url, ['action' => 'submit', 'comment' => 'Ajustado'])->assertSessionHasNoErrors();
        $this->post($url, ['action' => 'approve_with_reservations', 'comments_by_obra' => [$obra->id => 'Ressalva'],
            'reservations_by_obra' => [$obra->id => ['obligation' => 'Comprovar', 'responsible' => 'Gerenciadora', 'due_date' => now()->addDay()->toDateString()]]])->assertSessionHasNoErrors();
        $this->assertSame('pendente_comprovacao', $rdo->fresh()->status);
        $this->assertSame('gerenciadora', RdoWorkflow::activeParty($rdo->fresh()));
        $this->post($url, ['action' => 'submit', 'comment' => 'Comprovado'])->assertSessionHasNoErrors();
        $this->post($url, ['action' => 'approve', 'comments_by_obra' => [$obra->id => 'Conferido']])->assertSessionHasNoErrors();
        $this->assertSame('arquivado', $rdo->fresh()->status);
    }

    public function test_a_responsible_from_an_unselected_party_cannot_fill_or_submit(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['fill_party' => 'gerenciadora', 'review_parties' => []]);
        $users = collect(['gerenciadora', 'construtora'])->mapWithKeys(function ($party) use ($tenant, $contract, $obra) {
            $user = User::factory()->create();
            $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'member', 'status' => 'active']);
            RdoResponsavel::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $obra->id, 'user_id' => $user->id, 'modulo' => 'rdo', 'etapa' => $party, 'status' => 'active']);
            return [$party => $user];
        });
        $this->fill($tenant, $users['gerenciadora'], $obra, $rdo);
        $this->actingAs($users['construtora'])->post(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, 'comentarios']), ['obra_id' => $obra->id, 'dados' => ['gerenciadora' => 'Indevido']])->assertForbidden();
        $this->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), ['action' => 'submit'])->assertStatus(422);
        $this->assertSame('rascunho', $rdo->fresh()->status);
        $this->actingAs($users['gerenciadora'])->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->assertSame('arquivado', $rdo->fresh()->status);
    }

    public function test_all_fronts_must_be_filled_before_a_single_party_can_conclude(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['fill_party' => 'gerenciadora', 'review_parties' => []]);
        $second = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => '200', 'nome' => 'Segunda frente', 'tipo' => 'pai']);
        $rdo->configuracao->obras()->attach($second->id);
        $this->fill($tenant, $owner, $obra, $rdo);
        $url = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
        $this->post($url, ['action' => 'submit'])->assertStatus(422);
        $this->fill($tenant, $owner, $second, $rdo);
        $this->post($url, ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->assertSame('arquivado', $rdo->fresh()->status);
    }

    public function test_default_signature_recipients_follow_only_the_participating_parties(): void
    {
        config(['signatures.driver' => 'local']);
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['fill_party' => 'gerenciadora', 'review_parties' => []]);
        foreach (['gerenciadora', 'construtora', 'cliente'] as $party) {
            $user = User::factory()->create();
            $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'member', 'status' => 'active']);
            RdoResponsavel::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $obra->id, 'user_id' => $user->id, 'modulo' => 'rdo', 'etapa' => $party, 'status' => 'active']);
        }
        $this->fill($tenant, $owner, $obra, $rdo);
        $this->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), ['action' => 'submit'])->assertSessionHasNoErrors();
        $request = app(\App\Services\RdoSignatureService::class)->createAndSend($tenant, $rdo->fresh(), $owner);
        $this->assertSame(['gerenciadora'], $request->signers->pluck('role')->all());
        $this->assertGreaterThan(1, $request->request_payload['signature_page']);
        $method = new \ReflectionMethod(\App\Services\Signatures\OpenSignSignatureProvider::class, 'rdoMainPageNumbers');
        $this->assertSame([$request->request_payload['signature_page']], $method->invoke(app(\App\Services\Signatures\OpenSignSignatureProvider::class), $request));
    }

    public function test_notifications_target_the_configured_next_party(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['fill_party' => 'gerenciadora', 'review_parties' => ['cliente']]);
        $users = [];
        foreach (['gerenciadora', 'construtora', 'cliente'] as $party) {
            $user = User::factory()->create(); $users[$party] = $user;
            $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'member', 'status' => 'active']);
            RdoResponsavel::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $obra->id, 'user_id' => $user->id, 'modulo' => 'rdo', 'etapa' => $party, 'status' => 'active']);
        }
        $this->fill($tenant, $owner, $obra, $rdo);
        $url = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
        $this->post($url, ['action' => 'submit'])->assertSessionHasNoErrors();
        Notification::assertSentTo($users['cliente'], \App\Notifications\RdoFlowChangedNotification::class);
        Notification::assertNotSentTo($users['construtora'], \App\Notifications\RdoFlowChangedNotification::class);
        $this->post($url, ['action' => 'return', 'return_to' => 'gerenciadora', 'comments_by_obra' => [$obra->id => 'Corrigir']])->assertSessionHasNoErrors();
        Notification::assertSentTo($users['gerenciadora'], \App\Notifications\RdoFlowChangedNotification::class);
    }

    public function test_client_only_comments_and_cannot_fill_or_decide_in_new_workflows(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo, $payload] = $this->scenario(['fill_parties' => ['gerenciadora'], 'review_parties' => ['cliente'], 'client_comment_only' => false]);
        $this->assertTrue($rdo->workflow_snapshot['client_comment_only']);
        foreach ([['fill_parties' => ['cliente'], 'review_parties' => []], ['fill_party' => 'cliente', 'review_parties' => []]] as $workflow) {
            $this->post(route('tenant.diario-obra.rdo.settings.store', $tenant), [...$payload, 'configuration_id' => $rdo->rdo_configuracao_id, 'workflow' => $workflow])->assertSessionHasErrors();
        }
        $client = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $client->id, 'role' => 'member', 'status' => 'active']);
        RdoResponsavel::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $obra->id, 'user_id' => $client->id, 'modulo' => 'rdo', 'etapa' => 'cliente', 'status' => 'active']);
        $this->actingAs($client)->post(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, 'clima']), ['obra_id' => $obra->id, 'dados' => ['observacoes' => 'Indevido']])->assertForbidden();
        $this->fillParty($tenant, $owner, $obra, $rdo, 'gerenciadora', 'Registro da gerenciadora');
        $url = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
        $this->post($url, ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->actingAs($client)->get(route('tenant.diario-obra.rdo.show', [$tenant, $rdo]))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('rdo.can_edit', false)->where('rdo.flow_actions', ['approve' => ['label' => 'Enviar comentário', 'tone' => 'primary', 'comment_required' => true]]));
        foreach (['return', 'approve_with_reservations'] as $action) $this->postJson($url, ['action' => $action, 'comments_by_obra' => [$obra->id => 'Comentário']])->assertStatus(422);
        $this->post($url, ['action' => 'approve'])->assertStatus(422);
        $this->post($url, ['action' => 'approve', 'comments_by_obra' => [$obra->id => 'Comentário do cliente registrado']])->assertSessionHasNoErrors();
        $this->assertSame('arquivado', $rdo->fresh()->status);
        $this->assertSame('Comentário do cliente registrado', $rdo->analises()->where('etapa', 'cliente')->firstOrFail()->comentario);
        $this->assertSame(0, $rdo->secoes()->where('filling_party', 'cliente')->count());
    }

    public function test_mobile_saves_rdo_directly_with_party_isolation_retries_and_conflicts(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['fill_parties' => ['construtora', 'gerenciadora'], 'review_parties' => ['cliente']]);
        \App\Models\MobileApiToken::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'name' => 'Teste', 'token_hash' => hash('sha256', 'mobile-rdo-test'), 'expires_at' => now()->addDay()]);
        $headers = ['Authorization' => 'Bearer mobile-rdo-test'];
        $bootstrap = $this->getJson(route('api.mobile.bootstrap'), $headers)->assertOk()->assertJsonMissingPath('rda_rdos')->json();
        $this->assertCount(2, $bootstrap['rdo_diarios']);
        $sections = array_fill_keys(['clima', 'mao_obra', 'equipamentos', 'atividades', 'fotos', 'comentarios'], []);
        $sections['clima'] = ['observacoes' => 'MOBILE CONSTRUTORA'];
        $sections['comentarios'] = ['construtora' => 'Observação de campo'];
        $payload = ['local_uuid' => 'rdo-1', 'rdo_diario_id' => $rdo->id, 'obra_id' => $obra->id, 'reference_date' => $rdo->reference_date->format('Y-m-d'), 'filling_party' => 'construtora',
            'revision' => $bootstrap['rdo_diarios'][0]['revision'], 'sections_json' => json_encode($sections)];
        $url = route('api.mobile.rdo.offline.store');
        $response = $this->postJson($url, $payload, $headers)->assertCreated()->assertJsonPath('rdo.id', $rdo->id)->json();
        $this->postJson($url, $payload, $headers)->assertCreated()->assertExactJson($response);
        $this->assertSame(6, $rdo->secoes()->count());
        $this->assertSame(0, \App\Models\RdaApontamento::count());
        $this->postJson($url, [...$payload, 'local_uuid' => 'rdo-stale'], $headers)->assertConflict();
        $this->postJson($url, [...$payload, 'filling_party' => 'gerenciadora'], $headers)->assertConflict();
        $this->fillParty($tenant, $owner, $obra, $rdo, 'gerenciadora', 'GERENCIADORA WEB');
        $this->assertSame('MOBILE CONSTRUTORA', $rdo->secoes()->where('filling_party', 'construtora')->where('secao', 'clima')->firstOrFail()->dados['observacoes']);
        $sections['clima']['observacoes'] = 'MOBILE ATUALIZADO';
        $this->postJson($url, [...$payload, 'local_uuid' => 'rdo-2', 'revision' => $response['rdo']['revision'], 'sections_json' => json_encode($sections)], $headers)->assertCreated();
        $this->assertSame('GERENCIADORA WEB', $rdo->secoes()->where('filling_party', 'gerenciadora')->where('secao', 'clima')->firstOrFail()->dados['observacoes']);
        $this->postJson(route('api.mobile.rda.offline.store'), [], $headers)->assertStatus(410);
        $this->post(route('tenant.diario-obra.rda.store', $tenant), [])->assertStatus(410);
        $this->assertArrayNotHasKey('fill_rda', \App\Support\DiarioObraPermissions::labels());
    }

    public function test_mobile_photo_retry_keeps_a_single_copy_and_blocks_foreign_photo_paths(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['fill_parties' => ['construtora', 'gerenciadora'], 'review_parties' => []]);
        \App\Models\MobileApiToken::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'name' => 'Fotos', 'token_hash' => hash('sha256', 'mobile-photos'), 'expires_at' => now()->addDay()]);
        $headers = ['Authorization' => 'Bearer mobile-photos', 'Accept' => 'application/json'];
        $sections = array_fill_keys(['clima', 'mao_obra', 'equipamentos', 'atividades', 'fotos', 'comentarios'], []);
        $sections['fotos'] = ['arquivos' => [], 'novas_fotos' => [['client_id' => 'camera-1', 'comment' => 'Foto do campo']], 'ordem_fotos' => ['new:camera-1']];
        $payload = ['local_uuid' => 'photo-1', 'rdo_diario_id' => $rdo->id, 'obra_id' => $obra->id, 'reference_date' => $rdo->reference_date->format('Y-m-d'), 'filling_party' => 'construtora',
            'revision' => \App\Http\Controllers\Api\Mobile\MobileRdoController::revision($rdo, $obra->id, 'construtora'), 'sections_json' => json_encode($sections), 'fotos' => [\Illuminate\Http\UploadedFile::fake()->image('camera.jpg')]];
        $url = route('api.mobile.rdo.offline.store');
        $response = $this->post($url, $payload, $headers)->assertCreated()->json();
        $this->post($url, $payload, $headers)->assertCreated()->assertExactJson($response);
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $photo = $rdo->secoes()->where('filling_party', 'construtora')->where('secao', 'fotos')->firstOrFail()->dados['arquivos'][0];
        $this->assertSame('Foto do campo', $photo['comment']);
        $sections['fotos'] = ['arquivos' => [$photo]];
        unset($payload['fotos']);
        $this->post($url, [...$payload, 'local_uuid' => 'photo-forged', 'filling_party' => 'gerenciadora',
            'revision' => \App\Http\Controllers\Api\Mobile\MobileRdoController::revision($rdo, $obra->id, 'gerenciadora'), 'sections_json' => json_encode($sections)], $headers)->assertUnprocessable();
        $this->assertSame(0, $rdo->secoes()->where('filling_party', 'gerenciadora')->count());
    }

    public function test_mobile_rejects_wrong_party_front_tenant_and_locked_diaries(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['fill_parties' => ['construtora', 'gerenciadora'], 'review_parties' => ['cliente']]);
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'member', 'status' => 'active']);
        RdoResponsavel::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $obra->id, 'user_id' => $user->id, 'modulo' => 'rdo', 'etapa' => 'gerenciadora', 'status' => 'active']);
        \App\Models\MobileApiToken::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Teste', 'token_hash' => hash('sha256', 'mobile-manager'), 'expires_at' => now()->addDay()]);
        $headers = ['Authorization' => 'Bearer mobile-manager'];
        $diaries = $this->getJson(route('api.mobile.bootstrap'), $headers)->assertOk()->json('rdo_diarios');
        $this->assertCount(1, $diaries);
        $this->assertSame('gerenciadora', $diaries[0]['filling_party']);
        $sections = array_fill_keys(['clima', 'mao_obra', 'equipamentos', 'atividades', 'fotos', 'comentarios'], []);
        $payload = ['local_uuid' => 'manager-1', 'rdo_diario_id' => $rdo->id, 'obra_id' => $obra->id, 'reference_date' => $rdo->reference_date->format('Y-m-d'), 'filling_party' => 'construtora', 'revision' => $diaries[0]['revision'], 'secoes' => $sections];
        $url = route('api.mobile.rdo.offline.store');
        $this->postJson($url, $payload, $headers)->assertForbidden();
        $this->postJson($url, [...$payload, 'filling_party' => 'cliente'], $headers)->assertUnprocessable();
        $this->postJson($url, [...$payload, 'rdo_diario_id' => 999999], $headers)->assertNotFound();
        $rdo->update(['status' => 'em_aprovacao_cliente']);
        $this->postJson($url, [...$payload, 'filling_party' => 'gerenciadora'], $headers)->assertForbidden();
        $this->assertSame(0, $rdo->secoes()->count());
    }

    public function test_three_models_are_authoritative_and_manager_client_has_real_approval(): void
    {
        foreach (array_keys(RdoWorkflow::MODELS) as $model) {
            [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => $model]);
            $this->assertSame(RdoWorkflow::forModel($model), $rdo->workflow_snapshot);
            $this->assertSame(in_array($model, ['construtora_gerenciadora_cliente', 'gerenciadora_construtora'], true) ? ['construtora', 'gerenciadora'] : ['gerenciadora'], RdoWorkflow::fillParties($rdo));
            if ($model !== 'gerenciadora_cliente') continue;
            $this->fillParty($tenant, $owner, $obra, $rdo, 'gerenciadora', 'Atividades da gerenciadora');
            $url = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
            $this->post($url, ['action' => 'submit'])->assertSessionHasNoErrors();
            $this->assertSame('em_aprovacao_cliente', $rdo->fresh()->status);
            $this->get(route('tenant.diario-obra.rdo.show', [$tenant, $rdo]))->assertInertia(fn (Assert $page) => $page->where('rdo.flow_actions.approve.label', 'Aprovar')->has('rdo.flow_actions.return'));
            $this->post($url, ['action' => 'return', 'return_to' => 'gerenciadora', 'comments_by_obra' => [$obra->id => 'Ajustar atividades']])->assertSessionHasNoErrors();
            $this->assertSame('devolvido_construtora', $rdo->fresh()->status);
            $this->assertStringContainsString('Gerenciadora', RdoWorkflow::statusLabel($rdo->fresh()));
            $this->fillParty($tenant, $owner, $obra, $rdo, 'gerenciadora', 'Atividades ajustadas');
            $this->post($url, ['action' => 'submit', 'comment' => 'Ajustado'])->assertSessionHasNoErrors();
            $this->post($url, ['action' => 'approve', 'comments_by_obra' => [$obra->id => 'Conferido pelo cliente']])->assertSessionHasNoErrors();
            $this->assertSame('arquivado', $rdo->fresh()->status);
        }
    }

    public function test_manager_only_remains_editable_until_explicit_finalization(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'gerenciadora']);
        $second = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => '200', 'nome' => 'Segunda frente', 'tipo' => 'pai']);
        $rdo->configuracao->obras()->attach($second->id);
        $flow = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
        $section = route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, 'comentarios']);
        $this->fillParty($tenant, $owner, $obra, $rdo, 'gerenciadora', 'Primeira frente');
        $this->assertSame('rascunho', $rdo->fresh()->status);
        $this->postJson($flow, ['action' => 'submit'])->assertUnprocessable();
        $this->fillParty($tenant, $owner, $second, $rdo, 'gerenciadora', 'Segunda frente');
        $this->assertSame('rascunho', $rdo->fresh()->status);
        $edit = ['obra_id' => $obra->id, 'dados' => ['gerenciadora' => 'Primeira frente revisada']];
        $this->post($section, $edit)->assertSessionHasNoErrors();
        $this->get(route('tenant.diario-obra.rdo.show', [$tenant, $rdo]))
            ->assertInertia(fn (Assert $p) => $p->where('rdo.can_edit', true)->where('rdo.flow_actions.submit.label', 'Finalizar RDO'));
        $this->post($flow, ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->assertSame('arquivado', $rdo->fresh()->status);
        $this->assertSame('Finalizado', RdoWorkflow::statusLabel($rdo->fresh()));
        $this->assertSame(['submit', 'submit'], $rdo->analises()->pluck('decisao')->all());
        $this->assertSame(1, $rdo->versoes()->count());
        $this->assertSame(['gerenciadora'], RdoWorkflow::parties($rdo->fresh()));
        $this->post($section, $edit)->assertForbidden();
        $this->post(route('tenant.diario-obra.rdo.reopen', [$tenant, $rdo]))->assertUnprocessable();
        $this->post($flow, ['action' => 'submit'])->assertUnprocessable();
    }

    public function test_manager_only_mobile_sync_can_be_retried_and_finalization_blocks_new_edits(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'gerenciadora']);
        \App\Models\MobileApiToken::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'name' => 'Solo', 'token_hash' => hash('sha256', 'mobile-solo'), 'expires_at' => now()->addDay()]);
        $sections = array_fill_keys(['clima', 'mao_obra', 'equipamentos', 'atividades', 'fotos', 'comentarios'], []);
        $sections['comentarios'] = ['gerenciadora' => 'Registro diário concluído'];
        $payload = ['local_uuid' => 'solo-1', 'rdo_diario_id' => $rdo->id, 'obra_id' => $obra->id, 'reference_date' => $rdo->reference_date->format('Y-m-d'), 'filling_party' => 'gerenciadora',
            'revision' => \App\Http\Controllers\Api\Mobile\MobileRdoController::revision($rdo, $obra->id, 'gerenciadora'), 'secoes' => $sections];
        $headers = ['Authorization' => 'Bearer mobile-solo'];
        $url = route('api.mobile.rdo.offline.store');
        $this->postJson($url, $payload, $headers)->assertCreated()->assertJsonPath('rdo.status', 'rascunho');
        $this->postJson(route('api.mobile.rdo.submit', $rdo->id), [], $headers)->assertOk()->assertJsonPath('status', 'arquivado');
        $this->postJson($url, $payload, $headers)->assertCreated();
        $this->postJson($url, [...$payload, 'local_uuid' => 'solo-2'], $headers)->assertForbidden();
        $this->assertSame(['submit'], $rdo->analises()->pluck('decisao')->all());
        $this->assertSame(1, $rdo->versoes()->count());
    }

    public function test_document_configuration_is_per_contract_and_frozen_for_each_diary(): void
    {
        [$tenant, $owner, $contract, $obra, $old, $payload] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $oldSnapshot = $old->pdf_configuration_snapshot;
        $settings = route('tenant.diario-obra.rdo.settings.store', $tenant);
        $document = ['layout' => 'standard', 'title' => 'Diário de serviços da gerenciadora', 'color' => '#1555cc', 'company_name' => 'Consórcio da Gerenciadora'];
        $this->post($settings, [...$payload, 'configuration_id' => $old->rdo_configuracao_id, 'document_configuration' => $document])->assertSessionHasNoErrors();
        $new = app(RdoDailyGenerator::class)->generateForConfiguration($old->configuracao->fresh(), CarbonImmutable::parse($old->reference_date)->addDay(), false, $owner->id);
        $this->assertSame($document['title'], $new->pdf_configuration_snapshot['title']);
        $this->assertSame($oldSnapshot, $old->fresh()->pdf_configuration_snapshot);
        $this->post($settings, [...$payload, 'configuration_id' => $old->rdo_configuracao_id, 'workflow' => ['model' => 'gerenciadora'], 'document_configuration' => [...$document, 'title' => 'Título alterado depois', 'company_name' => 'Outra empresa configurada']])->assertSessionHasNoErrors();
        $this->assertSame('gerenciadora_cliente', $new->fresh()->workflow_snapshot['model']);
        $this->assertSame($document['title'], $new->fresh()->pdf_configuration_snapshot['title']);
        $this->assertSame('Consórcio da Gerenciadora', $new->fresh()->pdf_configuration_snapshot['company_name']);
        $pdf = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $new->fresh());
        $this->assertStringContainsString($document['title'], $pdf->getDomPDF()->getDom()->textContent);
        $this->assertStringContainsString('Consórcio da Gerenciadora', $pdf->getDomPDF()->getDom()->textContent);
        $this->assertStringNotContainsString('Prazo de envio', $pdf->getDomPDF()->getDom()->textContent);
        $this->assertSame(1, substr_count($pdf->getDomPDF()->getDom()->textContent, 'Assinaturas das partes'));
        if ($folder = getenv('RDO_MODEL_CAPTURE_DIR')) {
            if (!is_dir($folder)) mkdir($folder, 0777, true);
            file_put_contents($folder.'/rdo-parametrizado.pdf', $pdf->output());
        }
        $legacy = $old->fresh(); $legacy->pdf_configuration_snapshot = null;
        $this->assertSame('Relatório Diário de Obra', \App\Support\RdoPdfTemplates::forDiary($legacy)['title']);
        $this->post($settings, [...$payload, 'document_configuration' => [...$document, 'layout' => 'unknown']])->assertSessionHasErrors('document_configuration.layout');
        $this->post($settings, [...$payload, 'workflow' => ['model' => 'unknown']])->assertSessionHasErrors('workflow.model');
    }

    public function test_each_model_has_a_read_only_pdf_preview_with_unsaved_document_options(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $before = [RdoDiario::count(), RdoConfiguracao::count(), \App\Models\RdoSecaoRegistro::count(), \App\Models\RdoAnalise::count(), \App\Models\RdoPdfSetting::count(), Storage::disk('public')->allFiles()];
        foreach (array_keys(RdoWorkflow::MODELS) as $model) {
            $payload = ['contract_id' => $contract->id, 'obra_id' => $obra->id, 'model' => $model, 'layout' => 'standard', 'title' => 'Diário de serviços do contrato', 'color' => '#1355bb'];
            $response = $this->post(route('tenant.diario-obra.rdo.settings.preview', $tenant), $payload)->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $preview = app(\App\Services\RdoPdfPreview::class);
            $document = $preview->make($tenant, $contract->fresh(), $model, $payload, $obra, $owner);
            $pdf = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $document, $preview);
            $text = $pdf->getDomPDF()->getDom()->textContent;
            $this->assertStringContainsString($payload['title'], $text);
            $this->assertStringContainsString('Registro Fotográfico', $text);
            $this->assertStringContainsString('Total: 8,50 mm', preg_replace('/\s+/u', ' ', $text));
            $this->assertStringContainsString('Saldo de prazo', $text);
            $this->assertStringContainsString($model === 'gerenciadora' ? 'Registro diário salvo pela gerenciadora' : ($model === 'gerenciadora_construtora' ? 'registros validados pela gerenciadora' : 'Registros conferidos e aprovados pelo cliente'), $text);
            if ($folder = getenv('RDO_PREVIEWS_CAPTURE_DIR')) {
                if (!is_dir($folder)) mkdir($folder, 0777, true);
                file_put_contents($folder.'/'.$model.'.pdf', $response->getContent());
            }
        }
        $this->assertSame($before, [RdoDiario::count(), RdoConfiguracao::count(), \App\Models\RdoSecaoRegistro::count(), \App\Models\RdoAnalise::count(), \App\Models\RdoPdfSetting::count(), Storage::disk('public')->allFiles()]);
    }

    public function test_pdf_previews_enforce_contract_work_scope_and_settings_permission(): void
    {
        [$tenant, $owner, $contract, $obra] = $this->scenario(['model' => 'gerenciadora']);
        $url = route('tenant.diario-obra.rdo.settings.preview', $tenant);
        $payload = ['contract_id' => $contract->id, 'obra_id' => $obra->id, 'model' => 'gerenciadora', 'layout' => 'standard', 'title' => 'RDO', 'color' => '#123456'];
        $other = $tenant->contracts()->create(['code' => 'OTHER', 'name' => 'Outro contrato', 'status' => 'active']);
        $otherWork = $tenant->obras()->create(['contract_id' => $other->id, 'codigo' => '200', 'nome' => 'Outra frente', 'tipo' => 'pai']);
        $this->postJson($url, [...$payload, 'obra_id' => $otherWork->id])->assertNotFound();
        $foreignTenant = Tenant::create(['slug' => 'preview-foreign', 'name' => 'Outro tenant', 'status' => 'active', 'plan' => 'starter']);
        $foreignContract = $foreignTenant->contracts()->create(['code' => 'FOREIGN', 'name' => 'Contrato externo', 'status' => 'active']);
        $this->postJson($url, [...$payload, 'contract_id' => $foreignContract->id])->assertNotFound();
        $this->postJson($url, [...$payload, 'model' => 'invalid'])->assertUnprocessable();
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'member', 'status' => 'active', 'diario_obra_permissions' => [\App\Support\DiarioObraPermissions::VIEW]]);
        $this->actingAs($user)->postJson($url, $payload)->assertForbidden();
    }

    public function test_manager_observations_and_evidence_are_separate_per_front_and_visible_to_client(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'construtora_gerenciadora_cliente']);
        $second = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => '200', 'nome' => 'Frente sul', 'tipo' => 'pai']);
        $rdo->configuracao->obras()->attach($second->id);
        foreach ([$obra, $second] as $front) {
            $this->fillParty($tenant, $owner, $front, $rdo, 'construtora', 'Comentário original da construtora');
            $this->fillParty($tenant, $owner, $front, $rdo, 'gerenciadora', 'Registro de campo da gerenciadora');
        }
        $this->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), ['action' => 'submit'])->assertSessionHasNoErrors();
        $manager = User::factory()->create(); $client = User::factory()->create();
        foreach (['gerenciadora' => $manager, 'cliente' => $client] as $role => $person) {
            $tenant->memberships()->create(['user_id' => $person->id, 'role' => 'member', 'status' => 'active']);
            RdoResponsavel::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $obra->id, 'user_id' => $person->id, 'modulo' => 'rdo', 'etapa' => $role, 'status' => 'active']);
        }
        $url = route('tenant.diario-obra.rdo.inspection.store', [$tenant, $rdo]);
        $image = \Illuminate\Http\UploadedFile::fake()->image('vistoria-frente-norte.png', 300, 180);
        $document = \Illuminate\Http\UploadedFile::fake()->create('laudo-da-frente.pdf', 3, 'application/pdf');
        $payload = ['obra_id' => $obra->id, 'resumo_fiscalizacao' => 'Observação técnica exclusiva da frente norte.', 'status' => 'concluido'];
        $this->actingAs($manager)->post($url, [...$payload, 'evidencias' => [$image, $document]])->assertSessionHasNoErrors();
        $this->postJson($url, [...$payload, 'obra_id' => $second->id])->assertUnprocessable();
        $record = \App\Models\RdoFiscalizacaoRegistro::where('rdo_diario_id', $rdo->id)->where('obra_id', $obra->id)->firstOrFail();
        $this->assertCount(2, $record->evidencias);
        Storage::disk('local')->assertExists(array_column($record->evidencias, 'path'));
        $this->post($url, [...$payload, 'resumo_fiscalizacao' => 'Observações revisadas da frente norte.'])->assertSessionHasNoErrors();
        $this->assertCount(2, $record->fresh()->evidencias);
        $this->actingAs($owner)->post($url, [...$payload, 'obra_id' => $second->id, 'resumo_fiscalizacao' => 'Observações exclusivas da frente sul.'])->assertSessionHasNoErrors();
        $this->assertSame('Comentário original da construtora', $rdo->secoes()->where('obra_id', $obra->id)->where('secao', 'comentarios')->firstOrFail()->dados['construtora']);
        $this->actingAs($client)->get(route('tenant.diario-obra.rdo.show', [$tenant, $rdo]))->assertOk()->assertInertia(fn (Assert $p) => $p->has('rdo.inspection_records', 2)->has('rdo.inspection_records.0.evidence', 2));
        $fileUrl = route('tenant.diario-obra.rdo.inspection.evidence', [$tenant, $rdo, $record, 0]);
        $this->get($fileUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('tenant.diario-obra.rdo.inspection.evidence', [$tenant, $rdo, $record, 1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('tenant.diario-obra.rdo.inspection.evidence', [$tenant, $rdo, $record, 99]))->assertNotFound();
        $outsider = User::factory()->create(); $tenant->memberships()->create(['user_id' => $outsider->id, 'role' => 'member', 'status' => 'active', 'diario_obra_permissions' => []]);
        $this->actingAs($outsider)->get($fileUrl)->assertForbidden();
        $pdf = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $rdo->fresh());
        $text = $pdf->getDomPDF()->getDom()->textContent;
        foreach (['Observações revisadas da frente norte.', 'Observações exclusivas da frente sul.', 'laudo-da-frente.pdf', 'Comentário original da construtora'] as $value) $this->assertStringContainsString($value, $text);
        $this->assertGreaterThan(0, $pdf->getDomPDF()->getDom()->getElementsByTagName('img')->length);
        if ($folder = getenv('RDO_EVIDENCE_CAPTURE_DIR')) { if (!is_dir($folder)) mkdir($folder, 0777, true); file_put_contents($folder.'/rdo-evidencias.pdf', $pdf->output()); }
        $this->actingAs($owner)->post($url, [...$payload, 'evidencias' => array_map(fn ($i) => \Illuminate\Http\UploadedFile::fake()->create('doc'.$i.'.pdf', 1, 'application/pdf'), range(1,9))])->assertSessionHasErrors('evidencias');
        $this->assertCount(2, $record->fresh()->evidencias);
    }

    public function test_manager_activities_and_evidence_order_comments_are_saved_without_legacy_summary(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'construtora_gerenciadora_cliente']);
        $this->fillParty($tenant, $owner, $obra, $rdo, 'construtora', 'Registro original');
        $this->fillParty($tenant, $owner, $obra, $rdo, 'gerenciadora', 'Registro de campo da gerenciadora');
        $this->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), ['action' => 'submit'])->assertSessionHasNoErrors();
        $url = route('tenant.diario-obra.rdo.inspection.store', [$tenant, $rdo]);
        $activities = [['titulo' => 'Inspeção da drenagem', 'ocorrencia' => 'Conferência do assentamento dos tubos.'], ['titulo' => 'Verificação do concreto', 'ocorrencia' => 'Acompanhamento da concretagem da base.']];
        $weather = ['manha' => 'chuvoso', 'tarde' => 'nublado', 'noite' => 'nao_aplicavel', 'precipitacao_manha_mm' => '12.5', 'precipitacao_tarde_mm' => '0', 'dia_impraticavel' => false, 'observacoes' => 'Chuva observada na vistoria da manhã.'];
        $payload = ['obra_id' => $obra->id, 'atividades' => $activities, 'status' => 'concluido', 'comentario' => 'Frente vistoriada sem interferências.', 'clima' => $weather];
        $this->post($url, [...$payload,
            'evidencias' => [\Illuminate\Http\UploadedFile::fake()->image('drenagem.jpg'), \Illuminate\Http\UploadedFile::fake()->create('laudo.pdf', 2, 'application/pdf')],
            'evidencias_metadados' => [['comment' => 'Vista dos tubos.'], ['comment' => 'Laudo do concreto.']],
            'evidencias_ordem' => ['new:1', 'new:0'],
        ])->assertSessionHasNoErrors();
        $record = \App\Models\RdoFiscalizacaoRegistro::where('rdo_diario_id', $rdo->id)->firstOrFail();
        $this->assertSame($activities, $record->atividades);
        $this->assertSame($weather, $record->clima);
        $this->assertSame('Frente vistoriada sem interferências.', $record->comentario);
        $this->assertSame('laudo.pdf', $record->evidencias[0]['name']);
        $this->assertSame('Vista dos tubos.', $record->evidencias[1]['comment']);
        $this->assertStringContainsString('Inspeção da drenagem', $record->resumo_fiscalizacao);
        foreach (['etapa' => ['stage' => 'gerenciadora', 'obra_ids' => [$obra->id]], 'completo' => []] as $name => $export) {
            $pdf = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $rdo->fresh(), null, $export);
            $text = $pdf->getDomPDF()->getDom()->textContent;
            foreach (['Inspeção da drenagem', 'Conferência do assentamento dos tubos.', 'Verificação do concreto', 'Acompanhamento da concretagem da base.', 'Frente vistoriada sem interferências.', 'Condições do tempo — Gerenciadora', 'Noite (N/A)', 'Vista dos tubos.', 'Laudo do concreto.', 'laudo.pdf'] as $value) $this->assertStringContainsString($value, $text);
            foreach (['V01', 'Versão V', 'Serviços atestados', 'Ocorrências constatadas', 'Orientações', 'Observações da gerenciadora', 'Versões do RDO'] as $value) $this->assertStringNotContainsString($value, $text);
            $this->assertSame(1, substr_count($text, 'Conferência do assentamento dos tubos.'));
            $this->assertGreaterThan(0, $pdf->getDomPDF()->getDom()->getElementsByTagName('img')->length);
            if ($folder = getenv('RDO_MANAGER_CAPTURE_DIR')) {
                if (!is_dir($folder)) mkdir($folder, 0777, true);
                file_put_contents($folder.'/gerenciadora-'.$name.'.pdf', $pdf->output());
            }
        }

        $this->post($url, [...$payload, 'evidencias_ordem' => ['existing:1', 'existing:0'], 'evidencias_comentarios' => [1 => 'Comentário revisado.']])->assertSessionHasNoErrors();
        $this->assertSame('Comentário revisado.', $record->fresh()->evidencias[0]['comment']);
        $this->postJson($url, [...$payload, 'evidencias_ordem' => ['existing:99']])->assertUnprocessable();
        $this->postJson($url, [...$payload, 'atividades' => []])->assertUnprocessable();
        $this->postJson($url, [...$payload, 'atividades' => [['titulo' => 'Sem descrição']]])->assertUnprocessable();
        $this->postJson($url, [...$payload, 'clima' => ['manha' => 'invalido']])->assertUnprocessable();
        $this->postJson($url, [...$payload, 'clima' => ['precipitacao_manha_mm' => -1]])->assertUnprocessable();
        $this->post($url, [...$payload, 'evidencias_ordem' => ['existing:0']])->assertSessionHasNoErrors();
        $this->assertCount(1, $record->fresh()->evidencias);
        $this->assertSame('Registro original', $rdo->secoes()->where('secao', 'comentarios')->firstOrFail()->dados['construtora']);
        $this->get(route('tenant.diario-obra.rdo.show', [$tenant, $rdo]))->assertOk()->assertInertia(fn (Assert $p) => $p->has('rdo.inspection_records.0.activities', 2)->where('rdo.inspection_records.0.weather.manha', 'chuvoso')->where('rdo.inspection_records.0.comment', 'Frente vistoriada sem interferências.')->where('rdo.inspection_records.0.evidence.0.comment', 'Comentário revisado.'));
        $this->post($url, [...$payload, 'comentario' => ''])->assertSessionHasNoErrors();
        $this->assertNull($record->fresh()->comentario);
        $withoutWeather = $payload; unset($withoutWeather['clima']);
        $this->post($url, $withoutWeather)->assertSessionHasNoErrors();
        $this->assertSame($weather, $record->fresh()->clima);
        $this->assertSame('Registro original', $rdo->secoes()->where('secao', 'clima')->firstOrFail()->dados['observacoes']);
    }

    public function test_constructor_and_manager_forward_all_fronts_together_only_when_complete(): void
    {
        [$tenant, $owner, $contract, $first, $rdo] = $this->scenario(['model' => 'construtora_gerenciadora_cliente']);
        $second = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => '200', 'nome' => 'Frente sul', 'tipo' => 'pai']);
        $rdo->configuracao->obras()->attach($second->id);
        $url = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
        $this->fillParty($tenant, $owner, $first, $rdo, 'construtora', 'Primeira frente preenchida');
        $this->postJson($url, ['action' => 'submit', 'obra_ids' => [$first->id]])->assertUnprocessable();
        $this->assertSame('rascunho', $rdo->fresh()->status);
        $this->fillParty($tenant, $owner, $second, $rdo, 'construtora', 'Segunda frente preenchida');
        $this->postJson($url, ['action' => 'submit', 'filling_party' => 'gerenciadora'])->assertUnprocessable();
        $this->fillParty($tenant, $owner, $first, $rdo, 'gerenciadora', 'Registro da gerenciadora antes do envio');
        $this->post($url, ['action' => 'submit', 'obra_ids' => [$first->id]])->assertSessionHasNoErrors();
        $this->assertSame('em_analise_gerenciadora', $rdo->fresh()->status);
        $this->assertSame(2, \App\Models\RdoAnalise::where('rdo_diario_id', $rdo->id)->where('decisao', 'submit')->count());
        $this->get(route('tenant.diario-obra.rdo.show', [$tenant, $rdo]))->assertInertia(fn (Assert $p) => $p
            ->where('rdo.fill_party', 'gerenciadora')->where('rdo.can_edit', true)->where('rdo.can_fill', true)
            ->where('rdo.manager_forward_ready', false)
            ->where('rdo.sections.'.$first->id.'.comentarios.gerenciadora', 'Registro da gerenciadora antes do envio'));
        $payload = ['action' => 'approve', 'obra_ids' => [$first->id, $second->id]];
        $this->postJson($url, [...$payload, 'obra_ids' => [$first->id]])->assertUnprocessable();
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertSame(0, \App\Models\RdoAnalise::where('rdo_diario_id', $rdo->id)->where('etapa', 'gerenciadora')->count());
        $this->assertSame('em_analise_gerenciadora', $rdo->fresh()->status);
        $this->post(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, 'equipamentos']), [
            'obra_id' => $second->id, 'filling_party' => 'gerenciadora',
            'dados' => ['observacoes' => 'Equipamento conferido na segunda frente.'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, \App\Models\RdoFiscalizacaoRegistro::where('rdo_diario_id', $rdo->id)->count());
        $this->get(route('tenant.diario-obra.rdo.show', [$tenant, $rdo]))->assertInertia(fn (Assert $p) => $p->where('rdo.manager_forward_ready', true));
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame('em_aprovacao_cliente', $rdo->fresh()->status);
        $this->assertSame(2, \App\Models\RdoAnalise::where('rdo_diario_id', $rdo->id)->where('etapa', 'gerenciadora')->where('decisao', 'approve')->count());
        $this->postJson($url, [...$payload, 'obra_ids' => [$first->id]])->assertUnprocessable();
        $this->assertSame('em_aprovacao_cliente', $rdo->fresh()->status);
    }

    public function test_return_from_manager_creates_divergence_and_returns_diary_atomically(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'construtora_gerenciadora_cliente']);
        $this->fillParty($tenant, $owner, $obra, $rdo, 'construtora', 'Registro preservado');
        $this->fillParty($tenant, $owner, $obra, $rdo, 'gerenciadora', 'Registro de campo da gerenciadora');
        $url = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
        $this->post($url, ['action' => 'submit'])->assertSessionHasNoErrors();
        $divergence = ['topico' => 'Qualidade', 'titulo' => 'Diferença na execução da base', 'versao_construtora' => 'Base concluída.', 'versao_gerenciadora' => 'Regularização pendente no trecho final.'];
        $payload = ['action' => 'return', 'obra_ids' => [$obra->id], 'comments_by_obra' => [$obra->id => $divergence['versao_gerenciadora']], 'divergencia' => $divergence];
        $this->postJson($url, [...$payload, 'divergencia' => [...$divergence, 'titulo' => '']])->assertUnprocessable();
        $this->assertSame('em_analise_gerenciadora', $rdo->fresh()->status);
        $this->assertSame(0, \App\Models\RdoDivergencia::where('rdo_diario_id', $rdo->id)->count());
        $this->postJson($url, [...$payload, 'action' => 'approve'])->assertUnprocessable();
        $this->assertSame(0, \App\Models\RdoDivergencia::where('rdo_diario_id', $rdo->id)->count());
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame('devolvido_construtora', $rdo->fresh()->status);
        $record = \App\Models\RdoDivergencia::where('rdo_diario_id', $rdo->id)->sole();
        $this->assertSame($obra->id, $record->obra_id);
        $this->assertSame($divergence['versao_gerenciadora'], $record->versao_gerenciadora);
        $this->assertSame('aberta', $record->status);
        $this->assertSame('Registro preservado', $rdo->secoes()->where('secao', 'comentarios')->firstOrFail()->dados['construtora']);
    }

    public function test_manager_return_sends_all_fronts_back_and_links_divergence_to_affected_front(): void
    {
        [$tenant, $owner, $contract, $first, $rdo] = $this->scenario(['model' => 'construtora_gerenciadora_cliente']);
        $second = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => '200', 'nome' => 'Frente sul', 'tipo' => 'pai']);
        $rdo->configuracao->obras()->attach($second->id);
        foreach ([$first, $second] as $front) {
            $this->fillParty($tenant, $owner, $front, $rdo, 'construtora', 'Registro da frente '.$front->codigo);
            $this->fillParty($tenant, $owner, $front, $rdo, 'gerenciadora', 'Registro da gerenciadora');
        }
        $url = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
        $this->post($url, ['action' => 'submit'])->assertSessionHasNoErrors();
        $payload = ['action' => 'return', 'obra_ids' => [$first->id, $second->id],
            'comments_by_obra' => [$first->id => 'Corrigir drenagem', $second->id => 'RDO devolvido para correção'],
            'divergencia' => ['obra_id' => $first->id, 'topico' => 'Qualidade', 'titulo' => 'Drenagem incompleta', 'versao_construtora' => 'Concluída.', 'versao_gerenciadora' => 'Trecho incompleto.']];
        $this->postJson($url, [...$payload, 'obra_ids' => [$first->id]])->assertUnprocessable();
        $this->assertSame('em_analise_gerenciadora', $rdo->fresh()->status);
        $this->assertSame(0, \App\Models\RdoDivergencia::where('rdo_diario_id', $rdo->id)->count());
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame('devolvido_construtora', $rdo->fresh()->status);
        $this->assertSame(2, \App\Models\RdoAnalise::where('rdo_diario_id', $rdo->id)->where('etapa', 'gerenciadora')->where('decisao', 'return')->count());
        $this->assertSame($first->id, \App\Models\RdoDivergencia::where('rdo_diario_id', $rdo->id)->sole()->obra_id);
        $this->assertSame(1, (int) $rdo->fresh()->current_version);
        $this->assertSame('Registro da frente '.$second->codigo, $rdo->secoes()->where('obra_id', $second->id)->where('secao', 'comentarios')->firstOrFail()->dados['construtora']);
    }

    public function test_client_return_requires_target_and_reason_reopens_manager_and_notifies_all_parties(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'construtora_gerenciadora_cliente']);
        $people = [];
        foreach (['construtora', 'gerenciadora', 'cliente'] as $party) {
            $user = User::factory()->create(); $people[$party] = $user;
            $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'member', 'status' => 'active']);
            RdoResponsavel::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $obra->id, 'user_id' => $user->id, 'modulo' => 'rdo', 'etapa' => $party, 'status' => 'active']);
        }
        $this->fillParty($tenant, $owner, $obra, $rdo, 'construtora', 'Registro da construtora');
        $this->fillParty($tenant, $owner, $obra, $rdo, 'gerenciadora', 'Registro de campo da gerenciadora');
        $url = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
        $this->post($url, ['action' => 'submit'])->assertSessionHasNoErrors();
        foreach ($people as $user) Notification::assertSentTo($user, \App\Notifications\RdoFlowChangedNotification::class, fn ($notification) => $notification->via($user) === ['database', 'mail']);
        Notification::assertSentTo($owner, \App\Notifications\RdoFlowChangedNotification::class);
        $this->inspect($tenant, $obra, $rdo);
        $approve = ['action' => 'approve', 'comments_by_obra' => [$obra->id => 'Conferido']];
        $this->post($url, $approve)->assertSessionHasNoErrors();
        $this->assertSame('em_aprovacao_cliente', $rdo->fresh()->status);
        $payload = ['action' => 'return', 'return_to' => 'gerenciadora', 'comments_by_obra' => [$obra->id => 'Revisar a conferência técnica']];
        $missingTarget = $payload; unset($missingTarget['return_to']);
        $this->postJson($url, $missingTarget)->assertUnprocessable();
        $this->postJson($url, [...$payload, 'comments_by_obra' => []])->assertUnprocessable();
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame('em_analise_gerenciadora', $rdo->fresh()->status);
        $this->assertSame(1, (int) $rdo->fresh()->current_version);
        $this->assertContains($obra->id, app(\App\Http\Controllers\Tenant\RdoController::class)->flowCapabilities($tenant, $rdo->fresh(), $owner)['flow_obra_ids']);
        $this->post($url, $approve)->assertSessionHasNoErrors();
        $this->assertSame('em_aprovacao_cliente', $rdo->fresh()->status);
        $this->post($url, [...$payload, 'return_to' => 'construtora'])->assertSessionHasNoErrors();
        $this->assertSame('devolvido_construtora', $rdo->fresh()->status);
        $this->assertSame('Registro da construtora', $rdo->secoes()->where('secao', 'comentarios')->firstOrFail()->dados['construtora']);
    }

    public function test_client_can_record_reservations_for_one_front_without_applying_them_to_others(): void
    {
        [$tenant, $owner, $contract, $first, $rdo] = $this->scenario(['model' => 'construtora_gerenciadora_cliente']);
        $second = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => '200', 'nome' => 'Frente sul', 'tipo' => 'pai']);
        $rdo->configuracao->obras()->attach($second->id);
        foreach ([$first, $second] as $obra) $this->fill($tenant, $owner, $obra, $rdo);
        $url = route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]);
        $this->post($url, ['action' => 'submit'])->assertSessionHasNoErrors();
        foreach ([$first, $second] as $obra) $this->inspect($tenant, $obra, $rdo);
        $comments = [$first->id => 'Primeira frente conferida', $second->id => 'Segunda frente conferida'];
        $this->post($url, ['action' => 'approve', 'comments_by_obra' => $comments])->assertSessionHasNoErrors();
        $payload = ['action' => 'approve_with_reservations', 'obra_ids' => [$first->id, $second->id],
            'comments_by_obra' => $comments, 'reservation_obra_ids' => [$first->id],
            'reservations_by_obra' => [$first->id => ['obligation' => 'Comprovar regularização da base', 'responsible' => 'Construtora', 'due_date' => now()->addDays(2)->toDateString()]]];
        $this->postJson($url, [...$payload, 'reservations_by_obra' => []])->assertUnprocessable();
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame('pendente_comprovacao', $rdo->fresh()->status);
        $records = \App\Models\RdoAnalise::where('rdo_diario_id', $rdo->id)->where('etapa', 'cliente')->get()->keyBy('obra_id');
        $this->assertSame('approve_with_reservations', $records[$first->id]->decisao);
        $this->assertSame('approve', $records[$second->id]->decisao);
        $this->assertNull(data_get($records[$second->id]->detalhes, 'reservation'));
        $this->assertSame('Comprovar regularização da base', data_get($records[$first->id]->detalhes, 'reservation.obligation'));
        $calendar = route('tenant.diario-obra.rdo.calendar', [$tenant, 'contract_id' => $contract->id, 'month' => $rdo->reference_date->format('Y-m')]);
        $this->get($calendar)->assertOk()->assertInertia(fn (Assert $p) => $p->where('rdos', function ($rows) use ($rdo) {
            $item = collect($rows)->firstWhere('id', $rdo->id);
            return $item['has_reservations'] === true && $item['reservation_obra_count'] === 1;
        }));
        $rdo->update(['current_version' => 2]);
        $this->get($calendar)->assertOk()->assertInertia(fn (Assert $p) => $p->where('rdos', fn ($rows) => collect($rows)->firstWhere('id', $rdo->id)['has_reservations'] === false));
    }

    public function test_constructor_and_manager_save_multiple_fronts_with_files_atomically(): void
    {
        [$tenant, $owner, $contract, $first, $rdo] = $this->scenario(['model' => 'construtora_gerenciadora_cliente']);
        $second = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => '200', 'nome' => 'Frente sul', 'tipo' => 'pai']);
        $rdo->configuracao->obras()->attach($second->id);
        $sections = array_fill_keys(['clima','mao_obra','equipamentos','atividades','fotos','comentarios'], []);
        $sections['comentarios'] = ['construtora' => 'Primeira obra'];
        $a = ['obra_id' => $first->id, 'secoes' => $sections, 'fotos' => [\Illuminate\Http\UploadedFile::fake()->image('obra-a.jpg')]];
        $sections['comentarios'] = ['construtora' => 'Segunda obra'];
        $b = ['obra_id' => $second->id, 'secoes' => $sections, 'fotos' => [\Illuminate\Http\UploadedFile::fake()->image('obra-b.jpg')]];
        $url = route('tenant.diario-obra.rdo.sections.store-works', [$tenant, $rdo]);
        $this->post($url, ['obras' => [$a, [...$b, 'obra_id' => 999999]]])->assertForbidden();
        $this->assertSame(0, $rdo->secoes()->count());
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->post($url, ['obras' => [$a, $b]])->assertSessionHasNoErrors();
        $this->assertSame(12, $rdo->secoes()->count());
        $this->assertSame('Primeira obra', $rdo->secoes()->where('obra_id', $first->id)->where('secao','comentarios')->first()->dados['construtora']);
        $this->assertSame('Segunda obra', $rdo->secoes()->where('obra_id', $second->id)->where('secao','comentarios')->first()->dados['construtora']);
        $this->assertCount(2, Storage::disk('public')->allFiles());
        foreach ([$first, $second] as $front) $this->fillParty($tenant, $owner, $front, $rdo, 'gerenciadora', 'Registro de campo da gerenciadora');
        $this->post(route('tenant.diario-obra.rdo.flow', [$tenant,$rdo]), ['action' => 'submit'])->assertSessionHasNoErrors();
        $before = Storage::disk('local')->allFiles();
        $inspection = fn ($obra, $title) => ['obra_id' => $obra->id, 'atividades' => [['titulo' => $title, 'ocorrencia' => 'Serviços conferidos em campo.']], 'status' => 'concluido', 'evidencias' => [\Illuminate\Http\UploadedFile::fake()->image($obra->codigo.'.png')]];
        $a = $inspection($first, 'Vistoria da primeira'); $b = $inspection($second, 'Vistoria da segunda');
        $url = route('tenant.diario-obra.rdo.inspection.store-works', [$tenant,$rdo]);
        $this->postJson($url, ['obras' => [$a, [...$b, 'atividades' => []]]])->assertUnprocessable();
        $this->assertSame(0, \App\Models\RdoFiscalizacaoRegistro::where('rdo_diario_id',$rdo->id)->count());
        $this->assertSame($before, Storage::disk('local')->allFiles());
        $this->post($url, ['obras' => [$a,$b]])->assertSessionHasNoErrors();
        $records = \App\Models\RdoFiscalizacaoRegistro::where('rdo_diario_id',$rdo->id)->get()->keyBy('obra_id');
        $this->assertCount(2,$records);
        $this->assertSame('Vistoria da primeira',$records[$first->id]->atividades[0]['titulo']);
        $this->assertSame('Vistoria da segunda',$records[$second->id]->atividades[0]['titulo']);
        $this->assertCount(1,$records[$first->id]->evidencias);
        $this->assertCount(1,$records[$second->id]->evidencias);
    }

    public function test_solo_manager_batch_requires_explicit_finalization_after_saving_all_fronts(): void
    {
        [$tenant,$owner,$contract,$first,$rdo] = $this->scenario(['model'=>'gerenciadora']);
        $second = $tenant->obras()->create(['contract_id'=>$contract->id,'codigo'=>'200','nome'=>'Segunda','tipo'=>'pai']);
        $rdo->configuracao->obras()->attach($second->id);
        $sections = array_fill_keys(['clima','mao_obra','equipamentos','atividades','fotos','comentarios'], []);
        $sections['comentarios']=['gerenciadora'=>'Registro completo'];
        $this->post(route('tenant.diario-obra.rdo.sections.store-works',[$tenant,$rdo]), ['filling_party'=>'gerenciadora','obras'=>[['obra_id'=>$first->id,'secoes'=>$sections],['obra_id'=>$second->id,'secoes'=>$sections]]])->assertSessionHasNoErrors();
        $this->assertSame(12,$rdo->secoes()->count());
        $this->assertSame('rascunho', $rdo->fresh()->status);
        $this->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), ['action' => 'submit'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('arquivado',$rdo->fresh()->status);
    }

    public function test_manager_constructor_model_supports_final_approval_return_and_reservations_without_client(): void
    {
        foreach (['approve','approve_with_reservations','return'] as $action) {
            [$tenant,$owner,$contract,$obra,$rdo] = $this->scenario(['model'=>'gerenciadora_construtora']);
            $this->assertSame(['construtora','gerenciadora'], RdoWorkflow::parties($rdo));
            $people = [];
            foreach (['construtora','gerenciadora','cliente'] as $party) {
                $person = User::factory()->create(); $people[$party] = $person;
                $tenant->memberships()->create(['user_id'=>$person->id,'role'=>'member','status'=>'active']);
                RdoResponsavel::create(['tenant_id'=>$tenant->id,'contract_id'=>$contract->id,'obra_id'=>$obra->id,'user_id'=>$person->id,'modulo'=>'rdo','etapa'=>$party,'status'=>'active']);
            }
            $this->fillParty($tenant,$owner,$obra,$rdo,'construtora','Atividades da construtora');
            $this->fillParty($tenant,$owner,$obra,$rdo,'gerenciadora','Atividades da gerenciadora');
            $url=route('tenant.diario-obra.rdo.flow',[$tenant,$rdo]);
            $this->post($url,['action'=>'submit'])->assertSessionHasNoErrors();
            $this->assertSame('em_analise_gerenciadora',$rdo->fresh()->status);
            $this->inspect($tenant,$obra,$rdo);
            $data=['action'=>$action,'comments_by_obra'=>[$obra->id=>'Serviços conferidos pela gerenciadora']];
            if ($action==='approve_with_reservations') $data += ['reservation_obra_ids'=>[$obra->id], 'reservations_by_obra'=>[$obra->id=>['obligation'=>'Corrigir acabamento','responsible'=>'Construtora','due_date'=>now()->addDay()->toDateString()]]];
            if ($action==='return') $data['divergencia']=['obra_id'=>$obra->id,'topico'=>'Qualidade','titulo'=>'Acabamento incompleto','versao_construtora'=>'Serviço concluído','versao_gerenciadora'=>'Falta acabamento'];
            $this->post($url,$data)->assertSessionHasNoErrors();
            $this->assertSame(match($action){'approve'=>'arquivado','approve_with_reservations'=>'pendente_comprovacao',default=>'devolvido_construtora'},$rdo->fresh()->status);
            Notification::assertNotSentTo($people['cliente'],\App\Notifications\RdoFlowChangedNotification::class);
            Notification::assertSentTo($people['construtora'],\App\Notifications\RdoFlowChangedNotification::class);
            Notification::assertSentTo($people['gerenciadora'],\App\Notifications\RdoFlowChangedNotification::class);
            if ($action==='approve') {
                config(['signatures.driver'=>'local']);
                $signature=app(\App\Services\RdoSignatureService::class)->createAndSend($tenant,$rdo->fresh(),$owner);
                $this->assertSame(['construtora','gerenciadora'],$signature->signers->pluck('role')->all());
            }
            if ($action === 'approve_with_reservations') {
                $this->fillParty($tenant, $owner, $obra, $rdo, 'construtora', 'Acabamento corrigido e conferido em campo.');
                $this->post($url, ['action' => 'submit'])->assertSessionHasNoErrors();
                $this->assertSame('em_analise_gerenciadora', $rdo->fresh()->status);
                $this->assertSame(2, (int) $rdo->fresh()->current_version);
                $this->inspect($tenant, $obra, $rdo);
                $this->post($url, ['action' => 'approve', 'comments_by_obra' => [$obra->id => 'Correções verificadas e aprovadas.']])->assertSessionHasNoErrors();
                $this->assertSame('arquivado', $rdo->fresh()->status);
            }
            if ($action==='return') {
                $divergence=\App\Models\RdoDivergencia::where('rdo_diario_id',$rdo->id)->sole();
                $this->patch(route('tenant.diario-obra.rdo.divergences.update',[$tenant,$rdo,$divergence]),['action'=>'respond','response'=>'Acabamento corrigido e conferido em campo'])->assertSessionHasNoErrors();
                $this->post($url,['action'=>'submit','comment'=>'Acabamento corrigido'])->assertSessionHasNoErrors();
                $this->inspect($tenant,$obra,$rdo);
                $this->patch(route('tenant.diario-obra.rdo.divergences.update',[$tenant,$rdo,$divergence]),['action'=>'conclude','conclusion'=>'Conferido','outcome'=>'resolvida'])->assertSessionHasNoErrors();
                $this->post($url,['action'=>'approve','comments_by_obra'=>[$obra->id=>'Serviço corrigido e aprovado']])->assertSessionHasNoErrors();
                $this->assertSame('arquivado',$rdo->fresh()->status);
            }
        }
    }

    public function test_uploaded_logos_are_previewed_and_frozen_for_existing_and_new_diaries(): void
    {
        [$tenant, $owner, $contract, $obra, $old, $payload] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $contract->update(['starts_at' => today()->startOfYear(), 'ends_at' => today()->endOfYear()]);
        $company = \App\Models\Empresa::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Supervisão', 'sigla' => 'SUP', 'cnpj' => '12.345.678/0001-90', 'tipo_empresa_id' => \Illuminate\Support\Facades\DB::table('tipos_empresa')->where('nome', 'gerenciadora')->value('id'), 'logo_path' => 'empresas/logo-original.png']);
        $original = \Illuminate\Http\UploadedFile::fake()->image('original.png', 100, 25);
        Storage::disk('public')->put('empresas/logo-original.png', file_get_contents($original->getRealPath()));
        $contract->update(['fiscalizadora_empresa_id' => $company->id]);
        $snapshot = $old->pdf_configuration_snapshot; unset($snapshot['logos']); $old->update(['pdf_configuration_snapshot' => $snapshot]);
        $document = ['layout' => 'standard', 'title' => 'Relatório Diário de Obra', 'color' => '#182033'];
        $replacement = \Illuminate\Http\UploadedFile::fake()->image('logo-nova.png', 200, 45);
        $newBytes = file_get_contents($replacement->getRealPath());
        $this->post(route('tenant.diario-obra.rdo.settings.store', $tenant), [...$payload, 'configuration_id' => $old->rdo_configuracao_id, 'document_configuration' => $document, 'logo_gerenciadora' => $replacement])->assertSessionHasNoErrors();
        $oldLogo = $old->fresh()->pdf_configuration_snapshot['logos']['gerenciadora'];
        $this->assertSame(file_get_contents($original->getRealPath()), Storage::disk('local')->get($oldLogo['path']));
        $new = app(RdoDailyGenerator::class)->generateForConfiguration($old->configuracao->fresh(), CarbonImmutable::parse($old->reference_date)->addDay(), false, $owner->id);
        $newLogo = $new->pdf_configuration_snapshot['logos']['gerenciadora'];
        $this->assertSame($newBytes, Storage::disk('local')->get($newLogo['path']));
        $logoUrl = route('tenant.diario-obra.rdo.settings.logo', [$tenant, $contract, 'gerenciadora']);
        $this->get($logoUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $viewer = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $viewer->id, 'role' => 'member', 'status' => 'active', 'diario_obra_permissions' => [\App\Support\DiarioObraPermissions::VIEW]]);
        $this->actingAs($viewer)->get($logoUrl)->assertForbidden();
        $this->actingAs($owner);
        $before = RdoDiario::count();
        $previewResponse = $this->post(route('tenant.diario-obra.rdo.settings.preview', $tenant), ['contract_id' => $contract->id, 'model' => 'gerenciadora_cliente', ...$document, 'logo_cliente' => \Illuminate\Http\UploadedFile::fake()->image('cliente.png', 90, 30)])->assertOk();
        $this->assertSame($before, RdoDiario::count());
        $this->post(route('tenant.diario-obra.rdo.settings.store', $tenant), [...$payload, 'configuration_id' => $old->rdo_configuracao_id, 'document_configuration' => $document, 'remove_logo_gerenciadora' => true])->assertSessionHasNoErrors();
        $this->get($logoUrl)->assertNotFound();
        $this->assertSame($newLogo, $new->fresh()->pdf_configuration_snapshot['logos']['gerenciadora']);
        $this->assertSame($oldLogo, $old->fresh()->pdf_configuration_snapshot['logos']['gerenciadora']);
        $pdf = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $new->fresh());
        $dom = $pdf->getDomPDF()->getDom();
        $this->assertStringNotContainsString('SIGWORKS', strtoupper($dom->textContent));
        $this->assertGreaterThan(0, $dom->getElementsByTagName('img')->length);
        if ($folder = getenv('RDO_EVIDENCE_CAPTURE_DIR')) { if (!is_dir($folder)) mkdir($folder, 0777, true); file_put_contents($folder.'/rdo-cabecalho.pdf', $pdf->output()); file_put_contents($folder.'/rdo-previa-logos.pdf', $previewResponse->getContent()); }
    }

    public function test_pdf_company_selection_is_scoped_to_the_contract_and_captured_at_generation(): void
    {
        [$tenant, $owner, $contract, $obra, $old, $payload] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $company = $tenant->empresas()->create(['contract_id' => $contract->id, 'tipo_empresa_id' => \Illuminate\Support\Facades\DB::table('tipos_empresa')->where('nome', 'gerenciadora')->value('id'), 'nome' => 'Empresa escolhida no escopo', 'cnpj' => '12.345.678/0001-99', 'sigla' => 'ESC']);
        $url = route('tenant.diario-obra.rdo.settings.store', $tenant);
        $this->post($url, [...$payload, 'configuration_id' => $old->rdo_configuracao_id, 'report_company_id' => $company->id])->assertSessionHasNoErrors();
        $config = $old->configuracao->fresh();
        $this->assertSame($company->id, $config->report_company_id);
        $new = app(RdoDailyGenerator::class)->generateForConfiguration($config, CarbonImmutable::parse($old->reference_date)->addDay(), false, $owner->id);
        $this->assertSame($company->nome, $new->pdf_configuration_snapshot['company_name']);
        $other = $tenant->contracts()->create(['code' => 'OTHER', 'name' => 'Outro', 'status' => 'active']);
        $company->update(['contract_id' => $other->id]);
        $this->post($url, [...$payload, 'report_company_id' => $company->id])->assertSessionHasErrors('report_company_id');
        $this->assertSame('Empresa escolhida no escopo', $new->fresh()->pdf_configuration_snapshot['company_name']);
    }

    public function test_pdf_photo_toggle_theme_three_logos_and_flow_signatures_match_the_preview(): void
    {
        [$tenant, $owner, $contract, $obra] = $this->scenario(['model' => 'construtora_gerenciadora_cliente']);
        $preview = app(\App\Services\RdoPdfPreview::class);
        $renderer = app(\App\Services\RdoPdfRenderer::class);
        foreach ([false, true] as $photos) {
            $document = ['layout' => 'standard', 'title' => 'Relatório integrado', 'color' => '#873db8', 'require_photos' => $photos,
                'logos' => array_fill_keys(['gerenciadora', 'construtora', 'cliente'], ['data_uri' => $preview->photo()])];
            $rdo = $preview->make($tenant, $contract, 'construtora_gerenciadora_cliente', $document, $obra, $owner);
            $pdf = $renderer->render($tenant, $rdo, $preview);
            $dom = $pdf->getDomPDF()->getDom();
            if ($photos) $this->assertStringContainsString('Registro Fotográfico', $dom->textContent);
            else $this->assertStringNotContainsString('Registro Fotográfico', $dom->textContent);
            $xpath = new \DOMXPath($dom);
            foreach ($xpath->query('//table[@class="header"][.//div[@class="report-title" and .="Registro Fotográfico"]]') as $header) {
                $this->assertSame($obra->nome, $xpath->evaluate('string(.//td[span="Frente de serviço"]/span[@class="value"])', $header));
            }
            foreach ($xpath->query('//table[@class="header"]') as $header) $this->assertSame(3, $header->getElementsByTagName('img')->length);
            $style = $dom->getElementsByTagName('style')->item(0)->textContent;
            $this->assertGreaterThan(4, substr_count($style, '#873db8'));
            $sections = $dom->getElementsByTagName('section');
            $lastSection = $sections->item($sections->length - 1)->textContent;
            $this->assertStringContainsString('Histórico de análise e aprovação', $lastSection);
            $this->assertStringContainsString('Assinaturas das partes', $lastSection);
            $bytes = $pdf->output();
            $placement = $renderer->signaturePlacement($pdf);
            $this->assertSame('flow_end', $placement['signature_layout']);
            $this->assertSame($pdf->getDomPDF()->getCanvas()->get_page_count(), $placement['signature_page']);
            if ($folder = getenv('RDO_SCOPE_CAPTURE_DIR')) { if (!is_dir($folder)) mkdir($folder, 0777, true); file_put_contents($folder.'/tema-fotos-'.(int) $photos.'.pdf', $bytes); }
        }
    }

    public function test_long_analysis_flow_keeps_the_last_decision_with_the_signatures(): void
    {
        [$tenant, $owner, $contract, $obra] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $preview = app(\App\Services\RdoPdfPreview::class);
        $rdo = $preview->make($tenant, $contract, 'gerenciadora_cliente', ['require_photos' => false], $obra, $owner);
        $records = new \Illuminate\Database\Eloquent\Collection;
        foreach (range(1, 18) as $i) {
            $analysis = clone $rdo->analises->first();
            $analysis->comentario = $i === 18 ? 'ÚLTIMA DECISÃO DO FLUXO' : str_repeat('Serviços conferidos e registros de execução validados para esta frente. ', 3);
            $records->push($analysis);
        }
        $rdo->setRelation('analises', $records);
        $renderer = app(\App\Services\RdoPdfRenderer::class);
        $pdf = $renderer->render($tenant, $rdo, $preview);
        $bytes = $pdf->output();
        $placement = $renderer->signaturePlacement($pdf);
        $this->assertGreaterThan(2, $placement['signature_page']);
        $this->assertSame($pdf->getDomPDF()->getCanvas()->get_page_count(), $placement['signature_page']);
        $this->assertGreaterThan(0, $placement['signature_widgets']['cliente']['y']);
        $this->assertLessThan(790, $placement['signature_widgets']['cliente']['y'] + 34);
        if ($folder = getenv('RDO_SCOPE_CAPTURE_DIR')) { if (!is_dir($folder)) mkdir($folder, 0777, true); file_put_contents($folder.'/fluxo-longo.pdf', $bytes); }
    }

    public function test_report_styles_and_signature_slots_follow_each_workflow_model(): void
    {
        [$tenant, $owner, $contract, $obra, $old, $payload] = $this->scenario(['model' => 'gerenciadora']);
        $preview = app(\App\Services\RdoPdfPreview::class);
        $renderer = app(\App\Services\RdoPdfRenderer::class);
        foreach (array_keys(RdoWorkflow::MODELS) as $index => $model) {
            $theme = array_keys(\App\Support\RdoPdfTemplates::STYLES)[$index % count(\App\Support\RdoPdfTemplates::STYLES)];
            $document = ['layout' => 'standard', 'title' => 'Relatório Diário de Obra', 'theme' => $theme, 'require_photos' => false];
            $rdo = $preview->make($tenant, $contract, $model, $document, $obra, $owner);
            $pdf = $renderer->render($tenant, $rdo, $preview);
            $xpath = new \DOMXPath($pdf->getDomPDF()->getDom());
            $parties = RdoWorkflow::parties($rdo);
            $this->assertSame(count($parties), $xpath->query('//table[@id="rdo-final-signature-table"]//td')->length);
            $bytes = $pdf->output();
            $widgets = $renderer->signaturePlacement($pdf)['signature_widgets'];
            $this->assertSame($parties, array_keys($widgets));
            $previous = 0;
            foreach ($widgets as $widget) {
                $this->assertGreaterThan($previous, $widget['x']);
                $this->assertLessThan(595, $widget['x'] + $widget['w']);
                $previous = $widget['x'];
            }
            if ($folder = getenv('RDO_STYLE_CAPTURE_DIR')) { if (!is_dir($folder)) mkdir($folder, 0777, true); file_put_contents($folder.'/'.$model.'.pdf', $bytes); }
            $settingsDocument = \Illuminate\Support\Arr::except($document, ['require_photos']);
            $this->post(route('tenant.diario-obra.rdo.settings.store', $tenant), [...$payload, 'configuration_id' => $old->rdo_configuracao_id, 'document_configuration' => $settingsDocument])->assertSessionHasNoErrors();
            $this->assertSame($theme, \App\Support\RdoPdfTemplates::forContract($tenant, $contract->id)['theme']);
            $this->post(route('tenant.diario-obra.rdo.settings.preview', $tenant), ['contract_id' => $contract->id, 'model' => $model, ...$document])->assertOk();
        }
        $this->post(route('tenant.diario-obra.rdo.settings.preview', $tenant), ['contract_id' => $contract->id, 'model' => 'gerenciadora', 'layout' => 'standard', 'title' => 'RDO', 'theme' => 'invalid'])->assertSessionHasErrors('theme');
    }

    public function test_catalogs_are_scoped_to_contract_for_management_web_and_mobile(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $other = $tenant->contracts()->create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $catalogs = [
            ['mao-obra', \App\Models\RdoMaoObraCadastro::class, ['descricao' => 'Pedreiro', 'tipo' => 'direta', 'unidade' => 'pessoa'], 'maoObra', 'mao_obra', 'efetivos'],
            ['equipamentos', \App\Models\RdoEquipamentoCadastro::class, ['codigo' => 'EQ1', 'descricao' => 'Escavadeira', 'unidade' => 'un', 'propriedade' => 'proprio'], 'equipamentos', 'equipamentos', 'registros'],
            ['subcontratadas', \App\Models\RdoSubcontratadaCadastro::class, ['razao_social' => 'Empresa', 'cnpj' => '12.345.678/0001-90'], 'subcontratadas', 'mao_obra', 'subcontratadas'],
        ];
        foreach ($catalogs as [$route, $model, $fields, $prop, $section, $key]) {
            $store = route('tenant.diario-obra.rdo.cadastros.'.$route.'.store', $tenant);
            $this->post($store, $fields)->assertSessionHasErrors('contract_id');
            foreach ([$contract, $other] as $destination) $this->post($store, [...$fields, 'contract_id' => $destination->id])->assertSessionHasNoErrors();
            $own = $model::where('contract_id', $contract->id)->firstOrFail();
            $foreign = $model::where('contract_id', $other->id)->firstOrFail();
            $this->get(route('tenant.diario-obra.rdo.cadastros.index', $tenant).'?contract_id='.$contract->id)->assertInertia(fn (Assert $p) => $p->has($prop, 1)->where($prop.'.0.id', $own->id));
            $catalogKey = $route === 'mao-obra' ? 'mao_obra' : $route;
            $this->get(route('tenant.diario-obra.rdo.show', [$tenant, $rdo]))->assertInertia(fn (Assert $p) => $p->has('catalogs.'.$catalogKey, 1)->where('catalogs.'.$catalogKey.'.0.id', $own->id));
            $this->post(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, $section]), ['obra_id' => $obra->id, 'dados' => [$key => [$foreign->id => 1]]])->assertSessionHasErrors($section);
            $this->post(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, $section]), ['obra_id' => $obra->id, 'dados' => [$key => [$own->id => 1]]])->assertSessionHasNoErrors();
            $this->patch(route('tenant.diario-obra.rdo.cadastros.'.$route.'.update', [$tenant, $own]), [...$fields, 'contract_id' => $other->id, 'active' => true])->assertSessionHasErrors('contract_id');
        }
        $bootstrap = \App\Http\Controllers\Api\Mobile\MobileRdoController::bootstrapPayload($tenant, $owner);
        foreach (['rdo_mao_obra', 'rdo_equipamentos', 'rdo_subcontratadas'] as $key) {
            $this->assertCount(1, $bootstrap[$key]);
            $this->assertSame($contract->id, $bootstrap[$key][0]['contract_id']);
        }
    }

    public function test_legacy_catalogs_can_be_assigned_without_losing_existing_rdo_references(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $item = \App\Models\RdoMaoObraCadastro::create(['tenant_id' => $tenant->id, 'descricao' => 'Legado', 'tipo' => 'direta', 'unidade' => 'pessoa', 'active' => true]);
        $rdo->secoes()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'filling_party' => 'gerenciadora', 'secao' => 'mao_obra', 'dados' => ['efetivos' => [$item->id => 2]]]);
        $this->get(route('tenant.diario-obra.rdo.cadastros.index', $tenant).'?contract_id=unassigned')->assertInertia(fn (Assert $p) => $p->has('maoObra', 1));
        $this->post(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, 'mao_obra']), ['obra_id' => $obra->id, 'dados' => ['efetivos' => [$item->id => 3]]])->assertSessionHasNoErrors();
        $foreignTenant = Tenant::create(['slug' => 'other-catalog', 'name' => 'Other', 'status' => 'active', 'plan' => 'starter']);
        $foreignContract = $foreignTenant->contracts()->create(['code' => 'FOREIGN', 'name' => 'Foreign', 'status' => 'active']);
        $fields = ['descricao' => 'Legado', 'tipo' => 'direta', 'unidade' => 'pessoa', 'active' => true];
        $url = route('tenant.diario-obra.rdo.cadastros.mao-obra.update', [$tenant, $item]);
        $this->patch($url, [...$fields, 'contract_id' => $foreignContract->id])->assertSessionHasErrors('contract_id');
        $this->patch($url, [...$fields, 'contract_id' => $contract->id])->assertSessionHasNoErrors();
        $this->assertSame($contract->id, $item->fresh()->contract_id);
        $this->assertSame(3, $rdo->secoes()->where('secao', 'mao_obra')->first()->dados['efetivos'][$item->id]);
        $this->delete(route('tenant.diario-obra.rdo.cadastros.mao-obra.destroy', [$tenant, $item]))->assertSessionHasErrors('cadastro');
    }

    public function test_title_and_report_colors_are_independent_and_preserved_in_diary_snapshots(): void
    {
        [$tenant, $owner, $contract, $obra, $old, $payload] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $document = ['layout' => 'standard', 'title' => 'Relatório Diário de Obra', 'color' => '#25715d', 'title_color' => '#722d58'];
        $settings = route('tenant.diario-obra.rdo.settings.store', $tenant);
        $this->post($settings, [...$payload, 'configuration_id' => $old->rdo_configuracao_id, 'document_configuration' => $document])->assertSessionHasNoErrors();
        $new = app(RdoDailyGenerator::class)->generateForConfiguration($old->configuracao->fresh(), CarbonImmutable::parse($old->reference_date)->addDay(), false, $owner->id);
        $this->assertSame($document['title_color'], $new->pdf_configuration_snapshot['title_color']);
        $this->assertSame($document['color'], $new->pdf_configuration_snapshot['color']);
        $preview = app(\App\Services\RdoPdfPreview::class);
        $rdo = $preview->make($tenant, $contract, 'gerenciadora_cliente', [...$document, 'require_photos' => false], $obra, $owner);
        $pdf = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $rdo, $preview);
        $style = $pdf->getDomPDF()->getDom()->getElementsByTagName('style')->item(0)->textContent;
        $this->assertStringContainsString('color: #722d58', $style);
        $this->assertStringContainsString('background: #25715d', $style);
        if ($folder = getenv('RDO_COLOR_CAPTURE_DIR')) { if (!is_dir($folder)) mkdir($folder, 0777, true); file_put_contents($folder.'/cores-personalizadas.pdf', $pdf->output()); }
        $this->post($settings, [...$payload, 'configuration_id' => $old->rdo_configuracao_id, 'document_configuration' => [...$document, 'title_color' => '#102030', 'color' => '#304050']])->assertSessionHasNoErrors();
        $this->assertSame($document['title_color'], $new->fresh()->pdf_configuration_snapshot['title_color']);
        $previewUrl = route('tenant.diario-obra.rdo.settings.preview', $tenant);
        $this->post($previewUrl, ['contract_id' => $contract->id, 'model' => 'gerenciadora_cliente', ...$document])->assertOk();
        $this->post($previewUrl, ['contract_id' => $contract->id, 'model' => 'gerenciadora_cliente', ...$document, 'title_color' => 'invalid'])->assertSessionHasErrors('title_color');
    }

    public function test_generation_respects_local_time_calendar_and_copies_only_configured_data(): void
    {
        [$tenant, $owner, $contract, $obra, $first] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $config = $first->configuracao;
        $config->update(['copy_previous_day' => true, 'copy_workforce' => true, 'copy_equipment' => false, 'copy_pending_activities' => true, 'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'timezone' => 'America/Manaus']);
        $labor = \App\Models\RdoMaoObraCadastro::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'descricao' => 'Pedreiro', 'tipo' => 'direta', 'unidade' => 'pessoa', 'active' => true]);
        foreach (['mao_obra' => ['efetivos' => [$labor->id => 8], 'observacoes' => 'Apenas ontem'], 'clima' => ['manha' => 'chuvoso'], 'atividades' => ['atividades' => [['titulo' => 'Drenagem', 'status' => 'pendente'], ['titulo' => 'Limpeza', 'status' => 'concluida']]]] as $section => $data) {
            $first->secoes()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'filling_party' => 'gerenciadora', 'secao' => $section, 'dados' => $data]);
        }
        $day = CarbonImmutable::parse($first->reference_date)->addDay()->format('Y-m-d');
        $generator = app(RdoDailyGenerator::class);
        $this->assertSame(0, $generator->generateDue(CarbonImmutable::parse($day.' 00:04:59', 'America/Manaus')->utc()));
        $this->assertSame(1, $generator->generateDue(CarbonImmutable::parse($day.' 00:05:00', 'America/Manaus')->utc()));
        $this->assertSame(0, $generator->generateDue(CarbonImmutable::parse($day.' 08:30:00', 'America/Manaus')->utc()));
        $new = $config->diarios()->latest('id')->first();
        $this->assertSame($first->id, $new->copied_from_rdo_id);
        $this->assertSame(2, $new->secoes()->count());
        $this->assertSame(['efetivos' => [$labor->id => 8]], $new->secoes()->where('secao', 'mao_obra')->first()->dados);
        $this->assertCount(1, $new->secoes()->where('secao', 'atividades')->first()->dados['atividades']);
        $config->update(['generate_on_holidays' => false]);
        $holiday = $generator->generateForConfiguration($config, CarbonImmutable::parse('2027-09-07'));
        $this->assertNotNull($holiday);
        $this->assertNull($holiday->is_holiday);
        $config->update(['generate_on_holidays' => true]);
        $this->assertNull($generator->generateForConfiguration($config, CarbonImmutable::parse('2027-09-07')));
        $config->update(['active' => false]);
        $this->assertNull($generator->generateForConfiguration($config, CarbonImmutable::parse('2027-09-08')));
        $config->update(['active' => true, 'generation_weekdays' => [1, 2, 3, 4, 5]]);
        $this->assertNull($generator->generateForConfiguration($config, CarbonImmutable::parse('2027-09-11')));
        $this->assertNull($generator->generateForConfiguration($config, CarbonImmutable::parse('2028-01-03')));
    }

    public function test_automatic_copy_skips_inactive_catalogs_and_stops_when_disabled(): void
    {
        [$tenant, $owner, $contract, $obra, $first] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $config = $first->configuracao;
        $config->update(['copy_previous_day' => true, 'copy_workforce' => true]);
        $labor = \App\Models\RdoMaoObraCadastro::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'descricao' => 'Inativo', 'tipo' => 'direta', 'unidade' => 'pessoa', 'active' => false]);
        $first->secoes()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'filling_party' => 'gerenciadora', 'secao' => 'mao_obra', 'dados' => ['efetivos' => [$labor->id => 4]]]);
        $date = CarbonImmutable::parse($first->reference_date)->addDay();
        $new = app(RdoDailyGenerator::class)->generateForConfiguration($config, $date);
        $this->assertEmpty($new->secoes()->first()->dados['efetivos']);
        $config->update(['copy_previous_day' => false]);
        $next = app(RdoDailyGenerator::class)->generateForConfiguration($config, $date->addDay());
        $this->assertNull($next->copied_from_rdo_id);
        $this->assertSame(0, $next->secoes()->count());
    }

    public function test_pdf_orders_simultaneous_flow_events_and_uses_only_the_model_parties(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'gerenciadora_cliente']);
        foreach (['PRIMEIRO EVENTO', 'SEGUNDO EVENTO'] as $comment) {
            $rdo->analises()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'user_id' => $owner->id, 'etapa' => 'cliente', 'decisao' => 'approve', 'comentario' => $comment, 'status_anterior' => 'em_aprovacao_cliente', 'status_novo' => 'arquivado', 'created_at' => '2026-10-28 10:00:00']);
        }
        $rdo->setRelation('analises', $rdo->analises()->orderByDesc('id')->get());
        $pdf = app(\App\Services\RdoPdfRenderer::class)->render($tenant, $rdo);
        $dom = $pdf->getDomPDF()->getDom();
        $sections = $dom->getElementsByTagName('section');
        $history = $sections->item($sections->length - 1)->textContent;
        $this->assertLessThan(strpos($history, 'SEGUNDO EVENTO'), strpos($history, 'PRIMEIRO EVENTO'));
        $this->assertStringNotContainsString('Versões apresentadas pela construtora', $history);
        $this->assertStringNotContainsString('<th>Construtora</th>', $dom->saveHTML());
    }

    public function test_document_view_resolves_inactive_and_deleted_referenced_catalogs_without_exposing_other_records(): void
    {
        [$tenant, $owner, $contract, $obra, $rdo] = $this->scenario(['model' => 'gerenciadora_cliente']);
        $labor = \App\Models\RdoMaoObraCadastro::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'descricao' => 'Pedreiro histórico', 'tipo' => 'direta', 'unidade' => 'pessoa', 'active' => false]);
        $unused = \App\Models\RdoMaoObraCadastro::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'descricao' => 'Cadastro não utilizado', 'tipo' => 'direta', 'unidade' => 'pessoa', 'active' => false]);
        $rdo->secoes()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'filling_party' => 'gerenciadora', 'secao' => 'mao_obra', 'dados' => ['efetivos' => [$labor->id => 6]]]);
        $rdo->secoes()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'filling_party' => 'gerenciadora', 'secao' => 'comentarios', 'dados' => ['gerenciadora' => 'Registro preservado']]);
        $labor->delete();
        $this->get(route('tenant.diario-obra.rdo.show', [$tenant, $rdo]))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('catalogs.mao_obra', 0)->has('record_catalogs.mao_obra', 1)
            ->where('record_catalogs.mao_obra.0.descricao', 'Pedreiro histórico')
            ->where('rdo.sections_by_party.gerenciadora.'.$obra->id.'.comentarios.gerenciadora', 'Registro preservado'));
    }

    public function test_settings_reject_duplicated_unknown_or_self_review_parties(): void
    {
        [$tenant, $user, $contract, $obra, $rdo, $payload] = $this->scenario(RdoWorkflow::DEFAULT);
        foreach ([['fill_party' => 'other', 'review_parties' => []], ['fill_party' => 'gerenciadora', 'review_parties' => ['gerenciadora']], ['fill_party' => 'construtora', 'review_parties' => ['cliente', 'cliente']]] as $workflow) {
            $this->post(route('tenant.diario-obra.rdo.settings.store', $tenant), [...$payload, 'configuration_id' => $rdo->rdo_configuracao_id, 'workflow' => $workflow])->assertSessionHasErrors();
        }
        $this->assertSame(RdoWorkflow::DEFAULT, $rdo->configuracao->workflow);
    }
}

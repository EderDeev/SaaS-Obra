<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AiConversation;
use App\Models\AiMonthlyUsage;
use App\Models\BoletimMedicao;
use App\Models\Contract;
use App\Models\FolhaRosto;
use App\Models\GedDocument;
use App\Models\MedicaoItem;
use App\Models\Orcamento;
use App\Models\OrdemServico;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Assistant\AssistantActionResolver;
use App\Services\Assistant\AssistantRetriever;
use App\Support\ActivityPermissions;
use App\Support\BudgetPermissions;
use App\Support\ProjectPermissions;
use App\Support\RncPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TenantAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_retrieval_excludes_other_tenants_unlinked_contracts_restricted_activities_and_private_documents(): void
    {
        [$tenant, $user, $visibleContract] = $this->tenantUserAndContract('assistente-a');
        $hiddenContract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-OCULTO',
            'name' => 'Contrato sem vinculo',
            'status' => 'active',
        ]);
        $otherTenant = Tenant::create([
            'slug' => 'assistente-b',
            'name' => 'Tenant B',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $otherContract = Contract::create([
            'tenant_id' => $otherTenant->id,
            'code' => 'CT-OUTRO-TENANT',
            'name' => 'Contrato de outro tenant',
            'status' => 'active',
        ]);

        Activity::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $visibleContract->id,
            'created_by_id' => $user->id,
            'title' => 'Atividade visivel do agente',
            'visibility' => Activity::VISIBILITY_PUBLIC,
            'status' => 'todo',
            'priority' => 'normal',
        ]);
        Activity::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $visibleContract->id,
            'created_by_id' => User::factory()->create()->id,
            'title' => 'Atividade restrita sigilosa',
            'visibility' => Activity::VISIBILITY_RESTRICTED,
            'status' => 'todo',
            'priority' => 'normal',
        ]);
        Activity::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $hiddenContract->id,
            'created_by_id' => $user->id,
            'title' => 'Atividade de contrato oculto',
            'visibility' => Activity::VISIBILITY_PUBLIC,
            'status' => 'todo',
            'priority' => 'normal',
        ]);
        GedDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $visibleContract->id,
            'uploaded_by_id' => $user->id,
            'title' => 'Documento publico visivel',
            'status' => 'indexed',
            'original_filename' => 'publico.pdf',
            'checksum' => str_repeat('a', 64),
            'original_path' => 'ged/publico.pdf',
            'metadata' => [],
        ]);
        GedDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $visibleContract->id,
            'uploaded_by_id' => User::factory()->create()->id,
            'title' => 'Documento privado sigiloso',
            'status' => 'indexed',
            'original_filename' => 'privado.pdf',
            'checksum' => str_repeat('b', 64),
            'original_path' => 'ged/privado.pdf',
            'metadata' => [
                'permissions' => [
                    'owner_user_id' => 999999,
                    'view' => ['user_ids' => [999999], 'empresa_ids' => []],
                    'edit' => ['user_ids' => [], 'empresa_ids' => []],
                ],
            ],
        ]);
        GedDocument::create([
            'tenant_id' => $otherTenant->id,
            'contract_id' => $otherContract->id,
            'uploaded_by_id' => $user->id,
            'title' => 'Documento do outro tenant',
            'status' => 'indexed',
            'original_filename' => 'outro.pdf',
            'checksum' => str_repeat('c', 64),
            'original_path' => 'ged/outro.pdf',
        ]);

        $serialized = json_encode(app(AssistantRetriever::class)->retrieve($user, $tenant, 'visivel documento atividade'));

        $this->assertStringContainsString('Atividade visivel do agente', $serialized);
        $this->assertStringContainsString('Documento publico visivel', $serialized);
        $this->assertStringNotContainsString('Atividade restrita sigilosa', $serialized);
        $this->assertStringNotContainsString('Atividade de contrato oculto', $serialized);
        $this->assertStringNotContainsString('Documento privado sigiloso', $serialized);
        $this->assertStringNotContainsString('Documento do outro tenant', $serialized);
        $this->assertStringNotContainsString('Contrato sem vinculo', $serialized);
    }

    public function test_budget_retrieval_respects_record_level_access(): void
    {
        [$tenant, $user] = $this->tenantUserAndContract('assistente-budget');
        $otherUser = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $otherUser->id,
            'role' => 'engenheiro_custos',
            'status' => 'active',
            'budget_permissions' => [BudgetPermissions::VIEW, BudgetPermissions::EDIT],
        ]);
        Orcamento::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $user->id,
            'codigo' => 'ORC-VISIVEL',
            'descricao' => 'Orcamento do usuario',
            'categoria' => 'Obras',
            'status' => 'em_elaboracao',
        ]);
        Orcamento::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $otherUser->id,
            'codigo' => 'ORC-PRIVADO',
            'descricao' => 'Orcamento privado de outro usuario',
            'categoria' => 'Obras',
            'status' => 'em_elaboracao',
        ]);

        $serialized = json_encode(app(AssistantRetriever::class)->retrieve($user, $tenant, 'orcamento'));

        $this->assertStringContainsString('ORC-VISIVEL', $serialized);
        $this->assertStringNotContainsString('ORC-PRIVADO', $serialized);
    }

    public function test_general_question_receives_authorized_tenants_contracts_modules_and_permissions(): void
    {
        [$tenant, $user, $visibleContract] = $this->tenantUserAndContract('assistente-contexto');
        Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-NAO-VINCULADO',
            'name' => 'Contrato que nao pode aparecer',
            'status' => 'active',
        ]);
        $secondTenant = Tenant::create([
            'slug' => 'assistente-contexto-2',
            'name' => 'Segundo Tenant Acessivel',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $secondTenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'tenant_owner',
            'status' => 'active',
        ]);
        Contract::create([
            'tenant_id' => $secondTenant->id,
            'code' => 'CT-SEGUNDO-TENANT',
            'name' => 'Contrato autorizado no segundo tenant',
            'status' => 'active',
        ]);

        $sources = app(AssistantRetriever::class)->retrieve(
            $user,
            $tenant,
            'Quantos tenants e contratos tenho e quais modulos posso acessar?'
        );
        $access = collect($sources)->firstWhere('module', 'Acesso');

        $this->assertNotNull($access);
        $this->assertStringContainsString('Tenants acessíveis pela conta: 2', $access['content']);
        $this->assertStringContainsString('Contratos acessíveis em todos os tenants: 2', $access['content']);
        $this->assertStringContainsString('Contratos acessíveis no tenant atual: 1', $access['content']);
        $this->assertStringContainsString($visibleContract->code, $access['content']);
        $this->assertStringContainsString('Atividades', $access['content']);
        $this->assertStringContainsString('Visualizar Atividades', $access['content']);
        $this->assertStringNotContainsString('Contrato que nao pode aparecer', $access['content']);
        $this->assertStringNotContainsString('CT-SEGUNDO-TENANT', $access['content']);
    }

    public function test_workflow_question_receives_help_context_even_without_module_records(): void
    {
        [$tenant, $user] = $this->tenantUserAndContract('assistente-ajuda');

        $sources = app(AssistantRetriever::class)->retrieve(
            $user,
            $tenant,
            'Como funciona a triagem de email e os anexos com OCR?'
        );
        $guide = collect($sources)->firstWhere('title', 'Fluxo de Documentação');

        $this->assertNotNull($guide);
        $this->assertStringContainsString('Triagem', $guide['content']);
        $this->assertStringContainsString('PDFs anexos', $guide['content']);
        $this->assertStringContainsString('OCR', $guide['content']);
    }

    public function test_platform_administration_tutorial_is_only_retrieved_for_platform_admin(): void
    {
        [$tenant, $user] = $this->tenantUserAndContract('assistente-ajuda-plataforma');
        $question = 'Como consultar tenants, planos e o uso APS na administracao da plataforma?';

        $regularSources = app(AssistantRetriever::class)->retrieve($user, $tenant, $question);
        $this->assertNull(collect($regularSources)->firstWhere('title', 'Fluxo de Administração da plataforma'));

        $user->update(['is_platform_admin' => true]);
        $adminSources = app(AssistantRetriever::class)->retrieve($user->fresh(), $tenant, $question);
        $guide = collect($adminSources)->firstWhere('title', 'Fluxo de Administração da plataforma');

        $this->assertNotNull($guide);
        $this->assertStringContainsString('Uso APS', $guide['content']);
        $this->assertStringContainsString('gigabytes', $guide['content']);
    }

    public function test_operational_tutorials_are_retrieved_by_module_intent(): void
    {
        [$tenant, $user, $contract] = $this->tenantUserAndContract('assistente-tutoriais-operacionais');
        RelatorioNaoConformidadeResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $user->id,
            'created_by_id' => $user->id,
            'status' => 'active',
            'permissions' => [RncPermissions::VIEW],
        ]);

        $cases = [
            ['Como abrir e conduzir uma RNC?', 'Fluxo de Qualidade / RNC', ['Nova RNC', 'Notificar', 'evidências']],
            ['Como fazer um orcamento usando SINAPI e SICRO?', 'Fluxo de Orçamentos', ['SINAPI', 'SICRO', 'Novo orçamento']],
            ['Explique o passo a passo do RDA e do RDO offline.', 'Fluxo de Diário de Obra', ['RDA', 'offline', 'assinatura digital']],
            ['Como funciona a medicao simples e controlada?', 'Fluxo de Medição', ['Controlada', 'Simples', 'Folha de Rosto']],
            ['Como funciona uma revisao de projeto?', 'Fluxo de Projetos', ['Submeter projeto', 'Em revisão', 'Lista Mestra']],
            ['Como configurar a triagem de documentos?', 'Fluxo de Documentação', ['Anexos', 'Triagem', 'Lixeira']],
            ['Explique o fluxo de analise de uma OS.', 'Fluxo de Ordem de Serviço', ['rascunho', 'fiscal', 'Medição Controlada']],
        ];

        foreach ($cases as [$question, $title, $expectedParts]) {
            $sources = app(AssistantRetriever::class)->retrieve($user, $tenant, $question);
            $guide = collect($sources)->firstWhere('title', $title);

            $this->assertNotNull($guide, "Guia ausente para: {$question}");
            foreach ($expectedParts as $part) {
                $this->assertStringContainsString($part, $guide['content']);
            }
        }
    }

    public function test_operational_summary_counts_only_visible_activity_and_budget_records(): void
    {
        [$tenant, $user, $contract] = $this->tenantUserAndContract('assistente-resumo');
        $otherUser = User::factory()->create();
        Activity::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'title' => 'Atividade publica contabilizada',
            'visibility' => Activity::VISIBILITY_PUBLIC,
            'status' => 'todo',
            'priority' => 'normal',
        ]);
        Activity::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $otherUser->id,
            'title' => 'Atividade privada fora da contagem',
            'visibility' => Activity::VISIBILITY_RESTRICTED,
            'status' => 'todo',
            'priority' => 'normal',
        ]);
        Orcamento::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $user->id,
            'codigo' => 'ORC-CONTADO',
            'descricao' => 'Orcamento visivel',
            'categoria' => 'Obras',
            'status' => 'em_elaboracao',
        ]);
        Orcamento::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $otherUser->id,
            'codigo' => 'ORC-NAO-CONTADO',
            'descricao' => 'Orcamento privado',
            'categoria' => 'Obras',
            'status' => 'em_elaboracao',
        ]);

        $sources = app(AssistantRetriever::class)->retrieve($user, $tenant, 'Me de um resumo geral do sistema');
        $overview = collect($sources)->firstWhere('title', 'Resumo dos registros que você pode consultar');

        $this->assertNotNull($overview);
        $this->assertStringContainsString('Atividades visíveis: 1', $overview['content']);
        $this->assertStringContainsString('Orçamentos visíveis: 1', $overview['content']);
    }

    public function test_retrieval_combines_service_orders_and_measurements_without_collection_errors(): void
    {
        [$tenant, $user, $contract] = $this->tenantUserAndContract('assistente-comercial');
        OrdemServico::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'codigo' => 'OS-ASSISTENTE-001',
            'titulo' => 'OS para contexto do agente',
            'status' => 'rascunho',
        ]);
        BoletimMedicao::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'codigo' => 'BM-ASSISTENTE-001',
            'sequencial' => 1,
            'periodo' => '2026-08-01',
            'tipo' => 'normal',
            'status' => 'aberto_lancamento',
        ]);

        $sources = app(AssistantRetriever::class)->retrieve($user, $tenant, 'e de a');

        $this->assertTrue(collect($sources)->contains(fn (array $source): bool => $source['module'] === 'Ordem de Serviço'));
        $this->assertTrue(collect($sources)->contains(fn (array $source): bool => $source['module'] === 'Medição'));
    }

    public function test_bulletin_source_contains_items_and_measured_values(): void
    {
        [$tenant, $user, $contract] = $this->tenantUserAndContract('assistente-itens-bm');
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '100',
            'nome' => 'Obra do boletim',
            'tipo' => 'pai',
        ]);
        $bulletin = BoletimMedicao::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'codigo' => 'BM-003',
            'sequencial' => 3,
            'periodo' => '2026-08-01',
            'tipo' => 'normal',
            'status' => 'finalizado',
        ]);
        $highValueItem = MedicaoItem::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'item' => '1.1',
            'item_type' => 'manual',
            'codigo' => 'ITEM-ALTO',
            'descricao' => 'Item de maior valor medido',
            'unidade' => 'UN',
            'quantidade_prevista' => 10,
            'valor_unitario' => 250,
            'valor_total' => 2500,
        ]);
        $lowValueItem = MedicaoItem::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'item' => '1.2',
            'item_type' => 'manual',
            'codigo' => 'ITEM-BAIXO',
            'descricao' => 'Item de menor valor medido',
            'unidade' => 'UN',
            'quantidade_prevista' => 5,
            'valor_unitario' => 100,
            'valor_total' => 500,
        ]);
        $cover = FolhaRosto::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'ordem_servico_id' => null,
            'boletim_medicao_id' => $bulletin->id,
            'created_by_id' => $user->id,
            'codigo' => 'FR-BM-003',
            'sequencial' => 1,
            'comentario' => 'Medição consolidada',
            'status' => 'analisada',
        ]);
        $analysis = $cover->analises()->create([
            'user_id' => $user->id,
            'setor' => 'medicao',
        ]);

        foreach ([[$highValueItem, 10, 2500, 8], [$lowValueItem, 5, 500, 5]] as [$item, $requested, $value, $approved]) {
            $coverItem = $cover->itens()->create([
                'ordem_servico_item_id' => null,
                'medicao_item_id' => $item->id,
                'quantidade_pleiteada' => $requested,
                'valor_pleiteado' => $value,
            ]);
            $coverItem->analises()->create([
                'folha_rosto_analise_id' => $analysis->id,
                'setor' => 'medicao',
                'quantidade_aprovada' => $approved,
            ]);
        }

        $sources = app(AssistantRetriever::class)->retrieve($user, $tenant, 'Qual item teve o maior valor no BM-03?');
        $bulletinSource = collect($sources)->firstWhere('title', 'BM-003');

        $this->assertNotNull($bulletinSource);
        $this->assertStringContainsString('ITEM-ALTO', $bulletinSource['content']);
        $this->assertStringContainsString('Valor medido (P0): R$ 2.000,00', $bulletinSource['content']);
        $this->assertLessThan(
            strpos($bulletinSource['content'], 'ITEM-BAIXO'),
            strpos($bulletinSource['content'], 'ITEM-ALTO')
        );
    }

    public function test_message_endpoint_sends_only_authorized_context_and_persists_answer(): void
    {
        config()->set('services.openai.enabled', true);
        config()->set('services.openai.key', 'test-key');
        config()->set('services.openai.base_url', 'https://api.openai.test/v1');
        [$tenant, $user, $visibleContract] = $this->tenantUserAndContract('assistente-endpoint');
        Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-SEM-ACESSO',
            'name' => 'Contrato secreto',
            'status' => 'active',
        ]);

        Http::fake([
            'api.openai.test/*' => Http::response([
                'model' => 'gpt-test',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => "Sua conta:\n- **O contrato acessível é {$visibleContract->code}.** [S1]\n- *Consulta autorizada.* [S2]\n<related_link>S1</related_link>",
                    ]],
                ]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            ]),
        ]);

        $this->actingAs($user)
            ->postJson(route('tenant.assistant.messages.store', $tenant), [
                'message' => 'Qual contrato eu posso consultar?',
                'page_path' => "/t/{$tenant->slug}/contracts",
                'page_title' => 'Contratos',
            ])
            ->assertOk()
            ->assertJsonPath('assistant_message.role', 'assistant')
            ->assertJsonPath('assistant_message.content', "Sua conta:\nO contrato acessível é {$visibleContract->code}.\nConsulta autorizada.")
            ->assertJsonPath('assistant_message.links.0.id', 'S1')
            ->assertJsonPath('quota.user.used', 120)
            ->assertJsonMissingPath('assistant_message.sources');

        Http::assertSent(function ($request): bool {
            $payload = json_encode($request->data());

            return str_contains($payload, 'CT-VISIVEL')
                && ! str_contains($payload, 'CT-SEM-ACESSO')
                && str_contains($payload, 'CONTEXTO AUTORIZADO');
        });

        $this->assertDatabaseHas('ai_conversations', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('ai_messages', [
            'role' => 'assistant',
            'model' => 'gpt-test',
        ]);
        $this->assertDatabaseHas('ai_monthly_usages', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'input_tokens' => 100,
            'output_tokens' => 20,
            'total_tokens' => 120,
            'requests_count' => 1,
        ]);
    }

    public function test_user_cannot_delete_another_users_conversation(): void
    {
        [$tenant, $user] = $this->tenantUserAndContract('assistente-owner');
        $otherUser = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $otherUser->id,
            'role' => 'engenheiro',
            'status' => 'active',
        ]);
        $conversation = AiConversation::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'title' => 'Conversa privada',
        ]);

        $this->actingAs($otherUser)
            ->deleteJson(route('tenant.assistant.conversations.destroy', [$tenant, $conversation]))
            ->assertNotFound();

        $this->assertDatabaseHas('ai_conversations', ['id' => $conversation->id]);
    }

    public function test_user_monthly_quota_blocks_request_before_openai_call(): void
    {
        config()->set('services.openai.enabled', true);
        config()->set('services.openai.key', 'test-key');
        config()->set('services.openai.user_monthly_token_limit', 1000);
        [$tenant, $user] = $this->tenantUserAndContract('assistente-cota-usuario');
        AiMonthlyUsage::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'period' => now()->startOfMonth()->toDateString(),
            'total_tokens' => 1000,
        ]);
        Http::fake();

        $this->actingAs($user)
            ->postJson(route('tenant.assistant.messages.store', $tenant), ['message' => 'Teste de cota'])
            ->assertStatus(429)
            ->assertJsonPath('quota.allowed', false)
            ->assertJsonPath('quota.exhausted_by', 'user');

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_conversations', 0);
    }

    public function test_tenant_monthly_quota_is_shared_between_users(): void
    {
        config()->set('services.openai.enabled', true);
        config()->set('services.openai.key', 'test-key');
        [$tenant, $user] = $this->tenantUserAndContract('assistente-cota-tenant');
        $tenant->update(['ai_monthly_token_limit' => 1000]);
        $otherUser = User::factory()->create();
        AiMonthlyUsage::create([
            'tenant_id' => $tenant->id,
            'user_id' => $otherUser->id,
            'period' => now()->startOfMonth()->toDateString(),
            'total_tokens' => 1000,
        ]);
        Http::fake();

        $this->actingAs($user)
            ->postJson(route('tenant.assistant.messages.store', $tenant), ['message' => 'Teste de cota'])
            ->assertStatus(429)
            ->assertJsonPath('quota.exhausted_by', 'tenant');

        Http::assertNothingSent();
    }

    public function test_user_custom_quota_cannot_exceed_global_user_ceiling(): void
    {
        config()->set('services.openai.user_monthly_token_limit', 60000);
        [$tenant, $user] = $this->tenantUserAndContract('assistente-cota-maxima-usuario');
        $tenant->memberships()
            ->where('user_id', $user->id)
            ->update(['ai_monthly_token_limit' => 200000]);

        $quota = app(\App\Services\Assistant\AssistantQuotaService::class)->status($tenant, $user);

        $this->assertSame(60000, $quota['user']['limit']);
    }

    public function test_assistant_prepares_authorized_activity_draft_without_creating_record(): void
    {
        config()->set('services.openai.enabled', true);
        config()->set('services.openai.key', 'test-key');
        config()->set('services.openai.base_url', 'https://api.openai.test/v1');
        [$tenant, $user, $contract] = $this->tenantUserAndContract('assistente-rascunho');
        $tenant->memberships()->where('user_id', $user->id)->update([
            'activity_permissions' => [ActivityPermissions::VIEW, ActivityPermissions::CREATE],
        ]);
        $contract->participants()->where('user_id', $user->id)->update([
            'activity_permissions' => [ActivityPermissions::VIEW, ActivityPermissions::CREATE],
        ]);

        Http::fake([
            'api.openai.test/*' => Http::response([
                'model' => 'gpt-test',
                'output_text' => 'Preparei a atividade para sua revisão. <assistant_action>{"type":"draft","draft_type":"activity","fields":{"contract_code":"CT-VISIVEL","title":"Conferir memória de cálculo","description":"Validar os totais apresentados.","category":"measurement","visibility":"restricted","priority":"high","due_date":"2026-08-10"}}</assistant_action>',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
            ]),
        ]);

        $this->actingAs($user)
            ->postJson(route('tenant.assistant.messages.store', $tenant), [
                'message' => 'Prepare uma atividade no CT-VISIVEL para conferir a memória de cálculo.',
            ])
            ->assertOk()
            ->assertJsonPath('assistant_message.content', 'Preparei a atividade para sua revisão.')
            ->assertJsonPath('assistant_message.actions.0.type', 'draft')
            ->assertJsonPath('assistant_message.actions.0.draft_type', 'activity')
            ->assertJsonPath('assistant_message.actions.0.payload.contract_id', (string) $contract->id)
            ->assertJsonPath('assistant_message.actions.0.payload.title', 'Conferir memória de cálculo');

        $this->assertDatabaseCount('activities', 0);
    }

    public function test_action_resolver_rejects_activity_draft_without_create_permission(): void
    {
        [$tenant, $user] = $this->tenantUserAndContract('assistente-rascunho-negado');

        $action = app(AssistantActionResolver::class)->resolve($user, $tenant, [
            'type' => 'draft',
            'draft_type' => 'activity',
            'fields' => [
                'contract_code' => 'CT-VISIVEL',
                'title' => 'Atividade não autorizada',
            ],
        ], []);

        $this->assertNull($action);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_action_resolver_only_navigates_to_retrieved_source(): void
    {
        [$tenant, $user] = $this->tenantUserAndContract('assistente-navegacao');
        $sources = app(AssistantRetriever::class)->retrieve($user, $tenant, 'Onde vejo os contratos?');
        $source = collect($sources)->first();

        $allowed = app(AssistantActionResolver::class)->resolve($user, $tenant, [
            'type' => 'navigate',
            'source_id' => $source['id'],
            'filters' => [
                'contract_code' => 'CT-VISIVEL',
                'status' => 'aberto',
                'search' => 'BM-003',
            ],
        ], $sources);
        $blocked = app(AssistantActionResolver::class)->resolve($user, $tenant, [
            'type' => 'navigate',
            'source_id' => 'S999',
        ], $sources);

        $this->assertSame('navigate', $allowed['type']);
        $this->assertStringStartsWith($source['url'], $allowed['url']);
        $this->assertStringContainsString('contract_id=', $allowed['url']);
        $this->assertStringContainsString('status=aberto', $allowed['url']);
        $this->assertStringContainsString('search=BM-003', $allowed['url']);
        $this->assertNull($blocked);
    }

    public function test_action_resolver_prepares_rnc_and_service_order_forms_without_persisting_them(): void
    {
        [$tenant, $user, $contract] = $this->tenantUserAndContract('assistente-outros-rascunhos');
        $tenant->memberships()->where('user_id', $user->id)->update(['role' => 'tenant_owner']);
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '001',
            'nome' => 'Obra principal',
            'tipo' => 'pai',
        ]);
        $resolver = app(AssistantActionResolver::class);

        $rncAction = $resolver->resolve($user, $tenant, [
            'type' => 'draft',
            'draft_type' => 'rnc',
            'fields' => [
                'contract_code' => $contract->code,
                'obra_code' => $obra->codigo,
                'descricao_problema' => 'Inconsistência encontrada em campo.',
                'gravidade' => 'Grave',
            ],
        ], []);
        $serviceOrderAction = $resolver->resolve($user, $tenant, [
            'type' => 'draft',
            'draft_type' => 'service_order',
            'fields' => [
                'contract_code' => $contract->code,
                'obra_code' => $obra->codigo,
                'titulo' => 'Executar correção do trecho',
            ],
        ], []);

        $this->assertSame('rnc', $rncAction['draft_type']);
        $this->assertSame((string) $obra->id, $rncAction['payload']['obra_id']);
        $this->assertSame('service_order', $serviceOrderAction['draft_type']);
        $this->assertSame((string) $contract->id, $serviceOrderAction['payload']['contract_id']);
        $this->assertDatabaseCount('relatorio_nao_conformidades', 0);
        $this->assertDatabaseCount('ordem_servicos', 0);
    }

    public function test_action_resolver_prepares_partial_rnc_after_contract_is_confirmed(): void
    {
        [$tenant, $user, $contract] = $this->tenantUserAndContract('assistente-rnc-parcial');
        $tenant->memberships()->where('user_id', $user->id)->update(['role' => 'tenant_owner']);
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '001',
            'nome' => 'Obra principal',
            'tipo' => 'pai',
        ]);

        $action = app(AssistantActionResolver::class)->resolve($user, $tenant, [
            'type' => 'draft',
            'draft_type' => 'rnc',
            'fields' => [
                'contract_code' => $contract->code,
            ],
        ], []);

        $this->assertSame('rnc', $action['draft_type']);
        $this->assertSame((string) $obra->id, $action['payload']['obra_id']);
        $this->assertSame('', $action['payload']['descricao_problema']);
        $this->assertDatabaseCount('relatorio_nao_conformidades', 0);
    }

    public function test_unconfigured_assistant_returns_service_unavailable_without_creating_conversation(): void
    {
        config()->set('services.openai.key', null);
        [$tenant, $user] = $this->tenantUserAndContract('assistente-sem-chave');

        $this->actingAs($user)
            ->postJson(route('tenant.assistant.messages.store', $tenant), [
                'message' => 'Teste',
            ])
            ->assertStatus(503);

        $this->assertDatabaseCount('ai_conversations', 0);
    }

    private function tenantUserAndContract(string $slug): array
    {
        $tenant = Tenant::create([
            'slug' => $slug,
            'name' => 'Tenant Assistente',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engenheiro',
            'status' => 'active',
            'activity_permissions' => [ActivityPermissions::VIEW],
            'project_permissions' => [ProjectPermissions::VIEW],
            'budget_permissions' => [BudgetPermissions::VIEW, BudgetPermissions::EDIT],
        ]);
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-VISIVEL',
            'name' => 'Contrato acessivel',
            'status' => 'active',
        ]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'engenheiro',
            'status' => 'active',
            'activity_permissions' => [ActivityPermissions::VIEW],
            'project_permissions' => [ProjectPermissions::VIEW],
        ]);

        return [$tenant, $user, $contract];
    }
}

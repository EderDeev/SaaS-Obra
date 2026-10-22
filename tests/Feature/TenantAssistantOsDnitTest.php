<?php

namespace Tests\Feature;

use App\Models\{Activity, Contract, ContractParticipant, OsDnitHighway, OsDnitOrder, OsDnitProduct, OsDnitResponsible, Tenant, User};
use App\Services\Assistant\{AssistantActionResolver, AssistantRetriever, OpenAiAssistant, OsDnitAssistantContext};
use App\Support\OsDnitPermissions as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TenantAssistantOsDnitTest extends TestCase
{
    use RefreshDatabase;
    private Tenant $tenant;
    private User $user;
    private Contract $contract;
    private Contract $hidden;
    private ContractParticipant $link;
    private OsDnitProduct $product;
    private OsDnitOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->tenant = Tenant::create(['name' => 'Assistente DNIT', 'slug' => 'assistant-dnit', 'plan' => 'starter', 'status' => 'active']);
        $this->user = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $this->user->id, 'role' => 'tenant_admin', 'status' => 'active']);
        $this->contract = $this->tenant->contracts()->create(['code' => 'CT-001', 'name' => 'Contrato visível', 'status' => 'active']);
        $this->hidden = $this->tenant->contracts()->create(['code' => 'CT-OCULTO', 'name' => 'Contrato oculto', 'status' => 'active']);
        $this->link = $this->contract->participants()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'side' => 'client', 'role' => 'team_member', 'status' => 'active', 'os_dnit_permissions' => P::all()]);
        $this->product = OsDnitProduct::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id,
            'code' => '02', 'name' => 'Controle de Obras', 'category' => 'gestao_controle', 'unit' => 'mês', 'frequency' => 'monthly']);
        foreach (['analyst', 'concremat', 'contract_inspector'] as $role) OsDnitResponsible::create(['tenant_id' => $this->tenant->id,
            'contract_id' => $this->contract->id, 'user_id' => $this->user->id, 'role' => $role, 'active' => true]);
        $this->order = $this->order($this->contract, '001/2026', 'Pedido autorizado');
        $this->order($this->hidden, '001/2026', 'SEGREDO-OUTRO-CONTRATO');
    }

    public function test_retrieval_contains_authorized_os_status_and_never_crosses_tenant_contract_or_soft_delete_boundaries(): void
    {
        $foreignTenant = Tenant::create(['name' => 'Externo', 'slug' => 'external-dnit', 'plan' => 'starter', 'status' => 'active']);
        $foreign = $foreignTenant->contracts()->create(['code' => 'EXT', 'name' => 'Outro tenant', 'status' => 'active']);
        $this->order($foreign, '001/2026', 'SEGREDO-OUTRO-TENANT');
        $deleted = $this->order($this->contract, '002/2026', 'SEGREDO-EXCLUIDO');
        $deleted->delete();
        $sources = app(AssistantRetriever::class)->retrieve($this->user, $this->tenant, 'Consulte a OS DNIT 001/2026 no contrato CT-001');
        $content = json_encode($sources, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Pedido autorizado', $content);
        $this->assertStringContainsString('Rascunho', $content);
        $this->assertStringContainsString('Total: 1', $content);
        foreach (['SEGREDO-OUTRO-CONTRATO', 'SEGREDO-OUTRO-TENANT', 'SEGREDO-EXCLUIDO'] as $secret) $this->assertStringNotContainsString($secret, $content);
        $this->assertNotEmpty(collect($sources)->firstWhere('module', 'OS DNIT'));
        $this->link->update(['os_dnit_permissions' => []]);
        $this->assertCount(0, $this->context('Consulte a OS DNIT 001/2026'));
        $this->user->update(['is_platform_admin' => true]);
        $this->link->update(['status' => 'inactive']);
        $this->assertCount(0, $this->context('Consulte a OS DNIT 001/2026'));
    }

    public function test_guides_are_retrieved_within_context_budget_and_cover_flow_pdf_permissions_and_activity_linking(): void
    {
        foreach ([
            ['Como criar uma OS DNIT?', 'OS DNIT: criação e regras do pedido', ['sequencial/ano', 'rascunho nunca enviado']],
            ['Como analisar uma OS DNIT e reprovar no fiscal do contrato?', 'OS DNIT: análise Concremat e decisão do fiscal', ['comentário obrigatório', 'Devolução não libera edição']],
            ['Como baixar o PDF completo OS DNIT?', 'OS DNIT: baixar PDF e consultar o fluxo', ['última página', 'A4']],
            ['Como cadastrar produtos OS DNIT?', 'OS DNIT: produtos e rodovias', ['modal', '15 itens']],
            ['Como funciona o dashboard OS DNIT?', 'OS DNIT: dashboard', ['depois de Produtos', 'não comprova execução']],
            ['Como configurar responsáveis e permissões OS DNIT?', 'OS DNIT: responsáveis, permissões e avisos', ['13 opções', 'Revogar visualizar']],
            ['Como vincular atividade com OS DNIT?', 'Atividades: vínculo com OS DNIT', ['seletor pesquisável', 'mesmo contrato']],
        ] as [$question, $title, $expected]) {
            $guide = collect(app(AssistantRetriever::class)->retrieve($this->user, $this->tenant, $question))->firstWhere('title', $title);
            $this->assertNotNull($guide, $question);
            foreach ($expected as $part) $this->assertStringContainsString($part, $guide['content'], $question);
        }
        $this->assertNotEmpty($this->context('Qual é o fluxo?', '/t/assistant-dnit/os-dnit'));
    }

    public function test_catalog_dashboard_and_responsible_data_respect_independent_permissions(): void
    {
        $this->link->update(['os_dnit_permissions' => [P::VIEW]]);
        $sources = $this->context('Mostre produtos, dashboard e responsáveis OS DNIT');
        $this->assertFalse($sources->contains(fn ($source) => in_array($source['module'], ['OS DNIT · Produtos', 'OS DNIT · Responsáveis'])));
        $this->assertFalse($sources->contains(fn ($source) => str_ends_with($source['url'], '/dashboard')));
        $this->link->update(['os_dnit_permissions' => P::all()]);
        $sources = $this->context('Qual é o produto 02 e quem são os responsáveis OS DNIT?');
        $this->assertStringContainsString('Controle de Obras', $sources->firstWhere('module', 'OS DNIT · Produtos')['content']);
        $this->assertStringContainsString($this->user->name, $sources->firstWhere('module', 'OS DNIT · Responsáveis')['content']);
    }

    public function test_os_draft_is_only_a_validated_form_and_requires_create_permission_and_analyst_role(): void
    {
        $proposal = ['type' => 'draft', 'draft_type' => 'os_dnit', 'fields' => ['contract_code' => 'CT-001', 'highway' => 'BR-364/AC',
            'subject' => 'Pedido preparado', 'km_start' => '4,53', 'items' => [['code' => '02', 'quantity' => '2'], ['code' => 'INEXISTENTE', 'quantity' => '99']]]];
        $resolver = app(AssistantActionResolver::class);
        $action = $resolver->resolve($this->user, $this->tenant, $proposal, []);
        $this->assertSame('os_dnit', $action['draft_type']);
        $this->assertSame(route('tenant.os-dnit.orders.create', $this->tenant), $action['url']);
        $this->assertSame('4.53', $action['payload']['km_start']);
        $this->assertSame([['product_id' => $this->product->id, 'quantity' => '2']], $action['payload']['items']);
        $this->assertArrayNotHasKey('request_number', $action['payload']);
        $this->assertDatabaseCount('os_dnit_orders', 2);
        $this->assertDatabaseCount('os_dnit_order_events', 0);
        $this->link->update(['os_dnit_permissions' => [P::VIEW]]);
        $this->assertNull($resolver->resolve($this->user, $this->tenant, $proposal, []));
        $this->link->update(['os_dnit_permissions' => P::all()]);
        OsDnitResponsible::where('user_id', $this->user->id)->where('role', 'analyst')->update(['active' => false]);
        $this->assertNull($resolver->resolve($this->user, $this->tenant, $proposal, []));
        $this->assertNull($resolver->resolve($this->user, $this->tenant, ['type' => 'approve', 'order_id' => $this->order->id], []));
        Http::assertNothingSent();
    }

    public function test_activity_draft_resolves_os_number_in_the_selected_contract_and_navigation_uses_real_filters(): void
    {
        $resolver = app(AssistantActionResolver::class);
        $action = $resolver->resolve($this->user, $this->tenant, ['type' => 'draft', 'draft_type' => 'activity',
            'fields' => ['contract_code' => 'CT-001', 'category' => 'os_dnit', 'os_number' => '001/2026']], []);
        $this->assertSame((string) $this->order->id, $action['payload']['os_dnit_order_id']);
        $this->assertSame('Pedido autorizado', $action['payload']['os_dnit_order']['subject']);
        $this->assertDatabaseCount('activities', 0);
        $sources = [['id' => 'S1', 'title' => 'OS DNIT', 'url' => route('tenant.os-dnit.orders.index', $this->tenant)]];
        $navigation = ['type' => 'navigate', 'source_id' => 'S1', 'filters' => ['contract_code' => 'CT-001', 'search' => '001/2026', 'status' => 'draft']];
        $action = $resolver->resolve($this->user, $this->tenant, $navigation, $sources);
        parse_str(parse_url($action['url'], PHP_URL_QUERY), $query);
        $this->assertSame('001/2026', $query['q']);
        $this->assertSame((string) $this->contract->id, $query['contract_id']);
        $navigation['filters']['contract_code'] = 'CT-OCULTO';
        $this->assertNull($resolver->resolve($this->user, $this->tenant, $navigation, $sources));
    }

    public function test_openai_prompt_distinguishes_dnit_drafts_from_service_releases_and_linked_activities(): void
    {
        config(['services.openai.key' => 'test-key', 'services.openai.base_url' => 'https://api.openai.com/v1']);
        Http::fake(['api.openai.com/*' => Http::response(['output_text' => 'Proposta para revisão.', 'usage' => []])]);
        app(OpenAiAssistant::class)->respond('Prepare uma OS DNIT no contrato CT-001', []);
        Http::assertSent(fn ($request) => str_contains($request['input'][0]['content'], '"draft_type":"os_dnit"')
            && str_contains($request['input'][0]['content'], 'Nunca aprove, reprove'));
        app(OpenAiAssistant::class)->respond('Prepare uma atividade vinculada à OS DNIT 001/2026', []);
        Http::assertSent(fn ($request) => str_contains($request['input'][0]['content'], '"draft_type":"activity"')
            && str_contains($request['input'][0]['content'], '"os_number"'));
    }

    private function context(string $question, ?string $path = null): \Illuminate\Support\Collection
    {
        return app(OsDnitAssistantContext::class)->sources($this->user, $this->tenant, $question, $path);
    }

    private function order(Contract $contract, string $number, string $subject): OsDnitOrder
    {
        $road = OsDnitHighway::firstOrCreate(['tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'name' => 'BR-364/AC'], ['active' => true]);
        return OsDnitOrder::create(['tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'highway_id' => $road->id, 'created_by_id' => $this->user->id,
            'request_number' => $number, 'supervision_lot' => '01', 'highway_name' => $road->name, 'km_start' => 1, 'km_end' => 2, 'subject' => $subject,
            'desired_deadline' => '2026-12-01', 'technical_need' => 'Necessidade técnica', 'justification' => 'Justificativa']);
    }
}

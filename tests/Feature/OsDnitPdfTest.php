<?php

namespace Tests\Feature;

use App\Models\{Contract, ContractParticipant, OsDnitHighway, OsDnitOrder, OsDnitProduct, OsDnitResponsible, Tenant, User};
use App\Services\OsDnitPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OsDnitPdfTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $tenant = Tenant::create(['name' => 'Empresa PDF', 'slug' => 'pdf-test', 'plan' => 'starter', 'status' => 'active']);
        $user = User::factory()->create(['name' => 'Responsável de teste']);
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $contract = Contract::create(['tenant_id' => $tenant->id, 'code' => 'CT-PDF', 'name' => 'Supervisão de rodovias', 'status' => 'active']);
        ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'side' => 'client', 'role' => 'manager', 'status' => 'active']);
        foreach (['analyst', 'concremat', 'contract_inspector'] as $role) OsDnitResponsible::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'role' => $role, 'active' => true]);
        $highway = OsDnitHighway::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'name' => 'BR-307/AC', 'active' => true]);
        $product = OsDnitProduct::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'code' => '18E', 'name' => 'Levantamento em ponto localizado', 'unit' => 'unid', 'category' => 'levantamentos_estudos', 'frequency' => 'on_demand']);
        $extra = OsDnitProduct::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'code' => '18D', 'name' => 'Levantamento topográfico', 'unit' => 'm²', 'category' => 'levantamentos_estudos', 'frequency' => 'on_demand']);
        $this->actingAs($user)->post(route('tenant.os-dnit.orders.store', $tenant), [
            'contract_id' => $contract->id, 'highway_id' => $highway->id, 'supervision_lot' => 'Lote 01', 'km_start' => '4.760', 'km_end' => '4.980',
            'subject' => 'Levantamento topográfico', 'desired_deadline' => '2026-10-31', 'technical_need' => 'Pedido original preservado.',
            'justification' => 'Apoiar a fiscalização.', 'sei_reference' => 'SEI-PDF', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        return [$tenant, $user, $contract, OsDnitOrder::sole(), $extra];
    }

    private function action(Tenant $tenant, OsDnitOrder $order, string $action, array $payload = []): void
    {
        $this->post(route('tenant.os-dnit.orders.'.$action, [$tenant, $order]), ['version' => $order->fresh()->workflow_version, ...$payload])->assertSessionHasNoErrors();
    }

    public function test_pdf_download_is_private_and_drafts_do_not_claim_authorization_or_approval(): void
    {
        [$tenant, $user, $contract, $order] = $this->context();
        $data = app(OsDnitPdfRenderer::class)->data($tenant, $order);
        $html = view('pdf.os-dnit', $data)->render();
        $this->assertStringContainsString('execução ainda não foi autorizada', $html);
        $this->assertStringContainsString('Produtos solicitados', $html);
        $this->assertStringNotContainsString('Fica autorizada a execução', $html);
        $this->assertStringNotContainsString('Serviços autorizados', $html);
        $before = [$order->fresh()->workflow_version, $order->events()->count()];
        $response = $this->get(route('tenant.os-dnit.orders.pdf', [$tenant, $order]))->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($before, [$order->fresh()->workflow_version, $order->events()->count()]);
    }

    public function test_approved_pdf_keeps_original_and_reviewed_scopes_and_all_return_reasons(): void
    {
        [$tenant, $user, $contract, $order, $extra] = $this->context();
        $this->action($tenant, $order, 'submit');
        $proposal = ['mode' => 'forward', 'review_comment' => 'Primeira análise técnica.', 'estimated_deadline' => '2026-11-04', 'items' => [['product_id' => $extra->id, 'quantity' => 1500]]];
        $this->action($tenant, $order, 'review', $proposal);
        $this->action($tenant, $order, 'decide', ['decision' => 'return', 'return_to' => 'concremat', 'comment' => 'Rever área e prazo.']);
        $this->action($tenant, $order, 'review', [...$proposal, 'review_comment' => 'Análise final revisada.', 'estimated_deadline' => '2026-11-02', 'items' => [['product_id' => $extra->id, 'quantity' => 1250]]]);
        $this->action($tenant, $order, 'decide', ['decision' => 'approve', 'comment' => 'Aprovo somente a área revisada.']);
        $extra->update(['name' => 'Catálogo alterado depois da aprovação']);
        $data = app(OsDnitPdfRenderer::class)->data($tenant, $order->fresh());
        $this->assertTrue($data['approved']);
        $this->assertSame('1250.000', $data['summaryItems'][0]['quantity']);
        $this->assertSame('1.000', $data['requested'][0]['quantity']);
        $this->assertCount(2, $data['comparison']);
        $this->assertSame(['Retirado', 'Incluído'], array_column($data['comparison'], 'label'));
        $this->assertSame('2026-11-02', $data['reviewedDeadline']);
        $html = view('pdf.os-dnit', $data)->render();
        foreach (['Fica autorizada a execução', 'Pedido original preservado.', 'Primeira análise técnica.', 'Rever área e prazo.', 'Análise final revisada.', 'Aprovo somente a área revisada.', '1.500', '1.250', '04/11/2026', '02/11/2026'] as $text) $this->assertStringContainsString($text, $html);
        $this->assertStringNotContainsString('Catálogo alterado depois', $html);
        $response = $this->get(route('tenant.os-dnit.orders.pdf', [$tenant, $order]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_pdf_requires_tenant_access_and_active_contract_membership_even_for_admins(): void
    {
        [$tenant, $user, $contract, $order] = $this->context();
        $url = route('tenant.os-dnit.orders.pdf', [$tenant, $order]);
        $outsider = User::factory()->create(['is_platform_admin' => true]);
        $tenant->memberships()->create(['user_id' => $outsider->id, 'role' => 'tenant_admin', 'status' => 'active']);
        $this->actingAs($outsider)->get($url)->assertNotFound();
        ContractParticipant::where('user_id', $user->id)->update(['status' => 'inactive']);
        $this->actingAs($user)->get($url)->assertNotFound();
        ContractParticipant::where('user_id', $user->id)->update(['status' => 'active']);
        $other = Tenant::create(['name' => 'Outro tenant', 'slug' => 'foreign-pdf', 'plan' => 'starter', 'status' => 'active']);
        $other->memberships()->create(['user_id' => $user->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $this->get(route('tenant.os-dnit.orders.pdf', [$other, $order]))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_user_text_is_escaped_and_the_approved_summary_uses_the_decision_snapshot(): void
    {
        [$tenant, $user, $contract, $order, $extra] = $this->context();
        $this->action($tenant, $order, 'submit');
        $this->action($tenant, $order, 'review', ['mode' => 'forward', 'review_comment' => '<img src="https://invalid.test/secret">', 'estimated_deadline' => '2026-11-02', 'items' => [['product_id' => $extra->id, 'quantity' => 1250]]]);
        $this->action($tenant, $order, 'decide', ['decision' => 'approve', 'comment' => '<script>bad()</script>']);
        $order->update(['review_items' => [], 'estimated_deadline' => '2026-12-31', 'review_comment' => 'Mutable data']);
        $data = app(OsDnitPdfRenderer::class)->data($tenant, $order->fresh());
        $this->assertSame('1250.000', $data['summaryItems'][0]['quantity']);
        $this->assertSame('2026-11-02', $data['reviewedDeadline']);
        $html = view('pdf.os-dnit', $data)->render();
        $this->assertStringContainsString('&lt;script&gt;bad()', $html);
        $this->assertStringNotContainsString('<script>bad()', $html);
        $this->assertStringNotContainsString('<img src="https://invalid.test', $html);
    }

    public function test_download_variants_are_cumulative_with_one_header_per_stage_and_flow_last(): void
    {
        [$tenant, $user, $contract, $order, $extra] = $this->context();
        $this->action($tenant, $order, 'submit');
        $this->action($tenant, $order, 'review', ['mode' => 'forward', 'review_comment' => 'PARECER_CONCREMAT', 'estimated_deadline' => '2026-11-02', 'items' => [['product_id' => $extra->id, 'quantity' => 1250]]]);
        $this->action($tenant, $order, 'decide', ['decision' => 'approve', 'comment' => 'DECISAO_FISCAL']);
        foreach (array_keys(OsDnitPdfRenderer::SCOPES) as $index => $scope) {
            $data = app(OsDnitPdfRenderer::class)->data($tenant, $order->fresh(), $scope);
            $html = view('pdf.os-dnit', $data)->render();
            $this->assertSame($index + 1, substr_count($html, '<div class="stage-heading">'));
            $this->assertStringContainsString('Pedido original preservado.', $html);
            $this->assertLessThan(strpos($html, 'Justificativa / resultado esperado'), strpos($html, 'Descrição da necessidade técnica'));
            $this->assertLessThan(strpos($html, 'Produtos solicitados'), strpos($html, 'Justificativa / resultado esperado'));
            $this->assertSame($scope !== 'analyst', str_contains($html, 'PARECER_CONCREMAT'));
            $this->assertSame(in_array($scope, ['inspector', 'complete'], true), str_contains($html, 'DECISAO_FISCAL'));
            $this->assertSame($scope === 'complete', str_contains($html, 'Histórico das movimentações'));
            if ($scope === 'complete') $this->assertGreaterThan(strpos($html, 'DECISAO_FISCAL'), strpos($html, 'Histórico das movimentações'));
            $this->assertSame($index + 1, substr_count($html, 'Referência SEI / processo'));
            $response = $this->get(route('tenant.os-dnit.orders.pdf', [$tenant, $order, 'scope' => $scope]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertStringContainsString(OsDnitPdfRenderer::SCOPES[$scope].'.pdf', $response->headers->get('Content-Disposition'));
        }
        $this->get(route('tenant.os-dnit.orders.pdf', [$tenant, $order, 'scope' => 'invalid']))->assertSessionHasErrors('scope');
        ContractParticipant::where('user_id', $user->id)->update(['status' => 'inactive']);
        foreach (array_keys(OsDnitPdfRenderer::SCOPES) as $scope) $this->get(route('tenant.os-dnit.orders.pdf', [$tenant, $order, 'scope' => $scope]))->assertNotFound();
    }

    public function test_inspector_section_preserves_the_reason_and_scope_of_the_last_return(): void
    {
        [$tenant, $user, $contract, $order, $extra] = $this->context();
        $this->action($tenant, $order, 'submit');
        $proposal = ['mode' => 'forward', 'review_comment' => 'Área inicial.', 'estimated_deadline' => '2026-11-04', 'items' => [['product_id' => $extra->id, 'quantity' => 1500]]];
        $this->action($tenant, $order, 'review', $proposal);
        $this->action($tenant, $order, 'decide', ['decision' => 'return', 'return_to' => 'concremat', 'comment' => 'Ajustar o levantamento.']);
        $this->action($tenant, $order, 'review', [...$proposal, 'review_comment' => 'Área revisada.', 'items' => [['product_id' => $extra->id, 'quantity' => 1250]]]);
        $data = app(OsDnitPdfRenderer::class)->data($tenant, $order->fresh(), 'inspector');
        $this->assertSame('1500.000', $data['decisionItems'][0]['quantity']);
        $this->assertSame('1250.000', $data['reviewed'][0]['quantity']);
        $html = view('pdf.os-dnit', $data)->render();
        foreach (['Motivo da reprovação', 'Ajustar o levantamento.', 'novas movimentações após esta decisão', '1.500', '1.250'] as $text) $this->assertStringContainsString($text, $html);
        $this->assertStringNotContainsString('Fica autorizada a execução', $html);
    }
}

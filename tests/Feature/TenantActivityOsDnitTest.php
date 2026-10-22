<?php

namespace Tests\Feature;

use App\Models\{Activity, Contract, ContractParticipant, OsDnitHighway, OsDnitOrder, Tenant, User};
use App\Support\OsDnitPermissions as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantActivityOsDnitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Contract $contract;
    private Contract $other;
    private ContractParticipant $link;
    private OsDnitOrder $order;
    private OsDnitOrder $otherOrder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Atividades DNIT', 'slug' => 'activity-dnit', 'plan' => 'starter', 'status' => 'active']);
        $this->user = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $this->user->id, 'role' => 'tenant_admin', 'status' => 'active']);
        foreach (['contract', 'other'] as $key) {
            $this->$key = $this->tenant->contracts()->create(['code' => $key, 'name' => $key, 'status' => 'active']);
            $link = $this->$key->participants()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
                'side' => 'client', 'role' => 'team_member', 'status' => 'active', 'os_dnit_permissions' => [P::VIEW]]);
            if ($key === 'contract') $this->link = $link;
        }
        $this->order = $this->order($this->contract, '001/2026', 'Levantamento topográfico');
        $this->otherOrder = $this->order($this->other, '001/2026', 'Outro contrato');
        $this->actingAs($this->user);
    }

    public function test_search_is_scoped_to_contract_and_searches_number_and_subject_with_bounded_results(): void
    {
        $this->getJson($this->search(['q' => 'TOPográFICO']))->assertOk()->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $this->order->id)->assertJsonPath('has_more', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($this->search(['q' => '001/2026']))->assertOk()->assertJsonCount(1, 'orders');
        $this->getJson($this->search(['q' => 'ausente']))->assertOk()->assertJsonCount(0, 'orders');
        $deleted = $this->order($this->contract, '002/2026', 'Excluída');
        $deleted->delete();
        $this->getJson($this->search(['q' => 'Excluída']))->assertOk()->assertJsonCount(0, 'orders');
        for ($number = 3; $number <= 24; $number++) $this->order($this->contract, sprintf('%03d/2026', $number), 'Serviço '.$number);
        $this->getJson($this->search())->assertOk()->assertJsonCount(20, 'orders')->assertJsonPath('has_more', true);
    }

    public function test_create_edit_and_clear_reference_preserve_the_selected_order_and_show_it_in_activity_details(): void
    {
        $this->post(route('tenant.activities.store', $this->tenant), $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $activity = Activity::sole();
        $this->assertSame($this->order->id, $activity->osDnitOrder->id);
        $this->get(route('tenant.activities.index', $this->tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('activities.0.os_dnit_order_id', $this->order->id)->where('activities.0.os_dnit_order.request_number', '001/2026'));
        $replacement = $this->order($this->contract, '002/2026', 'Projeto executivo');
        $this->patch(route('tenant.activities.update', [$this->tenant, $activity]), [...$this->payload(), 'os_dnit_order_id' => $replacement->id])->assertSessionHasNoErrors();
        $this->assertSame($replacement->id, $activity->fresh()->os_dnit_order_id);
        $this->patch(route('tenant.activities.update', [$this->tenant, $activity]), [...$this->payload(), 'category' => 'service_order'])->assertSessionHasNoErrors();
        $this->assertNull($activity->fresh()->os_dnit_order_id);
    }

    public function test_missing_foreign_contract_foreign_tenant_and_deleted_orders_are_rejected_on_create_and_edit(): void
    {
        $foreignTenant = Tenant::create(['name' => 'Outro tenant', 'slug' => 'other-dnit', 'plan' => 'starter', 'status' => 'active']);
        $foreignContract = $foreignTenant->contracts()->create(['code' => 'foreign', 'name' => 'foreign', 'status' => 'active']);
        $foreign = $this->order($foreignContract, '001/2026', 'Privada');
        $deleted = $this->order($this->contract, '002/2026', 'Excluída');
        $deleted->delete();
        foreach ([null, $this->otherOrder->id, $foreign->id, $deleted->id] as $id) {
            $this->post(route('tenant.activities.store', $this->tenant), [...$this->payload(), 'os_dnit_order_id' => $id])->assertSessionHasErrors('os_dnit_order_id');
        }
        $this->assertDatabaseCount('activities', 0);
        $this->post(route('tenant.activities.store', $this->tenant), $this->payload())->assertSessionHasNoErrors();
        $activity = Activity::sole();
        $this->patch(route('tenant.activities.update', [$this->tenant, $activity]), [...$this->payload(), 'os_dnit_order_id' => $this->otherOrder->id])->assertSessionHasErrors('os_dnit_order_id');
        $this->assertSame($this->order->id, $activity->fresh()->os_dnit_order_id);
        $this->getJson($this->search(['contract_id' => $foreignContract->id]))->assertNotFound();
    }

    public function test_revoked_permission_or_inactive_participation_blocks_lookup_linking_and_order_details(): void
    {
        $this->post(route('tenant.activities.store', $this->tenant), $this->payload())->assertSessionHasNoErrors();
        $activity = Activity::sole();
        $this->link->update(['os_dnit_permissions' => []]);
        $this->getJson($this->search())->assertForbidden();
        $this->post(route('tenant.activities.store', $this->tenant), $this->payload())->assertSessionHasErrors('os_dnit_order_id');
        $this->patch(route('tenant.activities.update', [$this->tenant, $activity]), $this->payload())->assertSessionHasErrors('os_dnit_order_id');
        $this->get(route('tenant.activities.index', $this->tenant))->assertOk()->assertInertia(fn (Assert $page) => $page->where('activities.0.os_dnit_order', null));
        $this->link->update(['os_dnit_permissions' => [P::VIEW], 'status' => 'inactive']);
        $this->getJson($this->search())->assertForbidden();
        $this->assertDatabaseCount('activities', 1);
    }

    private function order(Contract $contract, string $number, string $subject): OsDnitOrder
    {
        $road = OsDnitHighway::firstOrCreate(['tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'name' => 'BR-364/AC'], ['active' => true]);
        return OsDnitOrder::create(['tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'highway_id' => $road->id,
            'created_by_id' => $this->user->id, 'request_number' => $number, 'supervision_lot' => '01', 'highway_name' => $road->name,
            'km_start' => 1, 'km_end' => 2, 'subject' => $subject, 'desired_deadline' => '2026-12-01', 'technical_need' => 'Necessidade', 'justification' => 'Justificativa']);
    }

    private function payload(): array
    {
        return ['contract_id' => $this->contract->id, 'title' => 'Acompanhar OS DNIT', 'category' => 'os_dnit', 'os_dnit_order_id' => $this->order->id,
            'priority' => 'normal', 'assigned_to_ids' => [], 'checklist_items' => []];
    }

    private function search(array $filters = []): string
    {
        return route('tenant.activities.os-dnit-options', [$this->tenant, 'contract_id' => $this->contract->id, ...$filters]);
    }
}

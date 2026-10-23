<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\OsDnitHighway;
use App\Models\OsDnitOrder;
use App\Models\OsDnitProduct;
use App\Models\OsDnitResponsible;
use App\Models\OsDnitSupervisionLot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OsDnitContractIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogs_and_order_creation_only_expose_linked_contracts(): void
    {
        [$tenant, $user, $a, $b, $roadA, $roadB, $productA, $productB] = $this->context();
        $this->actingAs($user)->get(route('tenant.os-dnit.products.index', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.id', $productA->id)->has('contracts', 1)->where('contracts.0.id', $a->id)->has('navigationContracts', 1)->where('navigationContracts.0.id', $a->id));
        $this->get(route('tenant.os-dnit.settings.index', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('highways.data', 1)->where('highways.data.0.id', $roadA->id));
        $this->get(route('tenant.os-dnit.orders.create', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('products')->has('highways', 1)->where('highways.0.id', $roadA->id)->has('contracts', 1)->has('numberPreviews', 1)->missing("numberPreviews.{$b->id}"));
        $this->get(route('tenant.os-dnit.products.index', [$tenant, 'contract_id' => $b->id]))->assertSessionHasErrors('contract_id');
        $this->get(route('tenant.os-dnit.settings.index', [$tenant, 'contract_id' => $b->id]))->assertSessionHasErrors('contract_id');
    }

    public function test_catalog_writes_require_contract_and_membership_even_for_owner(): void
    {
        [$tenant, $user, $a, $b, $roadA, $roadB, $productA] = $this->context();
        $this->actingAs($user)->post(route('tenant.os-dnit.products.store', $tenant), $this->productData($b))->assertSessionHasErrors('contract_id');
        $this->post(route('tenant.os-dnit.products.store', $tenant), collect($this->productData($a))->except('contract_id')->all())->assertSessionHasErrors('contract_id');
        $this->post(route('tenant.os-dnit.settings.highways.store', $tenant), ['contract_id' => $b->id, 'name' => 'Road'])->assertSessionHasErrors('contract_id');
        $this->patch(route('tenant.os-dnit.settings.highways.update', [$tenant, $roadB]), ['contract_id' => $a->id, 'name' => 'Changed', 'active' => false])->assertNotFound();
        $this->assertDatabaseHas('os_dnit_highways', ['id' => $roadB->id, 'name' => 'Rodovia B']);
    }

    public function test_users_linked_to_both_contracts_still_cannot_mix_their_catalogs_in_an_order(): void
    {
        [$tenant, $user, $a, $b, $roadA, $roadB, $productA, $productB] = $this->context();
        $this->link($tenant, $user, $b);
        $this->actingAs($user)->post(route('tenant.os-dnit.orders.store', $tenant), [
            ...$this->orderData($a, $roadA, $productA), 'highway_id' => $roadB->id, 'items' => [['product_id' => $productB->id, 'quantity' => 1]],
        ])->assertSessionHasErrors(['highway_id', 'items']);
        $this->assertDatabaseCount('os_dnit_orders', 0);
    }

    public function test_catalog_duplicates_are_allowed_between_contracts_but_not_within_one(): void
    {
        [$tenant, $user, $a, $b] = $this->context();
        $this->link($tenant, $user, $b);
        $this->actingAs($user)->post(route('tenant.os-dnit.products.store', $tenant), [...$this->productData($a), 'code' => 'NEW'])->assertSessionHasNoErrors();
        $this->post(route('tenant.os-dnit.products.store', $tenant), [...$this->productData($b), 'code' => 'NEW'])->assertSessionHasNoErrors();
        $this->post(route('tenant.os-dnit.products.store', $tenant), [...$this->productData($a), 'code' => 'NEW'])->assertSessionHasErrors('code');
        $this->post(route('tenant.os-dnit.settings.highways.store', $tenant), ['contract_id' => $a->id, 'name' => 'BR-364/AC'])->assertSessionHasNoErrors();
        $this->post(route('tenant.os-dnit.settings.highways.store', $tenant), ['contract_id' => $b->id, 'name' => 'BR-364/AC'])->assertSessionHasNoErrors();
        $this->post(route('tenant.os-dnit.settings.highways.store', $tenant), ['contract_id' => $a->id, 'name' => 'BR-364/AC'])->assertSessionHasErrors('name');
    }

    public function test_tenant_and_platform_admins_have_no_bypass_when_unlinked(): void
    {
        [$tenant, $linked, $a, $b, $road, $roadB, $product] = $this->context();
        $this->actingAs($linked)->post(route('tenant.os-dnit.orders.store', $tenant), $this->orderData($a, $road, $product))->assertSessionHasNoErrors();
        $order = OsDnitOrder::sole();
        foreach (['tenant_owner', 'tenant_admin', 'platform'] as $role) {
            $user = User::factory()->create(['is_platform_admin' => $role === 'platform']);
            $tenant->memberships()->create(['user_id' => $user->id, 'role' => $role === 'platform' ? 'tenant_owner' : $role, 'status' => 'active']);
            $this->actingAs($user)->get(route('tenant.os-dnit.products.index', $tenant))->assertForbidden();
            $this->get(route('tenant.os-dnit.settings.index', $tenant))->assertForbidden();
            $this->get(route('tenant.os-dnit.orders.index', $tenant))->assertForbidden();
            $this->get(route('tenant.os-dnit.orders.create', $tenant))->assertForbidden();
            $this->get(route('tenant.os-dnit.orders.show', [$tenant, $order]))->assertNotFound();
            $this->post(route('tenant.os-dnit.orders.store', $tenant), $this->orderData($a, $road, $product))->assertForbidden();
        }
    }

    public function test_revoked_or_deleted_participation_blocks_existing_orders_and_downloads(): void
    {
        Storage::fake('local');
        [$tenant, $user, $a, $b, $road, $roadB, $product] = $this->context();
        $this->actingAs($user)->post(route('tenant.os-dnit.orders.store', $tenant), [
            ...$this->orderData($a, $road, $product), 'attachments' => [UploadedFile::fake()->create('private.pdf', 5, 'application/pdf')],
        ])->assertSessionHasNoErrors();
        $order = OsDnitOrder::with('attachments')->sole();
        $link = ContractParticipant::where('contract_id', $a->id)->where('user_id', $user->id)->sole();
        $link->update(['status' => 'inactive']);
        $this->get(route('tenant.os-dnit.orders.show', [$tenant, $order]))->assertNotFound();
        $this->get(route('tenant.os-dnit.orders.attachments.download', [$tenant, $order, $order->attachments->sole()]))->assertNotFound();
        $link->update(['status' => 'active']);
        $link->delete();
        $this->get(route('tenant.os-dnit.orders.show', [$tenant, $order]))->assertNotFound();
        $this->patch(route('tenant.os-dnit.settings.highways.update', [$tenant, $road]), ['contract_id' => $a->id, 'name' => 'Changed', 'active' => false])->assertNotFound();
    }

    public function test_highways_cannot_be_moved_to_another_contract(): void
    {
        [$tenant, $user, $a, $b, $road] = $this->context();
        $this->link($tenant, $user, $b);
        $this->actingAs($user)->patch(route('tenant.os-dnit.settings.highways.update', [$tenant, $road]), ['contract_id' => $b->id, 'name' => 'Changed', 'active' => true])->assertSessionHasErrors('contract_id');
        $this->assertDatabaseHas('os_dnit_highways', ['id' => $road->id, 'contract_id' => $a->id]);
    }

    private function context(): array
    {
        $tenant = Tenant::create(['slug' => 'scope-'.str()->lower(str()->random(8)), 'name' => 'Empresa', 'plan' => 'starter', 'status' => 'active']);
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $a = Contract::create(['tenant_id' => $tenant->id, 'code' => 'A', 'name' => 'Contrato A', 'status' => 'active']);
        $b = Contract::create(['tenant_id' => $tenant->id, 'code' => 'B', 'name' => 'Contrato B', 'status' => 'active']);
        $this->link($tenant, $user, $a);
        $roadA = OsDnitHighway::create(['tenant_id' => $tenant->id, 'contract_id' => $a->id, 'name' => 'Rodovia A', 'active' => true]);
        $roadB = OsDnitHighway::create(['tenant_id' => $tenant->id, 'contract_id' => $b->id, 'name' => 'Rodovia B', 'active' => true]);
        $productA = OsDnitProduct::create(['tenant_id' => $tenant->id, ...$this->productData($a)]);
        $productB = OsDnitProduct::create(['tenant_id' => $tenant->id, ...$this->productData($b)]);

        return [$tenant, $user, $a, $b, $roadA, $roadB, $productA, $productB];
    }

    private function link(Tenant $tenant, User $user, Contract $contract): void
    {
        ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'side' => 'client', 'role' => 'manager', 'status' => 'active']);
        OsDnitResponsible::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'role' => 'analyst', 'active' => true]);
    }

    private function productData(Contract $contract): array
    {
        return ['contract_id' => $contract->id, 'code' => '02', 'name' => 'Controle de Obras', 'category' => 'gestao_controle', 'unit' => 'mês', 'frequency' => 'monthly'];
    }

    private function orderData(Contract $contract, OsDnitHighway $road, OsDnitProduct $product): array
    {
        return ['contract_id' => $contract->id, 'request_number' => '001', 'supervision_lot_id' => OsDnitSupervisionLot::firstOrCreate(['tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'name' => '1'], ['active' => true])->id, 'highway_id' => $road->id, 'km_start' => 1, 'km_end' => 2,
            'subject' => 'Demanda', 'desired_deadline' => '2026-12-01', 'technical_need' => 'Necessidade', 'justification' => 'Resultado'];
    }
}

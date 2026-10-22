<?php

namespace Tests\Feature;

use App\Models\OsDnitProduct;
use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OsDnitProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_normalizes_code_and_uses_the_route_tenant(): void
    {
        [$tenant, $user] = $this->context();
        [$otherTenant] = $this->context();

        $this->actingAs($user)->post(route('tenant.os-dnit.products.store', $tenant), [
            ...$this->payload($tenant), 'code' => ' 02.pato ', 'tenant_id' => $otherTenant->id,
        ])->assertRedirect(route('tenant.os-dnit.products.index', $tenant));

        $this->assertDatabaseHas('os_dnit_products', ['tenant_id' => $tenant->id, 'code' => '02.PATO']);
        $this->assertDatabaseMissing('os_dnit_products', ['tenant_id' => $otherTenant->id, 'contract_id' => Contract::where('tenant_id', $otherTenant->id)->sole()->id]);
    }

    public function test_duplicate_codes_are_scoped_to_each_tenant_and_invalid_choices_are_rejected(): void
    {
        [$tenant, $user] = $this->context();
        [$otherTenant] = $this->context();
        OsDnitProduct::create([...$this->payload($tenant), 'tenant_id' => $otherTenant->id, 'contract_id' => Contract::where('tenant_id', $otherTenant->id)->sole()->id]);

        $url = route('tenant.os-dnit.products.store', $tenant);
        $this->actingAs($user)->post($url, $this->payload($tenant))->assertSessionHasNoErrors();
        $this->post($url, [...$this->payload($tenant), 'code' => '02.pato'])->assertSessionHasErrors('code');
        $this->post($url, [...$this->payload($tenant), 'code' => '03', 'category' => 'invalid', 'frequency' => 'invalid'])
            ->assertSessionHasErrors(['category', 'frequency']);
        $this->assertDatabaseCount('os_dnit_products', 2);
    }

    public function test_listing_and_filters_do_not_expose_another_tenants_products(): void
    {
        [$tenant, $user] = $this->context();
        [$otherTenant] = $this->context();
        OsDnitProduct::create([...$this->payload($tenant), 'tenant_id' => $tenant->id]);
        OsDnitProduct::create([...$this->payload($tenant), 'code' => '03', 'category' => 'ambiental', 'tenant_id' => $tenant->id]);
        OsDnitProduct::create([...$this->payload($tenant), 'tenant_id' => $otherTenant->id, 'contract_id' => Contract::where('tenant_id', $otherTenant->id)->sole()->id]);

        $this->actingAs($user)->get(route('tenant.os-dnit.products.index', [$tenant, 'q' => 'plano', 'category' => 'gestao_controle']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/OsDnit/Products')
                ->has('products.data', 1)
                ->where('products.data.0.tenant_id', $tenant->id)
                ->where('products.data.0.code', '02.PATO')
                ->has('categories', 8)
                ->where('canCreate', true));
        $this->get(route('tenant.os-dnit.products.index', $otherTenant))->assertForbidden();
    }

    public function test_regular_members_can_read_but_cannot_create_products(): void
    {
        [$tenant, $user] = $this->context('engineer');
        $this->actingAs($user)->get(route('tenant.os-dnit.products.index', $tenant))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('canCreate', false));
        $this->post(route('tenant.os-dnit.products.store', $tenant), $this->payload($tenant))->assertForbidden();
        $this->assertDatabaseCount('os_dnit_products', 0);
    }

    private function context(string $role = 'tenant_owner'): array
    {
        $tenant = Tenant::create(['slug' => 'os-dnit-'.str()->lower(str()->random(8)), 'name' => 'Empresa DNIT', 'plan' => 'starter', 'status' => 'active']);
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => $role, 'status' => 'active']);

        $contract = Contract::create(['tenant_id' => $tenant->id, 'code' => 'CT-01', 'name' => 'Contrato', 'status' => 'active']);
        ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'side' => 'client', 'role' => 'manager', 'status' => 'active']);
        return [$tenant, $user];
    }

    private function payload(Tenant $tenant): array
    {
        return ['contract_id' => Contract::where('tenant_id', $tenant->id)->sole()->id, 'code' => '02.PATO', 'name' => 'Elaboração de Plano de Trabalho e Orçamento', 'category' => 'gestao_controle', 'unit' => 'unid', 'frequency' => 'on_demand'];
    }
}

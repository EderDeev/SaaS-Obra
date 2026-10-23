<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OsDnitPermissions as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OsDnitEmptyTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrators_can_open_the_empty_module_without_gaining_contract_or_workflow_permissions(): void
    {
        $tenant = $this->tenant();
        foreach (['platform', 'tenant_owner', 'tenant_admin'] as $role) {
            $user = $this->user($tenant, $role);
            $this->actingAs($user)->get(route('tenant.os-dnit.orders.index', $tenant))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Tenant/OsDnit/Orders')
                    ->where('osDnitPermissions.can.view_orders', true)
                    ->where('osDnitPermissions.can.view_products', true)
                    ->where('osDnitPermissions.can.view_settings', true)
                    ->where('osDnitPermissions.can.create_orders', false)
                    ->where('osDnitPermissions.can.create_products', false)
                    ->where('osDnitPermissions.byContract', [])
                    ->where('emptyTenantSetup', true)->where('canCreate', false)
                    ->has('contracts', 0)->where('orders.total', 0));
            foreach (['products.index', 'dashboard', 'settings.index', 'responsibles.index'] as $route) {
                $this->get(route('tenant.os-dnit.'.$route, $tenant))->assertOk();
            }
            $this->post(route('tenant.os-dnit.orders.store', $tenant), [])->assertForbidden();
            $this->post(route('tenant.os-dnit.products.store', $tenant), [])->assertForbidden();
        }
        $this->assertDatabaseCount('os_dnit_orders', 0);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_regular_users_and_admins_of_another_tenant_cannot_open_the_empty_module(): void
    {
        $tenant = $this->tenant();
        $user = $this->user($tenant, 'engineer');
        $this->actingAs($user)->get(route('tenant.os-dnit.orders.index', $tenant))->assertForbidden();
        $this->get(route('tenant.dashboard', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('osDnitPermissions.can.view_orders', false));
        $admin = $this->user($tenant, 'tenant_admin');
        $foreign = $this->tenant();
        $this->actingAs($admin)->get(route('tenant.os-dnit.orders.index', $foreign))->assertForbidden();
    }

    public function test_existing_contracts_still_require_active_participation_and_explicit_permissions(): void
    {
        $tenant = $this->tenant();
        $user = $this->user($tenant, 'tenant_admin');
        $this->actingAs($user)->get(route('tenant.os-dnit.orders.index', $tenant))->assertOk();
        $contract = Contract::create(['tenant_id' => $tenant->id, 'code' => 'CT-001', 'name' => 'Contrato', 'status' => 'active']);
        $this->get(route('tenant.os-dnit.orders.index', $tenant))->assertForbidden();
        $link = ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id,
            'side' => 'manager', 'role' => 'manager', 'status' => 'active', 'os_dnit_permissions' => []]);
        $this->get(route('tenant.os-dnit.orders.index', $tenant))->assertForbidden();
        $link->update(['os_dnit_permissions' => [P::VIEW]]);
        $this->get(route('tenant.os-dnit.orders.index', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('emptyTenantSetup', false)->has('contracts', 1)
                ->where('osDnitPermissions.can.view_orders', true)->where('osDnitPermissions.can.view_settings', false));
        $link->update(['status' => 'inactive']);
        $this->get(route('tenant.os-dnit.orders.index', $tenant))->assertForbidden();
    }

    private function tenant(): Tenant
    {
        return Tenant::create(['name' => 'Empresa sem contratos', 'slug' => 'empty-'.str()->random(8), 'plan' => 'starter', 'status' => 'active']);
    }

    private function user(Tenant $tenant, string $role): User
    {
        $user = User::factory()->create(['is_platform_admin' => $role === 'platform']);
        if ($role !== 'platform') {
            $tenant->memberships()->create(['user_id' => $user->id, 'role' => $role, 'status' => 'active']);
        }

        return $user;
    }
}

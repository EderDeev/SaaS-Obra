<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\OsDnitHighway;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OsDnitPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OsDnitHighwaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_accepts_free_text_and_uses_the_route_tenant(): void
    {
        [$tenant, $user, $contract] = $this->context();
        [$otherTenant] = $this->context();
        $this->actingAs($user)->post(route('tenant.os-dnit.settings.highways.store', $tenant), [
            'contract_id' => $contract->id, 'name' => '  Estrada municipal do Rio Branco  ', 'tenant_id' => $otherTenant->id,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('os_dnit_highways', ['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'name' => 'Estrada municipal do Rio Branco', 'active' => true]);
        $this->assertDatabaseMissing('os_dnit_highways', ['tenant_id' => $otherTenant->id]);
    }

    public function test_duplicates_are_scoped_to_the_tenant_and_blank_names_are_rejected(): void
    {
        [$tenant, $user, $contract] = $this->context();
        [$otherTenant] = $this->context();
        OsDnitHighway::create(['tenant_id' => $otherTenant->id, 'contract_id' => Contract::where('tenant_id', $otherTenant->id)->sole()->id, 'name' => 'BR-364/AC']);
        $url = route('tenant.os-dnit.settings.highways.store', $tenant);
        $this->actingAs($user)->post($url, ['contract_id' => $contract->id, 'name' => 'BR-364/AC'])->assertSessionHasNoErrors();
        $this->post($url, ['contract_id' => $contract->id, 'name' => ' BR-364/AC '])->assertSessionHasErrors('name');
        $this->post($url, ['contract_id' => $contract->id, 'name' => '   '])->assertSessionHasErrors('name');
        $this->post($url, ['contract_id' => $contract->id, 'name' => str_repeat('A', 256)])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('os_dnit_highways', 2);
    }

    public function test_updates_preserve_tenant_isolation_and_can_deactivate_a_highway(): void
    {
        [$tenant, $user, $contract] = $this->context();
        [$otherTenant] = $this->context();
        $highway = OsDnitHighway::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'name' => 'BR-364/AC']);
        $otherHighway = OsDnitHighway::create(['tenant_id' => $otherTenant->id, 'contract_id' => Contract::where('tenant_id', $otherTenant->id)->sole()->id, 'name' => 'BR-307/AC']);
        $this->actingAs($user)->patch(route('tenant.os-dnit.settings.highways.update', [$tenant, $highway]), [
            'contract_id' => $contract->id, 'name' => 'BR-364/AC', 'active' => false, 'tenant_id' => $otherTenant->id,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('os_dnit_highways', ['id' => $highway->id, 'tenant_id' => $tenant->id, 'active' => false]);
        $this->patch(route('tenant.os-dnit.settings.highways.update', [$tenant, $otherHighway]), ['contract_id' => $contract->id, 'name' => 'Changed', 'active' => false])->assertNotFound();
        $this->assertDatabaseHas('os_dnit_highways', ['id' => $otherHighway->id, 'name' => 'BR-307/AC']);
    }

    public function test_members_with_view_settings_can_only_read_their_tenant_and_listing_is_paginated(): void
    {
        [$tenant, $user, $contract] = $this->context('engineer');
        ContractParticipant::where('contract_id', $contract->id)->where('user_id', $user->id)
            ->update(['os_dnit_permissions' => OsDnitPermissions::normalize([OsDnitPermissions::VIEW_SETTINGS])]);
        [$otherTenant] = $this->context();
        for ($i = 1; $i <= 16; $i++) {
            OsDnitHighway::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'name' => sprintf('BR-%03d/AC', $i)]);
        }
        OsDnitHighway::create(['tenant_id' => $otherTenant->id, 'contract_id' => Contract::where('tenant_id', $otherTenant->id)->sole()->id, 'name' => 'BR-999/AC']);
        $this->actingAs($user)->get(route('tenant.os-dnit.settings.index', $tenant))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/OsDnit/Settings')->has('highways.data', 15)
            ->where('highways.total', 16)->where('highways.per_page', 15)->where('canManage', false));
        $this->get(route('tenant.os-dnit.settings.index', [$tenant, 'q' => 'br-016']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('highways.data', 1)->where('highways.data.0.name', 'BR-016/AC'));
        $this->post(route('tenant.os-dnit.settings.highways.store', $tenant), ['contract_id' => $contract->id, 'name' => 'BR-364/AC'])->assertForbidden();
        $this->patch(route('tenant.os-dnit.settings.highways.update', [$tenant, OsDnitHighway::where('tenant_id', $tenant->id)->first()]), ['contract_id' => $contract->id, 'name' => 'Changed', 'active' => false])->assertForbidden();
        $this->get(route('tenant.os-dnit.settings.index', $otherTenant))->assertForbidden();
    }

    private function context(string $role = 'tenant_owner'): array
    {
        $tenant = Tenant::create(['slug' => 'highways-'.str()->lower(str()->random(8)), 'name' => 'Empresa DNIT', 'plan' => 'starter', 'status' => 'active']);
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => $role, 'status' => 'active']);

        $contract = Contract::create(['tenant_id' => $tenant->id, 'code' => 'CT-01', 'name' => 'Contrato', 'status' => 'active']);
        ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'side' => 'client', 'role' => 'manager', 'status' => 'active']);

        return [$tenant, $user, $contract];
    }
}

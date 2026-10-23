<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AccessibleTenants;
use App\Support\NavigationPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_tenant_login_requires_a_new_choice_even_with_an_intended_url_and_previous_selection(): void
    {
        $user = User::factory()->create();
        $a = $this->tenant($user, 'Alfa');
        $b = $this->tenant($user, 'Beta');
        $this->withSession(['current_tenant_id' => $a->id, 'url.intended' => route('tenant.dashboard', $a)])
            ->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('tenant-selection.index'))->assertSessionMissing('current_tenant_id')->assertSessionMissing('url.intended');
        $this->get(route('tenant-selection.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Auth/SelectTenant')->has('tenants', 2)->where('tenants.0.id', $a->id)->where('tenants.1.id', $b->id));
        $this->get(route('dashboard'))->assertRedirect(route('tenant-selection.index'));
    }

    public function test_single_tenant_users_enter_directly_and_do_not_see_the_switch_control(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenant($user);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get(route('dashboard'))->assertRedirect(route('tenant.dashboard', $tenant))->assertSessionHas('current_tenant_id', $tenant->id);
        $this->get(route('tenant-selection.index'))->assertRedirect(route('tenant.dashboard', $tenant));
        $this->get(route('tenant.dashboard', $tenant))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canSwitchTenant', false));
    }

    public function test_selection_updates_the_session_and_lands_on_a_module_that_the_user_can_view(): void
    {
        $user = User::factory()->create();
        $a = $this->tenant($user, 'Alfa');
        $b = $this->tenant($user, 'Beta');
        $b->memberships()->where('user_id', $user->id)->update(['navigation_permissions' => [NavigationPermissions::TUTORIALS]]);
        $this->actingAs($user)->withSession(['url.intended' => 'https://external.example/'])
            ->post(route('tenant-selection.store'), ['tenant_id' => $b->id, 'redirect' => 'https://external.example/'])
            ->assertRedirect(route('tenant.tutorials.index', $b))->assertSessionHas('current_tenant_id', $b->id)->assertSessionMissing('url.intended');
        $this->get(route('tenant.tutorials.index', $b))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('currentTenant.id', $b->id)->where('canSwitchTenant', true)->where('navigationPermissions.can.view_overview', false));
        $this->get(route('tenant.dashboard', $b))->assertForbidden();
        $this->get(route('dashboard'))->assertRedirect(route('tenant.tutorials.index', $b));
        $this->post(route('tenant-selection.store'), ['tenant_id' => $a->id])->assertRedirect(route('tenant.dashboard', $a));
        $this->get(route('tenant.dashboard', $a))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('currentTenant.id', $a->id)->where('navigationPermissions.can.view_overview', true));
        $this->assertSame('engineer', $user->tenantRole($b));
    }

    public function test_only_active_permitted_unsuspended_tenants_are_listed_and_contract_links_are_deduplicated(): void
    {
        $user = User::factory()->create();
        $a = $this->tenant($user, 'Alfa');
        $b = $this->tenant(null, 'Beta');
        $this->participant($user, $a);
        $this->participant($user, $b);
        $this->participant($user, $b);
        $inactive = $this->tenant($user, 'Inactive');
        $inactive->memberships()->update(['status' => 'inactive']);
        $this->participant($user, $inactive)->update(['status' => 'inactive']);
        $deleted = $this->tenant(null, 'Deleted');
        $this->participant($user, $deleted)->delete();
        $suspended = $this->tenant($user, 'Suspended');
        $suspended->update(['status' => 'suspended']);
        $this->participant($user, $suspended);
        $this->tenant(null, 'Foreign');
        $this->assertSame([$a->id, $b->id], AccessibleTenants::query($user)->orderBy('name')->pluck('id')->all());
        $this->actingAs($user)->get(route('tenant-selection.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('tenants', 2)->where('tenants.0.id', $a->id)->where('tenants.1.id', $b->id));
    }

    public function test_an_external_participant_can_select_and_enter_their_environment(): void
    {
        $user = User::factory()->create();
        $a = $this->tenant(null, 'Alfa');
        $b = $this->tenant(null, 'Beta');
        $this->participant($user, $a);
        $this->participant($user, $b);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('tenant-selection.index'));
        $this->post(route('tenant-selection.store'), ['tenant_id' => $b->id])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasTenantAccess($b));
        $this->assertDatabaseCount('tenant_users', 0);
    }

    public function test_foreign_suspended_and_revoked_choices_are_rejected_without_changing_the_session(): void
    {
        $user = User::factory()->create();
        $allowed = $this->tenant($user, 'Allowed');
        $revoked = $this->tenant($user, 'Revoked');
        $this->actingAs($user)->get(route('tenant-selection.index'))->assertOk();
        $revoked->memberships()->update(['status' => 'inactive']);
        $foreign = $this->tenant(null, 'Foreign');
        $suspended = $this->tenant($user, 'Suspended');
        $suspended->update(['status' => 'suspended']);
        foreach ([$foreign->id, $revoked->id, $suspended->id, 999999] as $id) {
            $this->withSession(['current_tenant_id' => $allowed->id])->post(route('tenant-selection.store'), ['tenant_id' => $id])
                ->assertNotFound()->assertSessionHas('current_tenant_id', $allowed->id);
        }
        $this->post(route('tenant-selection.store'), ['tenant_id' => 'bad'])->assertSessionHasErrors('tenant_id');
    }

    public function test_a_stale_session_is_revalidated_before_redirecting(): void
    {
        $user = User::factory()->create();
        $a = $this->tenant($user, 'Alfa');
        $b = $this->tenant($user, 'Beta');
        $this->actingAs($user)->withSession(['current_tenant_id' => 999999])->get(route('dashboard'))
            ->assertRedirect(route('tenant-selection.index'))->assertSessionMissing('current_tenant_id');
        $b->memberships()->update(['status' => 'inactive']);
        $this->withSession(['current_tenant_id' => $b->id])->get(route('dashboard'))
            ->assertRedirect(route('tenant.dashboard', $a))->assertSessionHas('current_tenant_id', $a->id);
        $a->memberships()->update(['status' => 'inactive']);
        $this->get(route('dashboard'))->assertForbidden();
        $this->get(route('tenant-selection.index'))->assertForbidden();
    }

    public function test_guest_and_temporary_password_users_cannot_bypass_authentication_with_selection(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $a = $this->tenant($user, 'Alfa');
        $this->tenant($user, 'Beta');
        $this->get(route('tenant-selection.index'))->assertRedirect(route('login'));
        $this->post(route('tenant-selection.store'), ['tenant_id' => $a->id])->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('tenant-selection.index'))->assertRedirect(route('password.force.edit'));
        $this->post(route('tenant-selection.store'), ['tenant_id' => $a->id])->assertRedirect(route('password.force.edit'));
    }

    public function test_platform_admin_keeps_the_platform_landing_and_can_switch_to_an_authorized_environment(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $a = $this->tenant(null, 'Alfa');
        $this->tenant(null, 'Beta');
        $suspended = $this->tenant(null, 'Suspended');
        $suspended->update(['status' => 'suspended']);
        $this->post(route('login'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get(route('dashboard'))->assertRedirect(route('platform.dashboard'));
        $this->get(route('tenant-selection.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('tenants', 2));
        $this->post(route('tenant-selection.store'), ['tenant_id' => $a->id])->assertRedirect(route('tenant.dashboard', $a));
        $this->post(route('tenant-selection.store'), ['tenant_id' => $suspended->id])->assertNotFound();
    }

    private function tenant(?User $user, string $name = 'Empresa'): Tenant
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => str()->slug($name).'-'.str()->random(6), 'plan' => 'starter', 'status' => 'active']);
        if ($user) {
            $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'engineer', 'status' => 'active']);
        }

        return $tenant;
    }

    private function participant(User $user, Tenant $tenant): ContractParticipant
    {
        $contract = Contract::create(['tenant_id' => $tenant->id, 'code' => str()->random(8), 'name' => 'Contrato', 'status' => 'active']);

        return ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id,
            'user_id' => $user->id, 'side' => 'client', 'role' => 'team_member', 'status' => 'active']);
    }
}

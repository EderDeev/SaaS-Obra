<?php

namespace Tests\Feature;

use App\Models\{Tenant, TenantUser, TipoEmpresa, User};
use App\Support\NavigationPermissions as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantNavigationPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $owner;
    private User $user;
    private TenantUser $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'navigation-test', 'name' => 'Permissões', 'plan' => 'starter', 'status' => 'active']);
        $this->owner = User::factory()->create();
        $this->user = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $this->owner->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $this->membership = $this->tenant->memberships()->create(['user_id' => $this->user->id, 'role' => 'engineer', 'status' => 'active',
            'activity_permissions' => [], 'contract_permissions' => [], 'budget_permissions' => [], 'user_permissions' => [],
            'documentation_permissions' => [], 'diario_obra_permissions' => [], 'ordem_servico_permissions' => [], 'medicao_permissions' => [], 'project_permissions' => []]);
        $this->actingAs($this->user);
    }

    public function test_existing_members_keep_access_until_the_permissions_are_configured(): void
    {
        $this->get(route('tenant.dashboard', $this->tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('navigationPermissions.can.view_overview', true)->where('navigationPermissions.can.view_tutorials', true));
        $this->get(route('tenant.tutorials.index', $this->tenant))->assertOk();
    }

    public function test_revoking_overview_blocks_its_url_but_preserves_tutorials_and_a_valid_login_destination(): void
    {
        $this->membership->update(['navigation_permissions' => [P::TUTORIALS]]);
        $this->get(route('tenant.dashboard', $this->tenant))->assertForbidden();
        $this->get(route('tenant.tutorials.index', $this->tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('navigationPermissions.can.view_overview', false)->where('navigationPermissions.can.view_tutorials', true)
            ->where('tenantHomeUrl', route('tenant.tutorials.index', $this->tenant)));
        $this->get(route('dashboard'))->assertRedirect(route('tenant.tutorials.index', $this->tenant));
        $this->membership->update(['navigation_permissions' => []]);
        $this->get(route('tenant.tutorials.index', $this->tenant))->assertForbidden();
        $this->get(route('dashboard'))->assertRedirect(route('profile.edit'));
    }

    public function test_tutorials_can_be_revoked_independently_and_owner_keeps_full_access(): void
    {
        $this->membership->update(['navigation_permissions' => [P::OVERVIEW]]);
        $this->get(route('tenant.tutorials.index', $this->tenant))->assertForbidden();
        $this->get(route('tenant.dashboard', $this->tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('navigationPermissions.can.view_tutorials', false));
        $this->tenant->memberships()->where('user_id', $this->owner->id)->update(['navigation_permissions' => []]);
        $this->actingAs($this->owner)->get(route('tenant.dashboard', $this->tenant))->assertOk();
        $this->get(route('tenant.tutorials.index', $this->tenant))->assertOk();
    }

    public function test_permissions_screen_saves_tenant_wide_access_without_contract_or_changing_other_permissions(): void
    {
        $url = route('tenant.permissions.update', $this->tenant);
        $this->actingAs($this->owner)->get(route('tenant.permissions.index', $this->tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('permissionGroups.navigation.permissions', P::labels())
            ->where('navigationPermissionsByUser.'.$this->user->id, P::all()));
        $this->patch($url, ['user_id' => $this->user->id, 'navigation_permissions' => [P::TUTORIALS]])->assertSessionHasNoErrors();
        $this->assertSame([P::TUTORIALS], $this->membership->fresh()->navigation_permissions);
        $this->assertSame([], $this->membership->fresh()->budget_permissions);
        $this->actingAs($this->user)->patch($url, ['user_id' => $this->user->id, 'navigation_permissions' => P::all()])->assertForbidden();
        $this->assertSame([P::TUTORIALS], $this->membership->fresh()->navigation_permissions);
    }

    public function test_users_screen_loads_saves_and_preserves_navigation_permissions_on_profile_only_edits(): void
    {
        $empresa = $this->tenant->empresas()->create(['tipo_empresa_id' => TipoEmpresa::firstOrCreate(['nome' => 'gerenciadora'])->id,
            'nome' => 'Empresa', 'cnpj' => '44.444.444/0001-44', 'sigla' => 'EMP']);
        $data = ['name' => $this->user->name, 'email' => $this->user->email, 'role' => 'engineer', 'empresa_id' => $empresa->id];
        $url = route('tenant.users.update', [$this->tenant, $this->membership]);
        $this->actingAs($this->owner)->get(route('tenant.users.index', $this->tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('navigationPermissionOptions', P::labels()));
        $this->patch($url, [...$data, 'navigation_permissions' => [P::OVERVIEW]])->assertSessionHasNoErrors();
        $this->assertSame([P::OVERVIEW], $this->membership->fresh()->navigation_permissions);
        $this->patch($url, $data)->assertSessionHasNoErrors();
        $this->assertSame([P::OVERVIEW], $this->membership->fresh()->navigation_permissions);
        $this->patch($url, [...$data, 'navigation_permissions' => ['invalid']])->assertSessionHasErrors('navigation_permissions.0');
    }

    public function test_navigation_permissions_are_isolated_between_tenants(): void
    {
        $other = Tenant::create(['slug' => 'other-navigation', 'name' => 'Outro tenant', 'plan' => 'starter', 'status' => 'active']);
        $other->memberships()->create(['user_id' => $this->user->id, 'role' => 'engineer', 'status' => 'active', 'navigation_permissions' => []]);
        $this->get(route('tenant.dashboard', $this->tenant))->assertOk();
        $this->get(route('tenant.dashboard', $other))->assertForbidden();
        $this->get(route('tenant.tutorials.index', $other))->assertForbidden();
    }
}

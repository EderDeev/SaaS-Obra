<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\OsDnitHighway;
use App\Models\OsDnitOrder;
use App\Models\OsDnitResponsible;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OsDnitFlowRecipients;
use App\Support\OsDnitContractAccess;
use App\Support\OsDnitPermissions as P;
use App\Support\OsDnitWorkflowAccess as Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OsDnitAdminExemptionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Contract $contract;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Exceção', 'slug' => 'exception', 'plan' => 'starter', 'status' => 'active']);
        $this->contract = Contract::create(['tenant_id' => $this->tenant->id, 'code' => 'CT-01', 'name' => 'Contrato', 'status' => 'active']);
        $this->admin = User::factory()->create(['is_platform_admin' => true]);
        $this->admin->forceFill(['os_dnit_contract_exemption' => true])->save();
    }

    public function test_only_the_individually_flagged_platform_account_receives_unlinked_access(): void
    {
        $otherAdmin = User::factory()->create(['is_platform_admin' => true]);
        $ordinary = User::factory()->create();
        $ordinary->forceFill(['os_dnit_contract_exemption' => true])->save();
        foreach ([$otherAdmin, $ordinary] as $user) {
            $this->tenant->memberships()->create(['user_id' => $user->id, 'role' => 'tenant_admin', 'status' => 'active']);
            $this->assertFalse(P::hasContractExemption($user));
            $this->assertFalse(P::can($user, $this->tenant, P::VIEW, $this->contract->id));
            $this->actingAs($user)->get(route('tenant.os-dnit.orders.index', $this->tenant))->assertForbidden();
        }
        foreach (P::all() as $permission) {
            $this->assertTrue(P::can($this->admin, $this->tenant, $permission, $this->contract->id));
        }
        foreach (['orders.index', 'products.index', 'dashboard', 'settings.index', 'responsibles.index'] as $route) {
            $this->actingAs($this->admin)->get(route('tenant.os-dnit.'.$route, $this->tenant))->assertOk();
        }
        $this->assertDatabaseCount('contract_participants', 0);
        $this->assertDatabaseMissing('tenant_users', ['user_id' => $this->admin->id]);
        $this->assertFalse(P::canAny($this->admin, $this->tenant, 'invalid_permission'));
    }

    public function test_contract_queries_stay_scoped_to_the_requested_tenant(): void
    {
        $other = Tenant::create(['name' => 'Outra', 'slug' => 'other', 'plan' => 'starter', 'status' => 'active']);
        $foreign = Contract::create(['tenant_id' => $other->id, 'code' => 'OTHER', 'name' => 'Outro', 'status' => 'active']);
        $this->assertSame([$this->contract->id], OsDnitContractAccess::contracts($this->admin, $this->tenant, P::VIEW)->pluck('id')->all());
        $this->assertFalse(P::can($this->admin, $this->tenant, P::VIEW, $foreign->id));
        $this->assertFalse(P::eligibleUsers($this->tenant, $foreign->id)->exists());
        $this->actingAs($this->admin)->get(route('tenant.os-dnit.orders.index', [$this->tenant, 'contract_id' => $foreign->id]))
            ->assertSessionHasErrors('contract_id');
    }

    public function test_responsible_assignment_and_notifications_work_without_bypassing_workflow_roles(): void
    {
        $this->assertFalse(Workflow::hasRole($this->admin, $this->tenant, $this->contract->id, 'concremat'));
        $this->assertFalse(Workflow::hasRecipient($this->tenant, $this->contract->id, 'concremat'));
        $this->actingAs($this->admin)->get(route('tenant.os-dnit.responsibles.index', $this->tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('participants', 1)->where('participants.0.user_id', $this->admin->id));
        $this->post(route('tenant.os-dnit.responsibles.store', $this->tenant), ['contract_id' => $this->contract->id,
            'assignments' => [['role' => 'concremat', 'user_id' => $this->admin->id]]])->assertSessionHasNoErrors();
        $this->assertTrue(Workflow::hasRole($this->admin, $this->tenant, $this->contract->id, 'concremat'));
        $this->assertFalse(Workflow::hasRole($this->admin, $this->tenant, $this->contract->id, 'contract_inspector'));
        $this->assertTrue(Workflow::hasRecipient($this->tenant, $this->contract->id, 'concremat'));
        $otherAdmin = User::factory()->create(['is_platform_admin' => true]);
        $this->post(route('tenant.os-dnit.responsibles.store', $this->tenant), ['contract_id' => $this->contract->id,
            'assignments' => [['role' => 'concremat', 'user_id' => $otherAdmin->id]]])->assertSessionHasErrors('assignments.0.user_id');
        $road = OsDnitHighway::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id, 'name' => 'BR-364', 'active' => true]);
        $order = OsDnitOrder::create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->contract->id,
            'highway_id' => $road->id,
            'created_by_id' => $this->admin->id, 'request_number' => '001/2026', 'supervision_lot' => '01',
            'highway_name' => 'BR-364', 'km_start' => 1, 'km_end' => 2, 'subject' => 'Teste',
            'technical_need' => 'Necessidade', 'justification' => 'Motivo', 'desired_deadline' => '2026-12-31', 'status' => 'awaiting_concremat']);
        $event = $order->events()->create(['actor_id' => $this->admin->id, 'action' => 'submitted',
            'from_status' => 'draft', 'to_status' => 'awaiting_concremat', 'snapshot' => []]);
        $this->assertSame([$this->admin->id], app(OsDnitFlowRecipients::class)->forEvent($event)->modelKeys());
        $this->admin->forceFill(['os_dnit_contract_exemption' => false])->save();
        $this->assertCount(0, app(OsDnitFlowRecipients::class)->forEvent($event));
        $this->assertFalse(Workflow::hasRole($this->admin, $this->tenant, $this->contract->id, 'concremat'));
        $this->admin->forceFill(['os_dnit_contract_exemption' => true])->save();
        OsDnitResponsible::where('user_id', $this->admin->id)->update(['active' => false]);
        $this->assertFalse(Workflow::hasRecipient($this->tenant, $this->contract->id, 'concremat'));
        $this->assertCount(0, app(OsDnitFlowRecipients::class)->forEvent($event));
        $this->assertDatabaseCount('contract_participants', 0);
    }

    public function test_permissions_screen_displays_inherited_access_without_synthetic_memberships_and_rejects_other_grants(): void
    {
        $this->actingAs($this->admin)->get(route('tenant.permissions.index', $this->tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('users', 1)
                ->where('users.0.os_dnit_contract_exemption', true)->where('users.0.os_dnit_only', true)
                ->where("osDnitPermissionsByUserContract.{$this->admin->id}:{$this->contract->id}", P::all()));
        $this->patch(route('tenant.permissions.update', $this->tenant), ['user_id' => $this->admin->id,
            'contract_id' => $this->contract->id, 'os_dnit_permissions' => []])->assertSessionHasNoErrors();
        $this->assertTrue(P::can($this->admin->fresh(), $this->tenant, P::VIEW, $this->contract->id));
        $this->patch(route('tenant.permissions.update', $this->tenant), ['user_id' => $this->admin->id,
            'contract_id' => $this->contract->id, 'os_dnit_permissions' => P::all(), 'project_permissions' => ['view_projects']])->assertSessionHasErrors('user_id');
        $this->assertDatabaseCount('contract_participants', 0);
        $this->assertDatabaseCount('tenant_users', 0);
    }

    public function test_user_edit_exposes_the_exception_and_requests_cannot_enable_it_for_another_account(): void
    {
        $this->tenant->memberships()->create(['user_id' => $this->admin->id, 'role' => 'tenant_admin', 'status' => 'active']);
        $this->actingAs($this->admin)->get(route('tenant.users.index', $this->tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('memberships.0.os_dnit_contract_exemption', true)
                ->where('memberships.0.os_dnit_inherited_permissions', P::all()));
        $otherAdmin = User::factory()->create(['is_platform_admin' => true]);
        $this->tenant->memberships()->create(['user_id' => $otherAdmin->id, 'role' => 'tenant_admin', 'status' => 'active']);
        $this->patch(route('tenant.permissions.update', $this->tenant), ['user_id' => $otherAdmin->id,
            'contract_id' => $this->contract->id, 'os_dnit_permissions' => [P::VIEW],
            'os_dnit_contract_exemption' => true])->assertSessionHasErrors('contract_id');
        $this->assertFalse((bool) $otherAdmin->fresh()->os_dnit_contract_exemption);
        $this->assertFalse($otherAdmin->isFillable('os_dnit_contract_exemption'));
        $this->assertArrayNotHasKey('os_dnit_contract_exemption', $this->admin->toArray());
    }
}

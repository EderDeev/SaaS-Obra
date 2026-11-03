<?php

namespace Tests\Feature;

use App\Models\{ContractParticipant, Tenant, User};
use App\Support\{ProjectPermissions, UserPermissions};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserContractLinkTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $tenant = Tenant::create(['slug' => 'contract-link', 'name' => 'Diretoria de testes', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create();
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $admin->id, 'role' => 'tenant_admin', 'status' => 'active']);
        $membership = $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'engenheiro_planejamento', 'status' => 'active', 'empresa_id' => null, 'user_permissions' => [UserPermissions::VIEW]]);
        $contract = $tenant->contracts()->create(['code' => 'CT-001', 'name' => 'Contrato de testes', 'status' => 'active']);
        return [$tenant, $admin, $user, $membership, $contract];
    }

    public function test_linking_contracts_does_not_require_company_or_change_identity_or_global_permissions(): void
    {
        [$tenant, $admin, $user, $membership, $contract] = $this->context();
        $this->actingAs($admin)->patch(route('tenant.users.contracts.update', [$tenant, $membership]), [
            'name' => 'Ignored replacement', 'email' => 'ignored@example.test', 'role' => 'tenant_owner', 'user_permissions' => [],
            'contract_accesses' => [['contract_id' => $contract->id, 'side' => 'manager', 'role' => 'team_member', 'project_permissions' => [ProjectPermissions::VIEW]]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contract_participants', ['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'status' => 'active', 'deleted_at' => null]);
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertNull($membership->fresh()->empresa_id);
        $this->assertSame('engenheiro_planejamento', $membership->fresh()->role);
        $this->assertSame([UserPermissions::VIEW], $membership->fresh()->user_permissions);
        $this->assertSame([ProjectPermissions::VIEW], ContractParticipant::firstOrFail()->project_permissions);
    }

    public function test_foreign_contract_is_rejected_before_existing_access_is_removed(): void
    {
        [$tenant, $admin, $user, $membership, $contract] = $this->context();
        $participant = ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'side' => 'manager', 'role' => 'team_member', 'status' => 'active']);
        $other = Tenant::create(['slug' => 'other-link', 'name' => 'Outra diretoria', 'status' => 'active', 'plan' => 'starter']);
        $foreign = $other->contracts()->create(['code' => 'OTHER', 'name' => 'Outro contrato', 'status' => 'active']);
        $this->actingAs($admin)->patch(route('tenant.users.contracts.update', [$tenant, $membership]), ['contract_accesses' => [['contract_id' => $foreign->id, 'side' => 'manager', 'role' => 'team_member']]])
            ->assertSessionHasErrors('contract_accesses.0.contract_id');
        $this->assertDatabaseHas('contract_participants', ['id' => $participant->id, 'status' => 'active', 'deleted_at' => null]);
    }

    public function test_link_update_requires_edit_permission(): void
    {
        [$tenant, , $user, $membership] = $this->context();
        $this->actingAs($user)->patch(route('tenant.users.contracts.update', [$tenant, $membership]), ['contract_accesses' => []])->assertForbidden();
    }

    public function test_link_update_cannot_target_membership_from_another_directorate(): void
    {
        [$tenant, $admin, , $membership] = $this->context();
        $other = Tenant::create(['slug' => 'foreign-member', 'name' => 'Outra diretoria', 'status' => 'active', 'plan' => 'starter']);
        $foreignMembership = $other->memberships()->create(['user_id' => $membership->user_id, 'role' => 'engenheiro_planejamento', 'status' => 'active']);
        $this->actingAs($admin)->patch(route('tenant.users.contracts.update', [$tenant, $foreignMembership]), ['contract_accesses' => []])->assertNotFound();
    }

    public function test_explicit_empty_selection_soft_deletes_the_link_and_missing_selection_does_not(): void
    {
        [$tenant, $admin, $user, $membership, $contract] = $this->context();
        $participant = ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'side' => 'manager', 'role' => 'team_member', 'status' => 'active']);
        $url = route('tenant.users.contracts.update', [$tenant, $membership]);
        $this->actingAs($admin)->patch($url, [])->assertSessionHasErrors('contract_accesses');
        $this->assertDatabaseHas('contract_participants', ['id' => $participant->id, 'deleted_at' => null]);
        $this->actingAs($admin)->patch($url, ['contract_accesses' => []])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSoftDeleted('contract_participants', ['id' => $participant->id, 'status' => 'inactive']);
    }
}

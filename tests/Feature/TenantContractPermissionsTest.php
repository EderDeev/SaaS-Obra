<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ContractPermissions;
use App\Support\ParametrizacaoPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantContractPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_permissions_are_split_and_scoped_by_contract(): void
    {
        [$tenant, $user, $contractA, $contractB] = $this->context([ContractPermissions::VIEW]);

        ContractParticipant::query()
            ->where('contract_id', $contractB->id)
            ->where('user_id', $user->id)
            ->update(['contract_permissions' => []]);

        $this->actingAs($user)
            ->get(route('tenant.contracts.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('contracts', 1)
                ->where('contracts.0.id', $contractA->id)
                ->where("contractCapabilities.{$contractA->id}.parametrize", false)
                ->where("contractCapabilities.{$contractA->id}.additives", false));

        $this->actingAs($user)
            ->get(route('tenant.contracts.show', [$tenant, $contractB]))
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('tenant.contracts.parametrizacao.update', [$tenant, $contractA]), [])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('tenant.contracts.additives.store', [$tenant, $contractA]), [])
            ->assertForbidden();
    }

    public function test_permission_screen_saves_contract_permissions_and_adds_view_automatically(): void
    {
        [$tenant, $user, $contract] = $this->context([], true);
        $owner = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'tenant_owner',
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->patch(route('tenant.permissions.update', $tenant), [
                'user_id' => $user->id,
                'contract_id' => $contract->id,
                'contract_permissions' => [ContractPermissions::ADDITIVES],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $participant = ContractParticipant::query()
            ->where('contract_id', $contract->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame([
            ContractPermissions::VIEW,
            ContractPermissions::ADDITIVES,
        ], $participant->contract_permissions);
    }

    public function test_parametrizacao_separates_view_from_management(): void
    {
        [$tenant, $user] = $this->context([ContractPermissions::VIEW], true);
        $membership = $tenant->memberships()->where('user_id', $user->id)->firstOrFail();
        $membership->update([
            'parametrizacao_permissions' => [
                ParametrizacaoPermissions::VIEW,
                ParametrizacaoPermissions::EMPRESAS,
            ],
        ]);

        $this->actingAs($user)
            ->get(route('tenant.parametrizacao.empresas.index', $tenant))
            ->assertOk();

        $this->actingAs($user)
            ->post(route('tenant.parametrizacao.empresas.store', $tenant), [])
            ->assertForbidden();

        $owner = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'tenant_owner',
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->patch(route('tenant.permissions.update', $tenant), [
                'user_id' => $user->id,
                'parametrizacao_permissions' => [ParametrizacaoPermissions::MANAGE_EMPRESAS],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([
            ParametrizacaoPermissions::VIEW,
            ParametrizacaoPermissions::MANAGE_EMPRESAS,
            ParametrizacaoPermissions::EMPRESAS,
        ], $membership->fresh()->parametrizacao_permissions);
    }

    private function context(array $permissions, bool $oneContract = false): array
    {
        $tenant = Tenant::create([
            'slug' => 'contratos-'.str()->lower(str()->random(8)),
            'name' => 'Tenant Contratos',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engineer',
            'status' => 'active',
            'contract_permissions' => $permissions,
        ]);
        $contractA = $this->contract($tenant, 'CT-PERM-A');
        $this->participant($tenant, $contractA, $user, $permissions);

        if ($oneContract) {
            return [$tenant, $user, $contractA];
        }

        $contractB = $this->contract($tenant, 'CT-PERM-B');
        $this->participant($tenant, $contractB, $user, $permissions);

        return [$tenant, $user, $contractA, $contractB];
    }

    private function contract(Tenant $tenant, string $code): Contract
    {
        return Contract::create([
            'tenant_id' => $tenant->id,
            'code' => $code,
            'name' => "Contrato {$code}",
            'status' => 'active',
        ]);
    }

    private function participant(Tenant $tenant, Contract $contract, User $user, array $permissions): ContractParticipant
    {
        return ContractParticipant::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'contract_permissions' => $permissions,
        ]);
    }
}

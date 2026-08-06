<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\FolhaRosto;
use App\Models\FolhaRostoAnaliseResponsavel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\MedicaoPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantMedicaoPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_macro_permissions_are_split_and_scoped_by_contract(): void
    {
        [$tenant, $user, $contractA, $contractB] = $this->context([MedicaoPermissions::VIEW]);

        ContractParticipant::query()
            ->where('contract_id', $contractB->id)
            ->where('user_id', $user->id)
            ->update(['medicao_permissions' => []]);

        $this->actingAs($user)
            ->get(route('tenant.medicao.item.index', ['tenant' => $tenant, 'contract_id' => $contractA->id]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('tenant.medicao.item.index', ['tenant' => $tenant, 'contract_id' => $contractB->id]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.medicao.analisar-pleito.index', ['tenant' => $tenant, 'contract_id' => $contractA->id]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.medicao.relatorios.index', ['tenant' => $tenant, 'contract_id' => $contractA->id]))
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
                'medicao_permissions' => [MedicaoPermissions::ANALYZE],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $participant = ContractParticipant::query()
            ->where('contract_id', $contract->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame([
            MedicaoPermissions::VIEW,
            MedicaoPermissions::ANALYZE,
        ], $participant->medicao_permissions);
    }

    public function test_analysis_responsibility_does_not_bypass_revoked_macro_permission(): void
    {
        [$tenant, $user, $contract] = $this->context([MedicaoPermissions::VIEW], true);
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '100',
            'nome' => 'Frente principal',
            'tipo' => 'pai',
        ]);
        $folha = FolhaRosto::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $user->id,
            'codigo' => 'FR-001',
            'sequencial' => 1,
            'comentario' => 'Pleito para teste de permissão.',
            'status' => 'analise_fiscal',
        ]);
        FolhaRostoAnaliseResponsavel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'created_by_id' => $user->id,
            'etapa' => 'fiscal',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.medicao.analisar-pleito.analise.store', [$tenant, $folha]), [])
            ->assertForbidden();
    }

    private function context(array $permissions, bool $oneContract = false): array
    {
        $tenant = Tenant::create([
            'slug' => 'medicao-'.str()->lower(str()->random(8)),
            'name' => 'Tenant Medicao',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engineer',
            'status' => 'active',
            'medicao_permissions' => $permissions,
        ]);
        $contractA = $this->contract($tenant, 'CT-MED-A');
        $this->participant($tenant, $contractA, $user, $permissions);

        if ($oneContract) {
            return [$tenant, $user, $contractA];
        }

        $contractB = $this->contract($tenant, 'CT-MED-B');
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
            'medicao_permissions' => $permissions,
        ]);
    }
}

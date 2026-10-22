<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\FolhaRosto;
use App\Models\FolhaRostoAnaliseResponsavel;
use App\Models\MedicaoItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\MedicaoPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_item_index_paginates_items_without_changing_contract_totals(): void
    {
        [$tenant, $user, $contract] = $this->context([MedicaoPermissions::VIEW], true);

        foreach (range(1, 51) as $sequence) {
            MedicaoItem::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'created_by_id' => $user->id,
                'source_type' => 'manual',
                'item' => '1.'.$sequence,
                'nivel' => 2,
                'item_type' => 'item',
                'codigo' => str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                'descricao' => 'Item de medição '.$sequence,
                'unidade' => 'UN',
                'quantidade_prevista' => 1,
                'valor_unitario' => 10,
                'valor_com_bdi' => 10,
                'valor_total' => 10,
            ]);
        }

        $this->actingAs($user)
            ->get(route('tenant.medicao.item.index', [
                'tenant' => $tenant,
                'contract_id' => $contract->id,
                'page' => 2,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Medicao/Itens')
                ->has('items', 1)
                ->where('items.0.item', '1.51')
                ->where('pagination.current_page', 2)
                ->where('pagination.last_page', 2)
                ->where('pagination.total', 51)
                ->where('pagination.from', 51)
                ->where('pagination.to', 51)
                ->where('stats.total_items', 51)
                ->where('stats.total_value', 510));
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

<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\OrdemServico;
use App\Models\OrdemServicoObraResponsavel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrdemServicoPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantOrdemServicoPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_macro_permissions_are_split_and_scoped_by_contract(): void
    {
        [$tenant, $user, $contractA, $contractB] = $this->context([
            OrdemServicoPermissions::VIEW,
        ]);

        ContractParticipant::query()
            ->where('contract_id', $contractB->id)
            ->where('user_id', $user->id)
            ->update(['ordem_servico_permissions' => []]);

        $this->actingAs($user)
            ->get(route('tenant.ordem-servico.os.index', [
                'tenant' => $tenant,
                'contract_id' => $contractA->id,
            ]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('tenant.ordem-servico.os.index', [
                'tenant' => $tenant,
                'contract_id' => $contractB->id,
            ]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.ordem-servico.analise.index', [
                'tenant' => $tenant,
                'contract_id' => $contractA->id,
            ]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.ordem-servico.responsaveis.index', [
                'tenant' => $tenant,
                'contract_id' => $contractA->id,
            ]))
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
                'ordem_servico_permissions' => [OrdemServicoPermissions::ANALYZE],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $participant = ContractParticipant::query()
            ->where('contract_id', $contract->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame([
            OrdemServicoPermissions::VIEW,
            OrdemServicoPermissions::ANALYZE,
        ], $participant->ordem_servico_permissions);
    }

    public function test_operational_responsibility_does_not_bypass_revoked_macro_permission(): void
    {
        [$tenant, $user, $contract] = $this->context([OrdemServicoPermissions::VIEW], true);
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '100',
            'nome' => 'Frente principal',
            'tipo' => 'pai',
        ]);
        $ordem = OrdemServico::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $user->id,
            'codigo' => 'CT-OS-A-100-OS-001',
            'sequencial' => 1,
            'titulo' => 'Executar servico',
            'custo_previsto' => 1000,
            'status' => 'em_analise',
        ]);
        OrdemServicoObraResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'user_id' => $user->id,
            'created_by_id' => $user->id,
            'tipo' => 'fiscal',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->patch(route('tenant.ordem-servico.os.analyze', [$tenant, $ordem]), [
                'observacao' => 'Analise tecnica.',
            ])
            ->assertForbidden();
    }

    public function test_settings_permission_is_independent_and_scoped_by_contract(): void
    {
        [$tenant, $user, $contract] = $this->context([OrdemServicoPermissions::SETTINGS], true);

        $this->actingAs($user)
            ->get(route('tenant.ordem-servico.settings.index', [
                'tenant' => $tenant,
                'contract_id' => $contract->id,
            ]))
            ->assertOk();

        $this->actingAs($user)
            ->patch(route('tenant.ordem-servico.settings.update', $tenant), [
                'contract_id' => $contract->id,
                'require_project' => true,
                'require_document' => false,
                'require_deadline' => true,
                'require_execution_responsible' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        [$otherTenant, $otherUser] = $this->context([OrdemServicoPermissions::RESPONSIBLES], true);

        $this->actingAs($otherUser)
            ->get(route('tenant.ordem-servico.settings.index', $otherTenant))
            ->assertForbidden();
    }

    public function test_metrics_permission_is_independent_and_scoped_by_contract(): void
    {
        [$tenant, $user, $contractA, $contractB] = $this->context([
            OrdemServicoPermissions::METRICS,
        ]);

        ContractParticipant::query()
            ->where('contract_id', $contractB->id)
            ->where('user_id', $user->id)
            ->update(['ordem_servico_permissions' => []]);

        $obraA = $contractA->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '100',
            'nome' => 'Frente A',
            'tipo' => 'pai',
        ]);
        $obraB = $contractB->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '200',
            'nome' => 'Frente B',
            'tipo' => 'pai',
        ]);

        foreach ([[$contractA, $obraA, 'CT-OS-A-100-OS-001'], [$contractB, $obraB, 'CT-OS-B-200-OS-001']] as [$contract, $obra, $code]) {
            OrdemServico::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'created_by_id' => $user->id,
                'codigo' => $code,
                'sequencial' => 1,
                'titulo' => "Ordem {$code}",
                'custo_previsto' => 1000,
                'status' => 'rascunho',
            ]);
        }

        $this->actingAs($user)
            ->get(route('tenant.ordem-servico.metrics.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/OrdemServico/Metrics')
                ->where('summary.total', 1)
                ->has('filterOptions.contracts', 1)
                ->where('filterOptions.contracts.0.id', $contractA->id));

        $this->actingAs($user)
            ->get(route('tenant.ordem-servico.metrics.index', [
                'tenant' => $tenant,
                'contract_id' => $contractB->id,
            ]))
            ->assertForbidden();
    }

    public function test_metrics_screen_requires_its_own_permission(): void
    {
        [$tenant, $user] = $this->context([OrdemServicoPermissions::VIEW], true);

        $this->actingAs($user)
            ->get(route('tenant.ordem-servico.metrics.index', $tenant))
            ->assertForbidden();
    }

    private function context(array $permissions, bool $oneContract = false): array
    {
        $tenant = Tenant::create([
            'slug' => 'ordem-servico-'.str()->lower(str()->random(8)),
            'name' => 'Tenant Ordem de Servico',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engineer',
            'status' => 'active',
            'ordem_servico_permissions' => $permissions,
        ]);
        $contractA = $this->contract($tenant, 'CT-OS-A');
        $this->participant($tenant, $contractA, $user, $permissions);

        if ($oneContract) {
            return [$tenant, $user, $contractA];
        }

        $contractB = $this->contract($tenant, 'CT-OS-B');
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
            'ordem_servico_permissions' => $permissions,
        ]);
    }
}

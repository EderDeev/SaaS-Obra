<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Orcamento;
use App\Models\OrcamentoAcesso;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BudgetPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantBudgetAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_is_private_to_creator_and_shared_users_while_admin_sees_all(): void
    {
        [$tenant, $creator, $viewer, $outsider, $admin] = $this->budgetUsers();
        $visible = $this->budget($tenant, $creator, 'ORC-001', 'Orcamento compartilhado');
        $hidden = $this->budget($tenant, $outsider, 'ORC-002', 'Orcamento privado');

        OrcamentoAcesso::create([
            'tenant_id' => $tenant->id,
            'orcamento_id' => $visible->id,
            'user_id' => $viewer->id,
            'access_level' => OrcamentoAcesso::LEVEL_VIEW,
            'granted_by_id' => $creator->id,
        ]);

        $this->actingAs($creator)
            ->get(route('tenant.orcamentos.index', $tenant))
            ->assertOk()
            ->assertSee('Orcamento compartilhado')
            ->assertDontSee('Orcamento privado');

        $this->actingAs($viewer)
            ->get(route('tenant.orcamentos.show', [$tenant, $visible]))
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('tenant.orcamentos.show', [$tenant, $hidden]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('tenant.orcamentos.index', $tenant))
            ->assertOk()
            ->assertSee('Orcamento compartilhado')
            ->assertSee('Orcamento privado');
    }

    public function test_creator_can_manage_accesses_but_another_shared_user_cannot(): void
    {
        [$tenant, $creator, $viewer, $outsider] = $this->budgetUsers();
        $budget = $this->budget($tenant, $creator);

        $this->actingAs($creator)
            ->put(route('tenant.orcamentos.accesses.update', [$tenant, $budget]), [
                'accesses' => [
                    ['user_id' => $viewer->id, 'access_level' => OrcamentoAcesso::LEVEL_VIEW],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('orcamento_acessos', [
            'orcamento_id' => $budget->id,
            'user_id' => $viewer->id,
            'access_level' => OrcamentoAcesso::LEVEL_VIEW,
        ]);

        $this->actingAs($viewer)
            ->put(route('tenant.orcamentos.accesses.update', [$tenant, $budget]), [
                'accesses' => [
                    ['user_id' => $outsider->id, 'access_level' => OrcamentoAcesso::LEVEL_VIEW],
                ],
            ])
            ->assertForbidden();
    }

    public function test_shared_user_with_macro_permission_can_manage_budget_accesses(): void
    {
        [$tenant, $creator, $viewer, $outsider] = $this->budgetUsers();
        $budget = $this->budget($tenant, $creator);

        $tenant->memberships()
            ->where('user_id', $viewer->id)
            ->update([
                'budget_permissions' => [
                    BudgetPermissions::VIEW,
                    BudgetPermissions::MANAGE_ACCESSES,
                ],
            ]);

        OrcamentoAcesso::create([
            'tenant_id' => $tenant->id,
            'orcamento_id' => $budget->id,
            'user_id' => $viewer->id,
            'access_level' => OrcamentoAcesso::LEVEL_VIEW,
            'granted_by_id' => $creator->id,
        ]);

        $this->actingAs($viewer)
            ->getJson(route('tenant.orcamentos.accesses.index', [$tenant, $budget]))
            ->assertOk()
            ->assertJsonStructure(['users'])
            ->assertJsonMissing(['id' => $outsider->id]);

        $this->actingAs($viewer)
            ->getJson(route('tenant.orcamentos.accesses.index', [$tenant, $budget]).'?search='.urlencode($outsider->email))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $outsider->id,
                'email' => $outsider->email,
            ]);

        $this->actingAs($viewer)
            ->put(route('tenant.orcamentos.accesses.update', [$tenant, $budget]), [
                'accesses' => [
                    ['user_id' => $outsider->id, 'access_level' => OrcamentoAcesso::LEVEL_VIEW],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('orcamento_acessos', [
            'orcamento_id' => $budget->id,
            'user_id' => $outsider->id,
            'access_level' => OrcamentoAcesso::LEVEL_VIEW,
        ]);
    }

    public function test_budget_access_user_picker_is_limited_to_twenty_results(): void
    {
        [$tenant, $creator] = $this->budgetUsers();
        $budget = $this->budget($tenant, $creator);

        foreach (range(1, 25) as $index) {
            $user = User::factory()->create([
                'name' => sprintf('Usuario busca %02d', $index),
                'email' => sprintf('busca%02d@example.test', $index),
            ]);

            $tenant->memberships()->create([
                'user_id' => $user->id,
                'role' => 'engenheiro_custos',
                'status' => 'active',
                'budget_permissions' => [BudgetPermissions::VIEW],
            ]);
        }

        $response = $this->actingAs($creator)
            ->getJson(route('tenant.orcamentos.accesses.index', [$tenant, $budget]).'?available=1')
            ->assertOk();

        $this->assertCount(20, $response->json('users'));
    }

    public function test_granting_budget_access_adds_the_required_global_permission(): void
    {
        [$tenant, $creator, $viewer] = $this->budgetUsers();
        $budget = $this->budget($tenant, $creator);

        $tenant->memberships()
            ->where('user_id', $viewer->id)
            ->update(['budget_permissions' => []]);

        $this->actingAs($creator)
            ->put(route('tenant.orcamentos.accesses.update', [$tenant, $budget]), [
                'accesses' => [
                    ['user_id' => $viewer->id, 'access_level' => OrcamentoAcesso::LEVEL_EDIT],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('orcamento_acessos', [
            'orcamento_id' => $budget->id,
            'user_id' => $viewer->id,
            'access_level' => OrcamentoAcesso::LEVEL_EDIT,
        ]);

        $membership = $tenant->memberships()->where('user_id', $viewer->id)->firstOrFail();

        $this->assertContains(BudgetPermissions::VIEW, $membership->budget_permissions);
        $this->assertContains(BudgetPermissions::EDIT, $membership->budget_permissions);

        $this->actingAs($viewer)
            ->get(route('tenant.orcamentos.show', [$tenant, $budget]))
            ->assertOk();
    }

    public function test_shared_view_and_edit_levels_control_budget_mutations(): void
    {
        [$tenant, $creator, $viewer] = $this->budgetUsers();
        $budget = $this->budget($tenant, $creator);

        $this->actingAs($creator)
            ->put(route('tenant.orcamentos.accesses.update', [$tenant, $budget]), [
                'accesses' => [
                    ['user_id' => $viewer->id, 'access_level' => OrcamentoAcesso::LEVEL_VIEW],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('orcamento_acessos', [
            'orcamento_id' => $budget->id,
            'user_id' => $viewer->id,
            'access_level' => OrcamentoAcesso::LEVEL_VIEW,
        ]);

        $this->actingAs($viewer)
            ->post(route('tenant.orcamentos.etapas.store', [$tenant, $budget]), [
                'descricao' => 'Etapa bloqueada',
            ])
            ->assertForbidden();

        $this->actingAs($creator)
            ->put(route('tenant.orcamentos.accesses.update', [$tenant, $budget]), [
                'accesses' => [
                    ['user_id' => $viewer->id, 'access_level' => OrcamentoAcesso::LEVEL_EDIT],
                ],
            ])
            ->assertRedirect();

        $this->actingAs($viewer)
            ->post(route('tenant.orcamentos.etapas.store', [$tenant, $budget]), [
                'descricao' => 'Etapa autorizada',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('orcamento_etapas', [
            'orcamento_id' => $budget->id,
            'descricao' => 'Etapa autorizada',
        ]);
    }

    public function test_creator_cannot_edit_when_global_edit_permission_is_removed(): void
    {
        [$tenant, $creator] = $this->budgetUsers();
        $budget = $this->budget($tenant, $creator);

        $tenant->memberships()
            ->where('user_id', $creator->id)
            ->update([
                'budget_permissions' => [
                    BudgetPermissions::VIEW,
                    BudgetPermissions::CREATE,
                ],
            ]);

        $this->actingAs($creator)
            ->get(route('tenant.orcamentos.show', [$tenant, $budget]))
            ->assertOk();

        $this->actingAs($creator)
            ->post(route('tenant.orcamentos.etapas.store', [$tenant, $budget]), [
                'descricao' => 'Etapa bloqueada para o criador',
            ])
            ->assertForbidden();
    }

    public function test_global_permissions_protect_module_creation_reports_and_catalog_management(): void
    {
        [$tenant, $creator, $viewer] = $this->budgetUsers();
        $budget = $this->budget($tenant, $creator);

        $tenant->memberships()
            ->where('user_id', $viewer->id)
            ->update(['budget_permissions' => []]);

        $this->actingAs($viewer)
            ->get(route('tenant.orcamentos.index', $tenant))
            ->assertForbidden();

        $tenant->memberships()
            ->where('user_id', $viewer->id)
            ->update(['budget_permissions' => [BudgetPermissions::VIEW]]);

        OrcamentoAcesso::create([
            'tenant_id' => $tenant->id,
            'orcamento_id' => $budget->id,
            'user_id' => $viewer->id,
            'access_level' => OrcamentoAcesso::LEVEL_VIEW,
            'granted_by_id' => $creator->id,
        ]);

        $this->actingAs($viewer)
            ->get(route('tenant.orcamentos.create', $tenant))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('tenant.orcamentos.relatorios.sintetico', [$tenant, $budget]))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('tenant.orcamentos.insumos.store', $tenant), [])
            ->assertForbidden();
    }

    public function test_permissions_page_saves_global_budget_permissions(): void
    {
        [$tenant, $creator, $viewer, $outsider, $admin] = $this->budgetUsers();
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato teste',
            'status' => 'active',
        ]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $viewer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->patch(route('tenant.permissions.update', $tenant), [
                'user_id' => $viewer->id,
                'contract_id' => $contract->id,
                'activity_permissions' => [],
                'project_permissions' => [],
                'rnc_permissions' => [],
                'user_permissions' => [],
                'parametrizacao_permissions' => [],
                'budget_permissions' => [
                    BudgetPermissions::EDIT,
                    BudgetPermissions::MANAGE_ACCESSES,
                    BudgetPermissions::REPORTS,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $membership = $tenant->memberships()->where('user_id', $viewer->id)->firstOrFail();

        $this->assertSame([
            BudgetPermissions::VIEW,
            BudgetPermissions::EDIT,
            BudgetPermissions::MANAGE_ACCESSES,
            BudgetPermissions::REPORTS,
        ], $membership->budget_permissions);
    }

    public function test_global_budget_permissions_can_be_saved_without_a_contract_link(): void
    {
        [$tenant, $creator, $viewer, $outsider, $admin] = $this->budgetUsers();

        $this->actingAs($admin)
            ->patch(route('tenant.permissions.update', $tenant), [
                'user_id' => $viewer->id,
                'contract_id' => null,
                'activity_permissions' => [],
                'project_permissions' => [],
                'rnc_permissions' => [],
                'user_permissions' => [],
                'parametrizacao_permissions' => [],
                'budget_permissions' => [
                    BudgetPermissions::EDIT,
                    BudgetPermissions::REPORTS,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $membership = $tenant->memberships()->where('user_id', $viewer->id)->firstOrFail();

        $this->assertSame([
            BudgetPermissions::VIEW,
            BudgetPermissions::EDIT,
            BudgetPermissions::REPORTS,
        ], $membership->budget_permissions);
    }

    /**
     * @return array{Tenant, User, User, User, User}
     */
    private function budgetUsers(): array
    {
        $tenant = Tenant::create([
            'slug' => 'budget-test',
            'name' => 'Tenant de orcamentos',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $creator = User::factory()->create();
        $viewer = User::factory()->create();
        $outsider = User::factory()->create();
        $admin = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $creator->id,
            'role' => 'engenheiro_custos',
            'status' => 'active',
            'budget_permissions' => [
                BudgetPermissions::VIEW,
                BudgetPermissions::CREATE,
                BudgetPermissions::EDIT,
                BudgetPermissions::REPORTS,
            ],
        ]);
        $tenant->memberships()->create([
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'status' => 'active',
            'budget_permissions' => [BudgetPermissions::VIEW],
        ]);
        $tenant->memberships()->create([
            'user_id' => $outsider->id,
            'role' => 'viewer',
            'status' => 'active',
            'budget_permissions' => [BudgetPermissions::VIEW],
        ]);
        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
            'budget_permissions' => BudgetPermissions::all(),
        ]);

        return [$tenant, $creator, $viewer, $outsider, $admin];
    }

    private function budget(Tenant $tenant, User $creator, string $code = 'ORC-001', string $description = 'Orcamento teste'): Orcamento
    {
        return Orcamento::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $creator->id,
            'codigo' => $code,
            'descricao' => $description,
            'categoria' => 'Outros',
            'arredondamento' => 'truncate_all_2',
            'encargos_sociais' => 'desonerado',
            'bdi_tipo' => 'unit_price',
            'bdi_percentual' => 0,
            'status' => 'draft',
        ]);
    }
}

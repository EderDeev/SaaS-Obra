<?php

namespace Tests\Feature;

use App\Models\{Contract, ContractParticipant, Tenant, TipoEmpresa, User};
use App\Support\{OrdemServicoPermissions, OsDnitPermissions as P};
use App\Support\{ActivityPermissions, ContractPermissions, DiarioObraPermissions, DocumentationPermissions, MedicaoPermissions, ProjectPermissions, RncPermissions};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantUserOsDnitPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private array $payload;
    private ContractParticipant $a;
    private ContractParticipant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Permissões', 'slug' => 'user-dnit', 'plan' => 'starter', 'status' => 'active']);
        $owner = User::factory()->create();
        $this->user = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $owner->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $this->tenant->memberships()->create(['user_id' => $this->user->id, 'role' => 'engineer', 'status' => 'active']);
        foreach (['a', 'b'] as $key) {
            $contract = Contract::create(['tenant_id' => $this->tenant->id, 'code' => strtoupper($key), 'name' => 'Contrato '.$key, 'status' => 'active']);
            $this->$key = ContractParticipant::create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id,
                'user_id' => $this->user->id, 'side' => 'manager', 'role' => 'team_member', 'status' => 'active', 'os_dnit_permissions' => [P::VIEW]]);
        }
        $empresa = $this->tenant->empresas()->create(['contract_id' => $this->a->contract_id,
            'tipo_empresa_id' => TipoEmpresa::firstOrCreate(['nome' => 'gerenciadora'])->id,
            'nome' => 'Empresa', 'cnpj' => '44.444.444/0001-44', 'sigla' => 'EMP']);
        $this->payload = ['name' => $this->user->name, 'email' => $this->user->email, 'empresa_id' => $empresa->id, 'role' => 'engineer',
            'contract_accesses' => array_map(fn ($participant) => ['contract_id' => $participant->contract_id, 'side' => 'manager', 'role' => 'team_member'], [$this->a, $this->b])];
        $this->actingAs($owner);
    }

    public function test_both_screens_expose_the_same_permissions_and_updated_service_release_labels(): void
    {
        $this->a->update(['os_dnit_permissions' => null]);
        $this->b->update(['os_dnit_permissions' => []]);
        $this->get(route('tenant.users.index', $this->tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('contractPermissionGroups.os_dnit_permissions.permissions', P::labels())
            ->where('contractPermissionGroups.ordem_servico_permissions.label', 'Liberação de Serviço')
            ->where('contractPermissionGroups.ordem_servico_permissions.permissions', OrdemServicoPermissions::labels())
            ->where('memberships', function ($memberships) {
                $accesses = collect($memberships)->firstWhere('user_id', $this->user->id)['contract_accesses'];
                $this->assertSame(P::defaultForRole('engineer'), collect($accesses)->firstWhere('contract_id', $this->a->contract_id)['os_dnit_permissions']);
                $this->assertSame([], collect($accesses)->firstWhere('contract_id', $this->b->contract_id)['os_dnit_permissions']);
                return true;
            }));
        $this->get(route('tenant.permissions.index', $this->tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('permissionGroups.os_dnit.permissions', P::labels())
            ->where('permissionGroups.ordem_servico.label', 'Liberação de Serviço'));
    }

    public function test_user_edit_normalizes_grants_per_contract_preserves_omitted_groups_and_allows_revocation(): void
    {
        $membership = $this->tenant->memberships()->where('user_id', $this->user->id)->sole();
        $url = route('tenant.users.update', [$this->tenant, $membership]);
        $data = $this->payload;
        $data['contract_accesses'][0]['os_dnit_permissions'] = [P::EDIT_PRODUCTS];
        $this->patch($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([P::VIEW, P::VIEW_PRODUCTS, P::EDIT_PRODUCTS], $this->a->fresh()->os_dnit_permissions);
        $this->assertSame([P::VIEW], $this->b->fresh()->os_dnit_permissions);
        $this->patch($url, $this->payload)->assertSessionHasNoErrors();
        $this->assertContains(P::EDIT_PRODUCTS, $this->a->fresh()->os_dnit_permissions);
        $data['contract_accesses'][0]['os_dnit_permissions'] = [];
        $this->patch($url, $data)->assertSessionHasNoErrors();
        $this->assertSame([], $this->a->fresh()->os_dnit_permissions);
        $this->actingAs($this->user)->get(route('tenant.os-dnit.orders.index', [$this->tenant, 'contract_id' => $this->a->contract_id]))->assertSessionHasErrors('contract_id');
    }

    public function test_unknown_permissions_and_contracts_of_another_tenant_are_rejected(): void
    {
        $membership = $this->tenant->memberships()->where('user_id', $this->user->id)->sole();
        $url = route('tenant.users.update', [$this->tenant, $membership]);
        $data = $this->payload;
        $data['contract_accesses'][0]['os_dnit_permissions'] = ['grant_everything'];
        $this->patch($url, $data)->assertSessionHasErrors('contract_accesses.0.os_dnit_permissions.0');
        $other = Tenant::create(['name' => 'Outro', 'slug' => 'other-user-dnit', 'plan' => 'starter', 'status' => 'active']);
        $contract = Contract::create(['tenant_id' => $other->id, 'code' => 'OTHER', 'name' => 'Outro contrato', 'status' => 'active']);
        $data['contract_accesses'][0] = ['contract_id' => $contract->id, 'side' => 'manager', 'role' => 'team_member', 'os_dnit_permissions' => [P::VIEW]];
        $this->patch($url, $data)->assertSessionHasErrors('contract_accesses.0.contract_id');
        $this->assertSame([P::VIEW], $this->a->fresh()->os_dnit_permissions);
        $this->assertDatabaseMissing('contract_participants', ['user_id' => $this->user->id, 'contract_id' => $contract->id]);
    }

    public function test_editing_a_user_adds_permissions_from_every_module_only_in_the_selected_contract(): void
    {
        $membership = $this->tenant->memberships()->where('user_id', $this->user->id)->sole();
        $groups = [
            'activity_permissions' => [ActivityPermissions::class, ActivityPermissions::CREATE],
            'contract_permissions' => [ContractPermissions::class, ContractPermissions::ADDITIVES],
            'project_permissions' => [ProjectPermissions::class, ProjectPermissions::UPLOAD],
            'documentation_permissions' => [DocumentationPermissions::class, DocumentationPermissions::OCR],
            'diario_obra_permissions' => [DiarioObraPermissions::class, DiarioObraPermissions::FILL_RDO],
            'ordem_servico_permissions' => [OrdemServicoPermissions::class, OrdemServicoPermissions::MANAGE_DRAFTS],
            'medicao_permissions' => [MedicaoPermissions::class, MedicaoPermissions::ANALYZE],
        ];
        $data = $this->payload;
        foreach ($groups as $field => [$permissions, $permission]) {
            $data['contract_accesses'][0][$field] = [$permission];
            $data['contract_accesses'][1][$field] = [];
        }
        $data['contract_accesses'][0]['rnc_permissions'] = [RncPermissions::EDIT];
        $data['contract_accesses'][0]['os_dnit_permissions'] = [P::VIEW];
        $data['contract_accesses'][1]['os_dnit_permissions'] = [P::VIEW];
        $this->patch(route('tenant.users.update', [$this->tenant, $membership]), $data)->assertSessionHasNoErrors();
        foreach ($groups as $field => [$permissions, $permission]) {
            $this->assertSame($permissions::normalize([$permission]), $this->a->fresh()->$field);
            $this->assertSame([], $this->b->fresh()->$field);
        }
        $this->assertDatabaseHas('relatorio_nao_conformidade_responsaveis', ['tenant_id' => $this->tenant->id,
            'contract_id' => $this->a->contract_id, 'user_id' => $this->user->id, 'status' => 'active']);
        $this->get(route('tenant.users.index', $this->tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('memberships', function ($memberships) use ($groups) {
                $accesses = collect(collect($memberships)->firstWhere('user_id', $this->user->id)['contract_accesses']);
                foreach ($groups as $field => [$permissions, $permission]) {
                    $this->assertSame($permissions::normalize([$permission]), $accesses->firstWhere('contract_id', $this->a->contract_id)[$field]);
                    $this->assertSame([], $accesses->firstWhere('contract_id', $this->b->contract_id)[$field]);
                }
                return true;
            }));
    }

    public function test_editing_an_administrator_saves_general_module_permissions_without_changing_os_dnit_contract_access(): void
    {
        $membership = $this->tenant->memberships()->where('user_id', $this->user->id)->sole();
        $membership->update(['role' => 'tenant_admin', 'activity_permissions' => [], 'project_permissions' => [ProjectPermissions::VIEW]]);
        $data = collect($this->payload)->except('contract_accesses')->all();
        $data['role'] = 'tenant_admin';
        $data['tenant_module_permissions'] = ['activity_permissions' => [ActivityPermissions::CREATE]];
        $this->patch(route('tenant.users.update', [$this->tenant, $membership]), $data)->assertSessionHasNoErrors();
        $this->assertSame(ActivityPermissions::normalize([ActivityPermissions::CREATE]), $membership->fresh()->activity_permissions);
        $this->assertSame([ProjectPermissions::VIEW], $membership->fresh()->project_permissions);
        $this->assertTrue(ActivityPermissions::canAny($this->user, $this->tenant, ActivityPermissions::CREATE));
        $this->assertSame([P::VIEW], $this->a->fresh()->os_dnit_permissions);
        $this->assertSame([P::VIEW], $this->b->fresh()->os_dnit_permissions);
        $this->get(route('tenant.users.index', $this->tenant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('memberships', function ($memberships) {
                $permissions = collect($memberships)->firstWhere('user_id', $this->user->id)['tenant_module_permissions'];
                $this->assertContains(ActivityPermissions::CREATE, $permissions['activity_permissions']);
                return true;
            }));
        $data['tenant_module_permissions'] = ['os_dnit_permissions' => P::all()];
        $this->patch(route('tenant.users.update', [$this->tenant, $membership]), $data)->assertSessionHasErrors('tenant_module_permissions');
    }
}

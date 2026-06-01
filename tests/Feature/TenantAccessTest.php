<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use App\Models\User;
use App\Notifications\UserTemporaryPasswordNotification;
use App\Support\ActivityPermissions;
use App\Support\ParametrizacaoPermissions;
use App\Support\ProjectPermissions;
use App\Support\RncPermissions;
use App\Support\UserPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TenantAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_create_tenant_with_formatted_cnpj(): void
    {
        Notification::fake();

        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);

        $this->actingAs($platformAdmin)
            ->post(route('platform.tenants.store'), [
                'name' => 'Tenant Demo',
                'slug' => 'tenant-demo',
                'cnpj' => '12345678000190',
                'plan' => 'starter',
                'status' => 'active',
                'owner_name' => 'Owner Demo',
                'owner_email' => 'owner-demo@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tenants', [
            'slug' => 'tenant-demo',
            'cnpj' => '12.345.678/0001-90',
        ]);

        $owner = User::where('email', 'owner-demo@example.com')->firstOrFail();

        $this->assertTrue($owner->must_change_password);
        Notification::assertSentTo($owner, UserTemporaryPasswordNotification::class);
    }

    public function test_platform_admin_sends_temporary_password_to_existing_owner_when_creating_tenant(): void
    {
        Notification::fake();

        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);
        $owner = User::factory()->create([
            'name' => 'Owner Existente',
            'email' => 'owner-existente@example.com',
            'password' => Hash::make('SenhaAntiga1!'),
            'must_change_password' => false,
        ]);

        $this->actingAs($platformAdmin)
            ->post(route('platform.tenants.store'), [
                'name' => 'Tenant Existente',
                'slug' => 'tenant-existente',
                'cnpj' => '12345678000190',
                'plan' => 'starter',
                'status' => 'active',
                'owner_name' => 'Owner Existente',
                'owner_email' => 'owner-existente@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $owner->refresh();

        $this->assertTrue($owner->must_change_password);
        $this->assertNotNull($owner->temporary_password_created_at);
        $this->assertFalse(Hash::check('SenhaAntiga1!', $owner->password));

        Notification::assertSentTo($owner, UserTemporaryPasswordNotification::class);
    }

    public function test_platform_admin_cannot_create_tenant_with_incomplete_cnpj(): void
    {
        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);

        $this->actingAs($platformAdmin)
            ->post(route('platform.tenants.store'), [
                'name' => 'Tenant Demo',
                'slug' => 'tenant-demo',
                'cnpj' => '12.345.678/0001',
                'plan' => 'starter',
                'status' => 'active',
                'owner_name' => 'Owner Demo',
                'owner_email' => 'owner-demo@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('cnpj');

        $this->assertDatabaseMissing('tenants', [
            'slug' => 'tenant-demo',
        ]);
    }

    public function test_platform_admin_can_open_any_tenant_dashboard(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);
        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('tenant.dashboard', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Dashboard', false);
    }

    public function test_platform_admin_can_open_any_tenant_contract_portfolio_and_details(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);
        $document = $contract->projectDocuments()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Projeto com revisão',
            'status' => 'em_analise',
        ]);
        $document->versions()->create([
            'tenant_id' => $tenant->id,
            'revision' => 'R00',
            'original_name' => 'projeto-r00.pdf',
            'file_path' => 'projects/projeto-r00.pdf',
        ]);
        $document->versions()->create([
            'tenant_id' => $tenant->id,
            'revision' => 'R01',
            'original_name' => 'projeto-r01.pdf',
            'file_path' => 'projects/projeto-r01.pdf',
        ]);
        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('tenant.contracts.index', $tenant))
            ->assertOk()
            ->assertSee('CT-001');

        $this->actingAs($platformAdmin)
            ->get(route('tenant.contracts.show', [$tenant, $contract]))
            ->assertOk()
            ->assertSee('Tenant\/Contracts\/Show', false)
            ->assertSee('R01');
    }

    public function test_platform_admin_can_open_aps_usage_panel(): void
    {
        config([
            'services.autodesk_aps.client_id' => null,
            'services.autodesk_aps.client_secret' => null,
            'services.autodesk_aps.bucket_key' => null,
        ]);

        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('platform.aps.index'))
            ->assertOk()
            ->assertSee('Platform\/Aps\/Index', false);
    }

    public function test_external_participant_only_sees_own_contracts(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();

        $visible = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato Visivel',
            'status' => 'active',
        ]);
        Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-002',
            'name' => 'Contrato Oculto',
            'status' => 'active',
        ]);

        $visible->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'client',
            'role' => 'client_viewer',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.contracts.index', $tenant))
            ->assertOk()
            ->assertSee('Contrato Visivel')
            ->assertDontSee('Contrato Oculto');
    }

    public function test_external_participant_cannot_manage_tenant_users(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'client',
            'role' => 'client_viewer',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.users.index', $tenant))
            ->assertForbidden();
    }

    public function test_tenant_admin_can_access_user_management(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.users.index', $tenant))
            ->assertOk();
    }

    public function test_tenant_user_can_access_tutorials_page(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.tutorials.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Tutorials\/Index', false);
    }

    public function test_tenant_user_can_access_quality_rnc_page(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $contract = $tenant->contracts()->create([
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);

        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);
        RelatorioNaoConformidadeResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $user->id,
            'status' => 'active',
            'permissions' => [RncPermissions::VIEW],
        ]);

        $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Qualidade\/RelatorioNaoConformidade\/Index', false);
    }

    public function test_tenant_admin_can_create_user_linked_to_empresa(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);
        $tipo = TipoEmpresa::where('nome', 'gerenciadora')->firstOrFail();
        $empresa = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $tipo->id,
            'nome' => 'Gerenciadora Alfa',
            'cnpj' => '44.444.444/0001-44',
            'sigla' => 'ALFA',
        ]);

        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('tenant.users.store', $tenant), [
                'name' => 'Usuario Operacional',
                'email' => 'operacional@example.com',
                'empresa_id' => $empresa->id,
                'role' => 'engineer',
            ])
            ->assertRedirect();

        $user = User::where('email', 'operacional@example.com')->firstOrFail();

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'empresa_id' => $empresa->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);
    }

    public function test_tenant_admin_can_update_user_membership(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $managedUser = User::factory()->create([
            'name' => 'Usuario Antigo',
            'email' => 'usuario-antigo@example.com',
        ]);
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);
        $tipo = TipoEmpresa::where('nome', 'gerenciadora')->firstOrFail();
        $empresaA = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $tipo->id,
            'nome' => 'Gerenciadora Alfa',
            'cnpj' => '44.444.444/0001-44',
            'sigla' => 'ALFA',
        ]);
        $empresaB = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $tipo->id,
            'nome' => 'Gerenciadora Beta',
            'cnpj' => '55.555.555/0001-55',
            'sigla' => 'BETA',
        ]);

        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);
        $membership = $tenant->memberships()->create([
            'user_id' => $managedUser->id,
            'empresa_id' => $empresaA->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->patch(route('tenant.users.update', [$tenant, $membership]), [
                'name' => 'Usuario Editado',
                'email' => 'usuario-editado@example.com',
                'empresa_id' => $empresaB->id,
                'role' => 'financial',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $managedUser->id,
            'name' => 'Usuario Editado',
            'email' => 'usuario-editado@example.com',
        ]);
        $this->assertDatabaseHas('tenant_users', [
            'id' => $membership->id,
            'tenant_id' => $tenant->id,
            'user_id' => $managedUser->id,
            'empresa_id' => $empresaB->id,
            'role' => 'financial',
            'status' => 'active',
        ]);
    }

    public function test_tenant_admin_can_deactivate_user_membership(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $managedUser = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);
        $membership = $tenant->memberships()->create([
            'user_id' => $managedUser->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->patch(route('tenant.users.deactivate', [$tenant, $membership]))
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_users', [
            'id' => $membership->id,
            'tenant_id' => $tenant->id,
            'user_id' => $managedUser->id,
            'status' => 'inactive',
        ]);
    }

    public function test_tenant_admin_can_create_contract_with_currency(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.contracts.store', $tenant), [
                'code' => 'ct-usd-001',
                'total_value' => '1250.50',
                'currency' => 'USD',
                'city' => 'Campinas',
                'state' => 'SP',
                'starts_at' => '2026-06-01',
                'ends_at' => '2026-12-31',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('contracts', [
            'tenant_id' => $tenant->id,
            'code' => 'CT-USD-001',
            'currency' => 'USD',
            'city' => 'Campinas',
            'state' => 'SP',
        ]);

        $contract = Contract::where('tenant_id', $tenant->id)->where('code', 'CT-USD-001')->firstOrFail();

        $this->assertSame('1250.50', $contract->total_value);
        $this->assertSame('2026-06-01', $contract->starts_at->toDateString());
        $this->assertSame('2026-12-31', $contract->ends_at->toDateString());
    }

    public function test_internal_operational_user_only_sees_linked_contracts(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $visible = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato Visivel',
            'status' => 'active',
        ]);
        $hidden = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-002',
            'name' => 'Contrato Oculto',
            'status' => 'active',
        ]);

        $visible->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.contracts.index', $tenant))
            ->assertOk()
            ->assertSee('Contrato Visivel')
            ->assertDontSee('Contrato Oculto');

        $this->actingAs($user)
            ->get(route('tenant.contracts.show', [$tenant, $visible]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('tenant.contracts.show', [$tenant, $hidden]))
            ->assertForbidden();
    }

    public function test_tenant_owner_can_open_permissions_page(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $owner = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'tenant_owner',
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->get(route('tenant.permissions.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Permissions\/Index', false);
    }

    public function test_tenant_owner_can_update_user_permissions_by_contract(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $owner = User::factory()->create();
        $managedUser = User::factory()->create();
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);

        $tenant->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'tenant_owner',
            'status' => 'active',
        ]);
        $membership = $tenant->memberships()->create([
            'user_id' => $managedUser->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);
        $participant = $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $managedUser->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->patch(route('tenant.permissions.update', $tenant), [
                'user_id' => $managedUser->id,
                'contract_id' => $contract->id,
                'activity_permissions' => [
                    ActivityPermissions::VIEW,
                    ActivityPermissions::CREATE,
                ],
                'project_permissions' => [
                    ProjectPermissions::VIEW,
                    ProjectPermissions::UPLOAD,
                ],
                'rnc_permissions' => [
                    RncPermissions::VIEW,
                    RncPermissions::EDIT,
                ],
                'user_permissions' => [
                    UserPermissions::VIEW,
                ],
                'parametrizacao_permissions' => [
                    ParametrizacaoPermissions::EMPRESAS,
                ],
            ])
            ->assertRedirect();

        $participant->refresh();
        $membership->refresh();
        $responsavel = RelatorioNaoConformidadeResponsavel::where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->where('user_id', $managedUser->id)
            ->firstOrFail();

        $this->assertSame([ActivityPermissions::VIEW, ActivityPermissions::CREATE], $participant->activity_permissions);
        $this->assertSame([ProjectPermissions::VIEW, ProjectPermissions::UPLOAD], $participant->project_permissions);
        $this->assertSame([UserPermissions::VIEW], $membership->user_permissions);
        $this->assertSame([ParametrizacaoPermissions::VIEW, ParametrizacaoPermissions::EMPRESAS], $membership->parametrizacao_permissions);
        $this->assertSame([RncPermissions::VIEW, RncPermissions::EDIT], $responsavel->permissions);
        $this->assertSame('active', $responsavel->status);
    }

    public function test_user_with_parametrizacao_empresas_permission_can_only_open_empresas_submenu(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engineer',
            'status' => 'active',
            'parametrizacao_permissions' => [
                ParametrizacaoPermissions::VIEW,
                ParametrizacaoPermissions::EMPRESAS,
            ],
        ]);
        $tenant->contracts()->create([
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.parametrizacao.empresas.index', $tenant))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('tenant.parametrizacao.obras.index', $tenant))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.parametrizacao.disciplinas.index', $tenant))
            ->assertForbidden();
    }

    public function test_user_without_parametrizacao_permission_cannot_open_parametrizacao(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engineer',
            'status' => 'active',
            'parametrizacao_permissions' => [],
        ]);

        $this->actingAs($user)
            ->get(route('tenant.parametrizacao.empresas.index', $tenant))
            ->assertForbidden();
    }
}

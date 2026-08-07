<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use App\Models\User;
use App\Support\ActivityPermissions;
use App\Support\BudgetPermissions;
use App\Support\ContractPermissions;
use App\Support\DiarioObraPermissions;
use App\Support\DocumentationPermissions;
use App\Support\MedicaoPermissions;
use App\Support\OrdemServicoPermissions;
use App\Support\ParametrizacaoPermissions;
use App\Support\ProjectPermissions;
use App\Support\RncPermissions;
use App\Support\UserPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantUserCreationPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_creation_saves_all_current_macro_permissions(): void
    {
        $tenant = Tenant::create([
            'slug' => 'usuarios-permissoes',
            'name' => 'Tenant Usuarios',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $managedUser = User::factory()->create(['email' => 'novo.usuario@example.com']);
        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-USR-001',
            'name' => 'Contrato Usuarios',
            'status' => 'active',
        ]);
        $tipo = TipoEmpresa::firstOrCreate(['nome' => 'gerenciadora']);
        $empresa = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $tipo->id,
            'nome' => 'Gerenciadora Usuarios',
            'cnpj' => '44.444.444/0001-44',
            'sigla' => 'GUS',
        ]);

        $this->actingAs($admin)
            ->post(route('tenant.users.store', $tenant), [
                'name' => $managedUser->name,
                'email' => $managedUser->email,
                'empresa_id' => $empresa->id,
                'role' => 'engineer',
                'user_permissions' => [UserPermissions::VIEW],
                'parametrizacao_permissions' => [ParametrizacaoPermissions::MANAGE_EMPRESAS],
                'budget_permissions' => [BudgetPermissions::EDIT],
                'contract_accesses' => [[
                    'contract_id' => $contract->id,
                    'side' => 'manager',
                    'role' => 'team_member',
                    'activity_permissions' => [ActivityPermissions::CREATE],
                    'contract_permissions' => [ContractPermissions::ADDITIVES],
                    'project_permissions' => [ProjectPermissions::UPLOAD],
                    'rnc_permissions' => [RncPermissions::EDIT],
                    'documentation_permissions' => [DocumentationPermissions::OCR],
                    'diario_obra_permissions' => [DiarioObraPermissions::FILL_RDO],
                    'ordem_servico_permissions' => [OrdemServicoPermissions::MANAGE_DRAFTS],
                    'medicao_permissions' => [MedicaoPermissions::ANALYZE],
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $membership = $tenant->memberships()->where('user_id', $managedUser->id)->firstOrFail();
        $participant = ContractParticipant::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->where('user_id', $managedUser->id)
            ->firstOrFail();

        $this->assertSame([BudgetPermissions::VIEW, BudgetPermissions::EDIT], $membership->budget_permissions);
        $this->assertSame([ContractPermissions::VIEW, ContractPermissions::ADDITIVES], $participant->contract_permissions);
        $this->assertSame([DocumentationPermissions::VIEW, DocumentationPermissions::OCR], $participant->documentation_permissions);
        $this->assertSame([DiarioObraPermissions::VIEW, DiarioObraPermissions::FILL_RDO], $participant->diario_obra_permissions);
        $this->assertSame([OrdemServicoPermissions::VIEW, OrdemServicoPermissions::MANAGE_DRAFTS], $participant->ordem_servico_permissions);
        $this->assertSame([MedicaoPermissions::VIEW, MedicaoPermissions::ANALYZE], $participant->medicao_permissions);
        $this->assertSame('/t/'.$tenant->slug.'/usuarios', route('tenant.users.index', $tenant, false));
    }
}

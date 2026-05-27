<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\ContractParticipant;
use App\Models\RelatorioNaoConformidade;
use App\Models\TipoEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantParametrizacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owner_can_access_empresa_parametrizacao(): void
    {
        [$tenant, $owner] = $this->tenantWithUser('tenant_owner');

        $this->actingAs($owner)
            ->get(route('tenant.parametrizacao.empresas.index', $tenant))
            ->assertOk();
    }

    public function test_cliente_tipo_empresa_exists(): void
    {
        $this->assertDatabaseHas('tipos_empresa', [
            'nome' => 'cliente',
        ]);
    }

    public function test_operational_user_cannot_access_empresa_parametrizacao(): void
    {
        [$tenant, $engineer] = $this->tenantWithUser('engineer');

        $this->actingAs($engineer)
            ->get(route('tenant.parametrizacao.empresas.index', $tenant))
            ->assertForbidden();
    }

    public function test_tenant_admin_can_create_empresa_with_normalized_cnpj(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $tipo = TipoEmpresa::where('nome', 'construtora')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('tenant.parametrizacao.empresas.store', $tenant), [
                'nome' => 'Construtora Horizonte',
                'contract_id' => $contract->id,
                'cnpj' => '12345678000190',
                'sigla' => 'chz',
                'tipo_empresa_id' => $tipo->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('empresas', [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Construtora Horizonte',
            'cnpj' => '12.345.678/0001-90',
            'sigla' => 'CHZ',
            'tipo_empresa_id' => $tipo->id,
        ]);
    }

    public function test_tenant_admin_can_create_empresa_with_optional_logo(): void
    {
        Storage::fake('public');

        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $tipo = TipoEmpresa::where('nome', 'cliente')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('tenant.parametrizacao.empresas.store', $tenant), [
                'nome' => 'Cliente Delta',
                'contract_id' => $contract->id,
                'cnpj' => '98.765.432/0001-10',
                'sigla' => 'DELTA',
                'tipo_empresa_id' => $tipo->id,
                'logo' => UploadedFile::fake()->image('logo.png', 320, 180),
            ])
            ->assertRedirect();

        $empresa = $tenant->empresas()->where('nome', 'Cliente Delta')->firstOrFail();

        $this->assertNotNull($empresa->logo_path);
        $this->assertSame('/storage/'.ltrim($empresa->logo_path, '/'), $empresa->logo_url);
        Storage::disk('public')->assertExists($empresa->logo_path);
    }

    public function test_tenant_admin_can_update_empresa_and_add_logo_later(): void
    {
        Storage::fake('public');

        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $tipo = TipoEmpresa::where('nome', 'construtora')->firstOrFail();
        $empresa = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $tipo->id,
            'nome' => 'Construtora Horizonte',
            'cnpj' => '12.345.678/0001-90',
            'sigla' => 'CHZ',
        ]);

        $this->actingAs($admin)
            ->post(route('tenant.parametrizacao.empresas.update', [$tenant, $empresa]), [
                '_method' => 'patch',
                'nome' => 'Construtora Horizonte Editada',
                'contract_id' => $contract->id,
                'cnpj' => '12.345.678/0001-90',
                'sigla' => 'CHE',
                'tipo_empresa_id' => $tipo->id,
                'logo' => UploadedFile::fake()->image('logo-nova.png', 360, 180),
            ])
            ->assertRedirect();

        $empresa->refresh();

        $this->assertSame('Construtora Horizonte Editada', $empresa->nome);
        $this->assertSame('CHE', $empresa->sigla);
        $this->assertNotNull($empresa->logo_path);
        Storage::disk('public')->assertExists($empresa->logo_path);
    }

    public function test_tenant_admin_can_soft_delete_empresa(): void
    {
        Storage::fake('public');

        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $tipo = TipoEmpresa::where('nome', 'construtora')->firstOrFail();
        $logoPath = "tenant-{$tenant->id}/empresas/logos/logo.png";
        Storage::disk('public')->put($logoPath, 'logo');
        $empresa = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $tipo->id,
            'nome' => 'Construtora Horizonte',
            'cnpj' => '12.345.678/0001-90',
            'sigla' => 'CHZ',
            'logo_path' => $logoPath,
        ]);

        $this->actingAs($admin)
            ->delete(route('tenant.parametrizacao.empresas.destroy', [$tenant, $empresa]))
            ->assertRedirect();

        $this->assertSoftDeleted('empresas', [
            'id' => $empresa->id,
        ]);
        Storage::disk('public')->assertExists($logoPath);
    }

    public function test_tenant_admin_cannot_delete_empresa_used_by_rnc(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $tipo = TipoEmpresa::where('nome', 'construtora')->firstOrFail();
        $obra = $tenant->obras()->create([
            'contract_id' => $contract->id,
            'nome' => 'Obra Norte',
            'codigo' => 'OBR-N',
            'tipo' => 'pai',
        ]);
        $contratante = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $tipo->id,
            'nome' => 'Contratante Alfa',
            'cnpj' => '11.111.111/0001-11',
            'sigla' => 'ALFA',
        ]);
        $contratada = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $tipo->id,
            'nome' => 'Contratada Beta',
            'cnpj' => '22.222.222/0001-22',
            'sigla' => 'BETA',
        ]);

        RelatorioNaoConformidade::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'contratante_empresa_id' => $contratante->id,
            'contratada_empresa_id' => $contratada->id,
            'created_by_id' => $admin->id,
            'opened_at' => '2026-05-19',
            'natureza' => 'Qualidade',
            'gravidade' => 'Leve',
            'descricao_problema' => 'Problema teste',
            'acoes_corretivas_recomendadas' => 'Corrigir item',
            'prazo_resposta_acao_corretiva' => '2026-05-30',
            'status' => 'aberta',
        ]);

        $this->actingAs($admin)
            ->delete(route('tenant.parametrizacao.empresas.destroy', [$tenant, $contratante]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('empresas', [
            'id' => $contratante->id,
        ]);
    }

    public function test_tenant_admin_cannot_create_empresa_with_incomplete_cnpj(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $tipo = TipoEmpresa::where('nome', 'construtora')->firstOrFail();

        $this->actingAs($admin)
            ->from(route('tenant.parametrizacao.empresas.index', $tenant))
            ->post(route('tenant.parametrizacao.empresas.store', $tenant), [
                'nome' => 'Construtora Horizonte',
                'contract_id' => $contract->id,
                'cnpj' => '12.345.678/0001',
                'sigla' => 'CHZ',
                'tipo_empresa_id' => $tipo->id,
            ])
            ->assertRedirect(route('tenant.parametrizacao.empresas.index', $tenant))
            ->assertSessionHasErrors('cnpj');

        $this->assertDatabaseMissing('empresas', [
            'tenant_id' => $tenant->id,
            'nome' => 'Construtora Horizonte',
        ]);
    }

    public function test_tenant_admin_can_create_obra_pai(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');

        $this->actingAs($admin)
            ->post(route('tenant.parametrizacao.obras.store', $tenant), [
                'nome' => 'Residencial Jardim Central',
                'contract_id' => $contract->id,
                'codigo' => 'obr-001',
                'tipo' => 'pai',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('obras', [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Residencial Jardim Central',
            'codigo' => 'OBR-001',
            'tipo' => 'pai',
            'obra_pai_id' => null,
        ]);
    }

    public function test_tenant_admin_can_create_obra_filha_linked_to_obra_pai(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $obraPai = $tenant->obras()->create([
            'contract_id' => $contract->id,
            'nome' => 'Residencial Jardim Central',
            'codigo' => 'OBR-001',
            'tipo' => 'pai',
        ]);

        $this->actingAs($admin)
            ->post(route('tenant.parametrizacao.obras.store', $tenant), [
                'nome' => 'Torre A',
                'contract_id' => $contract->id,
                'codigo' => 'TOR-A',
                'tipo' => 'filha',
                'obra_pai_id' => $obraPai->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('obras', [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Torre A',
            'codigo' => 'TOR-A',
            'tipo' => 'filha',
            'obra_pai_id' => $obraPai->id,
        ]);
    }

    public function test_tenant_admin_can_update_obra(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $obra = $tenant->obras()->create([
            'contract_id' => $contract->id,
            'nome' => 'Residencial Jardim Central',
            'codigo' => 'OBR-001',
            'tipo' => 'pai',
        ]);

        $this->actingAs($admin)
            ->patch(route('tenant.parametrizacao.obras.update', [$tenant, $obra]), [
                'nome' => 'Residencial Jardim Central Editado',
                'contract_id' => $contract->id,
                'codigo' => 'obr-002',
                'tipo' => 'pai',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('obras', [
            'id' => $obra->id,
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Residencial Jardim Central Editado',
            'codigo' => 'OBR-002',
            'tipo' => 'pai',
            'obra_pai_id' => null,
        ]);
    }

    public function test_tenant_admin_can_soft_delete_obra(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $obra = $tenant->obras()->create([
            'contract_id' => $contract->id,
            'nome' => 'Obra Temporaria',
            'codigo' => 'OBR-TMP',
            'tipo' => 'pai',
        ]);

        $this->actingAs($admin)
            ->delete(route('tenant.parametrizacao.obras.destroy', [$tenant, $obra]))
            ->assertRedirect();

        $this->assertSoftDeleted('obras', [
            'id' => $obra->id,
        ]);
    }

    public function test_tenant_admin_cannot_delete_obra_with_child(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $obraPai = $tenant->obras()->create([
            'contract_id' => $contract->id,
            'nome' => 'Obra Principal',
            'codigo' => 'OBR-PAI',
            'tipo' => 'pai',
        ]);
        $tenant->obras()->create([
            'contract_id' => $contract->id,
            'obra_pai_id' => $obraPai->id,
            'nome' => 'Obra Filha',
            'codigo' => 'OBR-FILHA',
            'tipo' => 'filha',
        ]);

        $this->actingAs($admin)
            ->delete(route('tenant.parametrizacao.obras.destroy', [$tenant, $obraPai]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('obras', [
            'id' => $obraPai->id,
        ]);
    }

    public function test_obra_filha_requires_obra_pai(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');

        $this->actingAs($admin)
            ->from(route('tenant.parametrizacao.obras.index', $tenant))
            ->post(route('tenant.parametrizacao.obras.store', $tenant), [
                'nome' => 'Torre A',
                'contract_id' => $contract->id,
                'codigo' => 'TOR-A',
                'tipo' => 'filha',
                'obra_pai_id' => '',
            ])
            ->assertRedirect(route('tenant.parametrizacao.obras.index', $tenant))
            ->assertSessionHasErrors('obra_pai_id');
    }

    public function test_operational_user_cannot_access_obra_parametrizacao(): void
    {
        [$tenant, $engineer] = $this->tenantWithUser('engineer');

        $this->actingAs($engineer)
            ->get(route('tenant.parametrizacao.obras.index', $tenant))
            ->assertForbidden();
    }

    public function test_tenant_admin_can_create_disciplina(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');

        $this->actingAs($admin)
            ->post(route('tenant.parametrizacao.disciplinas.store', $tenant), [
                'contract_id' => $contract->id,
                'nome' => 'Arquitetura',
                'sigla' => 'arq',
                'descricao' => 'Projetos arquitetonicos',
                'cor' => '#1d4ed8',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('disciplinas', [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'descricao' => 'Projetos arquitetonicos',
            'cor' => '#1d4ed8',
        ]);
    }

    public function test_tenant_admin_can_update_disciplina(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $disciplina = $tenant->disciplinas()->create([
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'descricao' => 'Projetos arquitetonicos',
            'cor' => '#1d4ed8',
        ]);

        $this->actingAs($admin)
            ->patch(route('tenant.parametrizacao.disciplinas.update', [$tenant, $disciplina]), [
                'contract_id' => $contract->id,
                'nome' => 'Estrutura',
                'sigla' => 'est',
                'descricao' => 'Projetos estruturais',
                'cor' => '#16a34a',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('disciplinas', [
            'id' => $disciplina->id,
            'nome' => 'Estrutura',
            'sigla' => 'EST',
            'descricao' => 'Projetos estruturais',
            'cor' => '#16a34a',
        ]);
    }

    public function test_tenant_admin_can_soft_delete_disciplina(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $disciplina = $tenant->disciplinas()->create([
            'contract_id' => $contract->id,
            'nome' => 'Arquitetura',
            'sigla' => 'ARQ',
            'cor' => '#1d4ed8',
        ]);

        $this->actingAs($admin)
            ->delete(route('tenant.parametrizacao.disciplinas.destroy', [$tenant, $disciplina]))
            ->assertRedirect();

        $this->assertSoftDeleted('disciplinas', [
            'id' => $disciplina->id,
        ]);
    }

    public function test_operational_user_cannot_access_disciplina_parametrizacao(): void
    {
        [$tenant, $engineer] = $this->tenantWithUser('engineer');

        $this->actingAs($engineer)
            ->get(route('tenant.parametrizacao.disciplinas.index', $tenant))
            ->assertForbidden();
    }

    public function test_tenant_admin_can_access_contrato_parametrizacao(): void
    {
        [$tenant, $admin] = $this->tenantWithUser('tenant_admin');

        $this->actingAs($admin)
            ->get(route('tenant.parametrizacao.contrato.index', $tenant))
            ->assertOk();
    }

    public function test_tenant_admin_can_access_usuario_contrato_parametrizacao(): void
    {
        [$tenant, $admin] = $this->tenantWithUser('tenant_admin');

        $this->actingAs($admin)
            ->get(route('tenant.parametrizacao.usuarios-contratos.index', $tenant))
            ->assertOk();
    }

    public function test_tenant_admin_can_link_user_to_contract(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $engineer = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $engineer->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('tenant.parametrizacao.usuarios-contratos.store', $tenant), [
                'user_id' => $engineer->id,
                'contract_id' => $contract->id,
                'side' => 'manager',
                'role' => 'team_member',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('contract_participants', [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $engineer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);
    }

    public function test_tenant_admin_can_soft_delete_user_contract_link(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $engineer = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $engineer->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $participant = ContractParticipant::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $engineer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->delete(route('tenant.parametrizacao.usuarios-contratos.destroy', [$tenant, $participant]))
            ->assertRedirect();

        $this->assertDatabaseHas('contract_participants', [
            'id' => $participant->id,
            'status' => 'inactive',
        ]);
        $this->assertSoftDeleted('contract_participants', [
            'id' => $participant->id,
        ]);
    }

    public function test_tenant_admin_can_restore_removed_user_contract_link(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $engineer = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $engineer->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $participant = ContractParticipant::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $engineer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);
        $participant->update(['status' => 'inactive']);
        $participant->delete();

        $this->actingAs($admin)
            ->post(route('tenant.parametrizacao.usuarios-contratos.store', $tenant), [
                'user_id' => $engineer->id,
                'contract_id' => $contract->id,
                'side' => 'manager',
                'role' => 'manager',
            ])
            ->assertRedirect();

        $participant->refresh();

        $this->assertFalse($participant->trashed());
        $this->assertSame('active', $participant->status);
        $this->assertSame('manager', $participant->role);
    }

    public function test_tenant_admin_can_link_obra_cliente_construtora_and_gerenciadora_to_contract(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $clienteTipo = TipoEmpresa::where('nome', 'cliente')->firstOrFail();
        $construtoraTipo = TipoEmpresa::where('nome', 'construtora')->firstOrFail();
        $gerenciadoraTipo = TipoEmpresa::where('nome', 'gerenciadora')->firstOrFail();
        $obra = $tenant->obras()->create([
            'contract_id' => $contract->id,
            'nome' => 'Obra Norte',
            'codigo' => 'OBR-N',
            'tipo' => 'pai',
        ]);
        $cliente = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $clienteTipo->id,
            'nome' => 'Cliente Delta',
            'cnpj' => '11.111.111/0001-11',
            'sigla' => 'DELTA',
        ]);
        $construtora = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $construtoraTipo->id,
            'nome' => 'Construtora Horizonte',
            'cnpj' => '22.222.222/0001-22',
            'sigla' => 'CHZ',
        ]);
        $gerenciadora = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $gerenciadoraTipo->id,
            'nome' => 'Gerenciadora Alfa',
            'cnpj' => '33.333.333/0001-33',
            'sigla' => 'ALFA',
        ]);

        $this->actingAs($admin)
            ->post(route('tenant.parametrizacao.contrato.store', $tenant), [
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'cliente_empresa_id' => $cliente->id,
                'construtora_empresa_id' => $construtora->id,
                'gerenciadora_empresa_id' => $gerenciadora->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'obra_id' => $obra->id,
            'cliente_empresa_id' => $cliente->id,
            'construtora_empresa_id' => $construtora->id,
            'fiscalizadora_empresa_id' => $gerenciadora->id,
            'client_company_name' => 'Cliente Delta',
            'contractor_company_name' => 'Construtora Horizonte',
            'name' => 'Obra Norte',
        ]);
    }

    public function test_contrato_parametrizacao_rejects_obra_from_another_contract(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $otherContract = $tenant->contracts()->create([
            'code' => 'CT-002',
            'name' => 'Contrato Fora',
            'status' => 'active',
        ]);
        $obra = $tenant->obras()->create([
            'contract_id' => $otherContract->id,
            'nome' => 'Obra de Outro Contrato',
            'codigo' => 'OBR-OUTRA',
            'tipo' => 'pai',
        ]);

        $this->actingAs($admin)
            ->from(route('tenant.parametrizacao.contrato.index', $tenant))
            ->post(route('tenant.parametrizacao.contrato.store', $tenant), [
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'cliente_empresa_id' => '',
                'construtora_empresa_id' => '',
                'gerenciadora_empresa_id' => '',
            ])
            ->assertRedirect(route('tenant.parametrizacao.contrato.index', $tenant))
            ->assertSessionHasErrors('obra_id');

        $this->assertDatabaseMissing('contracts', [
            'id' => $contract->id,
            'obra_id' => $obra->id,
        ]);
    }

    public function test_operational_user_cannot_access_contrato_parametrizacao(): void
    {
        [$tenant, $engineer] = $this->tenantWithUser('engineer');

        $this->actingAs($engineer)
            ->get(route('tenant.parametrizacao.contrato.index', $tenant))
            ->assertForbidden();
    }

    public function test_operational_user_cannot_access_usuario_contrato_parametrizacao(): void
    {
        [$tenant, $engineer] = $this->tenantWithUser('engineer');

        $this->actingAs($engineer)
            ->get(route('tenant.parametrizacao.usuarios-contratos.index', $tenant))
            ->assertForbidden();
    }

    /**
     * @return array{Tenant, User, \App\Models\Contract}
     */
    private function tenantWithUser(string $role): array
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
            'role' => $role,
            'status' => 'active',
        ]);

        $contract = $tenant->contracts()->create([
            'code' => 'CT-001',
            'name' => 'Contrato Teste',
            'status' => 'active',
        ]);

        return [$tenant, $user, $contract];
    }
}

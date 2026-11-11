<?php

namespace Tests\Feature;

use App\Models\{Tenant, User, Contract, Empresa, TipoEmpresa};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrcamentoContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_is_linked_to_contract_and_rejects_unrelated_client(): void
    {
        $tenant = Tenant::create(['slug' => 'budget-contract', 'name' => 'Teste', 'plan' => 'starter', 'status' => 'active']);
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'tenant_admin', 'status' => 'active']);
        $contract = Contract::create(['tenant_id' => $tenant->id, 'code' => '001', 'name' => 'Contrato teste']);
        $type = TipoEmpresa::firstOrCreate(['nome' => 'cliente']);
        $client = Empresa::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'tipo_empresa_id' => $type->id, 'cnpj' => '12345678000100', 'sigla' => 'CV', 'nome' => 'Cliente vinculado']);
        $other = Empresa::create(['tenant_id' => $tenant->id, 'tipo_empresa_id' => $type->id, 'cnpj' => '12345678000200', 'sigla' => 'OC', 'nome' => 'Outro cliente']);
        $payload = ['contract_id' => $contract->id, 'codigo' => 'ORC-01', 'descricao' => 'Orçamento teste', 'cliente_empresa_id' => $other->id,
            'categoria' => 'Unidades habitacionais - Construcao', 'arredondamento' => 'truncate_all_2', 'encargos_sociais' => 'desonerado', 'bdi_tipo' => 'unit_price', 'bdi_percentual' => '0,00',
            'base_references' => [['codigo' => 'SINAPI-PA', 'nome' => 'SINAPI', 'uf' => 'PA', 'data' => '10/2026']]];
        $this->actingAs($user)->post(route('tenant.orcamentos.store', $tenant), $payload)->assertSessionHasErrors('cliente_empresa_id');
        $payload['cliente_empresa_id'] = $client->id;
        $this->post(route('tenant.orcamentos.store', $tenant), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('orcamentos', ['codigo' => 'ORC-01', 'contract_id' => $contract->id, 'cliente_empresa_id' => $client->id]);
        $this->get(route('tenant.orcamentos.index', [$tenant, 'search' => 'teste', 'tipo' => $payload['categoria'], 'cliente_empresa_id' => $client->id]))
            ->assertOk()->assertInertia(fn ($page) => $page->has('orcamentos.data', 1)->where('orcamentos.per_page', 15)->missing('stats'));
        $this->get(route('tenant.orcamentos.index', [$tenant, 'search' => 'inexistente']))
            ->assertOk()->assertInertia(fn ($page) => $page->has('orcamentos.data', 0));
    }
}



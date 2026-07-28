<?php

namespace Tests\Feature;

use App\Models\FolhaRosto;
use App\Models\MedicaoItem;
use App\Models\OrdemServico;
use App\Models\OrdemServicoItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantOrdemServicoMeasurementProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_exposes_measured_percentage_from_completed_measurements(): void
    {
        $tenant = Tenant::create([
            'slug' => 'empresa-os-medicao',
            'name' => 'Empresa OS Medicao',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'tenant_owner',
            'status' => 'active',
        ]);
        $contract = $tenant->contracts()->create([
            'code' => 'CT-001',
            'name' => 'Contrato OS',
            'status' => 'active',
        ]);
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '100',
            'nome' => 'Obra OS',
            'tipo' => 'pai',
        ]);
        $medicaoItem = MedicaoItem::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'item' => '1.1',
            'item_type' => 'manual',
            'codigo' => 'ITEM-001',
            'descricao' => 'Servico medido',
            'unidade' => 'UN',
            'quantidade_prevista' => 10,
            'valor_total' => 1000,
        ]);
        $ordem = OrdemServico::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $user->id,
            'codigo' => 'CT001-100-OS-001',
            'sequencial' => 1,
            'titulo' => 'OS com medicao',
            'custo_previsto' => 1000,
            'status' => 'aprovada',
        ]);
        $ordemItem = $ordem->itens()->create([
            'medicao_item_id' => $medicaoItem->id,
            'quantidade_solicitada' => 10,
            'valor_previsto' => 1000,
        ]);

        $this->createMeasurement($tenant, $user, $ordem, $ordemItem, 'analisada', 2.5, 1);
        $this->createMeasurement($tenant, $user, $ordem, $ordemItem, 'analise_medicao', 5, 2);

        $this->actingAs($user)
            ->get(route('tenant.ordem-servico.os.index', [
                'tenant' => $tenant,
                'contract_id' => $contract->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/OrdemServico/Index')
                ->where('ordens.0.itens.0.quantidade_solicitada', 10)
                ->where('ordens.0.itens.0.quantidade_medida', 2.5)
                ->where('ordens.0.itens.0.percentual_medido', 25)
            );
    }

    private function createMeasurement(
        Tenant $tenant,
        User $user,
        OrdemServico $ordem,
        OrdemServicoItem $ordemItem,
        string $status,
        float $quantity,
        int $sequence
    ): void {
        $folha = FolhaRosto::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $ordem->contract_id,
            'obra_id' => $ordem->obra_id,
            'ordem_servico_id' => $ordem->id,
            'created_by_id' => $user->id,
            'codigo' => "FR-{$sequence}",
            'sequencial' => $sequence,
            'comentario' => 'Medicao do item',
            'status' => $status,
        ]);
        $folhaItem = $folha->itens()->create([
            'ordem_servico_item_id' => $ordemItem->id,
            'medicao_item_id' => $ordemItem->medicao_item_id,
            'quantidade_pleiteada' => $quantity,
            'valor_pleiteado' => $quantity * 100,
        ]);
        $analise = $folha->analises()->create([
            'user_id' => $user->id,
            'setor' => 'medicao',
        ]);
        $folhaItem->analises()->create([
            'folha_rosto_analise_id' => $analise->id,
            'setor' => 'medicao',
            'quantidade_aprovada' => $quantity,
        ]);
    }
}

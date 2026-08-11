<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\FolhaRosto;
use App\Models\FolhaRostoAnalise;
use App\Models\FolhaRostoItem;
use App\Models\FolhaRostoItemAnalise;
use App\Models\MedicaoIndiceReajuste;
use App\Models\MedicaoIndiceReajusteCompetencia;
use App\Models\MedicaoItem;
use App\Models\MedicaoItemReajusteIndice;
use App\Models\Orcamento;
use App\Models\OrcamentoEtapa;
use App\Models\OrcamentoItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\MedicaoItemValueResolver;
use App\Support\MedicaoPermissions;
use App\Support\MedicaoReajusteCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantMedicaoFinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_measurement_financial_permissions_are_independent(): void
    {
        [$tenant, $user, $contract] = $this->context(false, [MedicaoPermissions::ITEMS]);

        $this->actingAs($user)
            ->post(route('tenant.medicao.item.store', $tenant), [
                'contract_id' => $contract->id,
                'item' => '1.1',
                'descricao' => 'Item manual autorizado',
                'quantidade_prevista' => '10',
                'valor_com_bdi' => '25',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('tenant.medicao.item.orcamento.store', $tenant), [])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('tenant.medicao.item.additive.manual', $tenant), [])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('tenant.medicao.indice-reajuste.store', $tenant), [])
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.medicao.bi.index', $tenant))
            ->assertForbidden();
    }

    public function test_budget_revision_with_one_thousand_items_is_atomic_and_never_reduces_measured_quantity(): void
    {
        [$tenant, $owner, $contract] = $this->context();
        [$baseBudget, $baseStage, $baseItems] = $this->budget($tenant, $owner, 'ORC-BASE', 1000);

        $this->actingAs($owner)
            ->post(route('tenant.medicao.item.orcamento.store', $tenant), [
                'contract_id' => $contract->id,
                'orcamento_id' => $baseBudget->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1001, MedicaoItem::query()->where('contract_id', $contract->id)->count());

        $this->actingAs($owner)
            ->post(route('tenant.medicao.item.orcamento.store', $tenant), [
                'contract_id' => $contract->id,
                'orcamento_id' => $baseBudget->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1001, MedicaoItem::query()->where('contract_id', $contract->id)->count());

        $measuredItem = MedicaoItem::query()
            ->where('contract_id', $contract->id)
            ->where('source_orcamento_item_id', $baseItems->get(1)->id)
            ->firstOrFail();
        $this->recordApprovedMeasurement($tenant, $contract, $owner, $measuredItem, 60);

        [$revisedBudget, $revisedStage] = $this->revisedBudget(
            $tenant,
            $owner,
            $baseStage,
            $baseItems,
            50
        );

        $this->actingAs($owner)
            ->post(route('tenant.medicao.item.orcamento.store', $tenant), [
                'contract_id' => $contract->id,
                'orcamento_id' => $revisedBudget->id,
            ])
            ->assertSessionHasErrors('orcamento_id');

        $this->actingAs($owner)
            ->post(route('tenant.medicao.item.additive.orcamento.store', $tenant), [
                'contract_id' => $contract->id,
                'orcamento_id' => $revisedBudget->id,
                'additive_title' => 'Revisão quantitativa anual',
                'effective_at' => '2027-01-01',
            ])
            ->assertSessionHasErrors('quantidade_prevista');

        $this->assertDatabaseCount('medicao_item_additives', 0);
        $this->assertSame('100.000000', $measuredItem->refresh()->quantidade_prevista);
        $this->assertSame(1001, MedicaoItem::query()->where('contract_id', $contract->id)->count());

        OrcamentoItem::query()
            ->where('orcamento_etapa_id', $revisedStage->id)
            ->where('ordem', 1)
            ->update([
                'quantidade' => 60,
                'valor_total_nao_desonerado' => 600,
                'valor_total_desonerado' => 600,
            ]);

        $this->actingAs($owner)
            ->post(route('tenant.medicao.item.additive.orcamento.store', $tenant), [
                'contract_id' => $contract->id,
                'orcamento_id' => $revisedBudget->id,
                'additive_title' => 'Revisão quantitativa anual',
                'effective_at' => '2027-01-01',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('medicao_item_additives', 1);
        $this->assertSame(1002, MedicaoItem::query()->where('contract_id', $contract->id)->count());

        $revisedFirstItem = OrcamentoItem::query()
            ->where('orcamento_etapa_id', $revisedStage->id)
            ->where('ordem', 1)
            ->firstOrFail();
        $updatedMeasuredItem = $measuredItem->refresh();

        $this->assertSame('60.000000', $updatedMeasuredItem->quantidade_prevista);
        $this->assertSame($revisedBudget->id, $updatedMeasuredItem->source_orcamento_id);
        $this->assertSame($revisedFirstItem->id, $updatedMeasuredItem->source_orcamento_item_id);
        $this->assertSame($baseItems->get(1)->id, $updatedMeasuredItem->meta['budget_origin_item_id']);
        $this->assertSame(2, $updatedMeasuredItem->versions()->count());
        $updatedMeasuredItem->load('versions');
        $this->assertEqualsWithDelta(
            100,
            MedicaoItemValueResolver::resolve($updatedMeasuredItem, '2026-12-01')['quantidade_total'],
            0.000001
        );
        $this->assertEqualsWithDelta(
            60,
            MedicaoItemValueResolver::resolve($updatedMeasuredItem, '2027-01-01')['quantidade_total'],
            0.000001
        );

        $removedFromRevision = MedicaoItem::query()
            ->where('contract_id', $contract->id)
            ->where('source_orcamento_item_id', $baseItems->get(1000)->id)
            ->firstOrFail();
        $this->assertSame('100.000000', $removedFromRevision->quantidade_prevista);

        $this->assertDatabaseHas('medicao_itens', [
            'contract_id' => $contract->id,
            'source_orcamento_id' => $revisedBudget->id,
            'codigo' => 'ITEM-NEW',
            'source_type' => 'aditivo',
        ]);
        $newItem = MedicaoItem::query()
            ->where('contract_id', $contract->id)
            ->where('codigo', 'ITEM-NEW')
            ->with('versions')
            ->firstOrFail();
        $this->assertEqualsWithDelta(
            0,
            MedicaoItemValueResolver::resolve($newItem, '2026-12-01')['quantidade_total'],
            0.000001
        );
        $this->assertEqualsWithDelta(
            25,
            MedicaoItemValueResolver::resolve($newItem, '2027-01-01')['quantidade_total'],
            0.000001
        );

        $this->actingAs($owner)
            ->post(route('tenant.medicao.item.additive.orcamento.store', $tenant), [
                'contract_id' => $contract->id,
                'orcamento_id' => $revisedBudget->id,
            ])
            ->assertSessionHasErrors('orcamento_id');

        $this->assertDatabaseCount('medicao_item_additives', 1);
        $this->assertSame(1002, MedicaoItem::query()->where('contract_id', $contract->id)->count());
    }

    public function test_adjustment_index_uses_the_correct_month_across_year_boundary(): void
    {
        [$tenant, $owner, $contract] = $this->context();
        $item = MedicaoItem::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $owner->id,
            'item' => '1.1',
            'item_type' => 'manual',
            'descricao' => 'Item reajustável',
            'quantidade_prevista' => 10,
            'valor_com_bdi' => 100,
            'valor_total' => 1000,
        ]);
        $indice = MedicaoIndiceReajuste::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $owner->id,
            'nome' => 'Índice anual',
            'codigo' => 'IDX',
            'indice_base' => 100,
            'data_base' => '2025-01-01',
            'indice_atual' => 140,
            'data_atual' => '2026-04-01',
        ]);
        MedicaoItemReajusteIndice::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'medicao_item_id' => $item->id,
            'medicao_indice_reajuste_id' => $indice->id,
            'created_by_id' => $owner->id,
            'item_codigo' => '1.1',
            'indice_codigo' => 'IDX',
            'source_type' => 'manual',
        ]);

        foreach ([
            ['2025-12-01', 110],
            ['2026-01-01', 115],
            ['2026-03-01', 130],
        ] as [$competencia, $valor]) {
            MedicaoIndiceReajusteCompetencia::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'medicao_indice_reajuste_id' => $indice->id,
                'created_by_id' => $owner->id,
                'competencia' => $competencia,
                'valor_indice' => $valor,
            ]);
        }

        $item->load('reajusteIndice.indice.competencias');

        $this->assertEqualsWithDelta(0, MedicaoReajusteCalculator::percentage($item, '2025-11-01'), 0.000001);
        $this->assertEqualsWithDelta(10, MedicaoReajusteCalculator::percentage($item, '2025-12-01'), 0.000001);
        $this->assertEqualsWithDelta(15, MedicaoReajusteCalculator::percentage($item, '2026-01-01'), 0.000001);
        $this->assertEqualsWithDelta(15, MedicaoReajusteCalculator::percentage($item, '2026-02-01'), 0.000001);
        $this->assertEqualsWithDelta(30, MedicaoReajusteCalculator::percentage($item, '2026-03-01'), 0.000001);
        $this->assertEqualsWithDelta(115, MedicaoReajusteCalculator::adjustedValue(100, $item, '2026-01-01'), 0.000001);
    }

    public function test_index_dates_cannot_move_before_their_financial_base(): void
    {
        [$tenant, $owner, $contract] = $this->context();

        $this->actingAs($owner)
            ->post(route('tenant.medicao.indice-reajuste.store', $tenant), [
                'contract_id' => $contract->id,
                'nome' => 'Índice inválido',
                'indice_base' => '100',
                'data_base' => '2026-01-01',
                'indice_atual' => '101',
                'data_atual' => '2025-12-01',
            ])
            ->assertSessionHasErrors('data_atual');

        $indice = MedicaoIndiceReajuste::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $owner->id,
            'nome' => 'Índice válido',
            'indice_base' => 100,
            'data_base' => '2026-01-01',
            'indice_atual' => 101,
            'data_atual' => '2026-01-01',
        ]);

        $this->actingAs($owner)
            ->post(route('tenant.medicao.indice-reajuste.competencias.store', [$tenant, $indice]), [
                'competencia' => '12/2025',
                'valor_indice' => '99',
            ])
            ->assertSessionHasErrors('competencia');
    }

    public function test_negative_budget_values_are_rejected_at_the_contract_boundary(): void
    {
        [$tenant, $owner, $contract] = $this->context();
        [$budget, , $items] = $this->budget($tenant, $owner, 'ORC-NEG', 1);
        $items->first()->update(['quantidade' => -1]);

        $this->actingAs($owner)
            ->post(route('tenant.medicao.item.orcamento.store', $tenant), [
                'contract_id' => $contract->id,
                'orcamento_id' => $budget->id,
            ])
            ->assertSessionHasErrors('orcamento_id');

        $this->assertSame(0, MedicaoItem::query()->where('contract_id', $contract->id)->count());
    }

    public function test_duplicate_budget_lineage_is_rejected_without_partial_additive(): void
    {
        [$tenant, $owner, $contract] = $this->context();
        [$baseBudget, $baseStage, $baseItems] = $this->budget($tenant, $owner, 'ORC-DUP-BASE', 2);

        $this->actingAs($owner)
            ->post(route('tenant.medicao.item.orcamento.store', $tenant), [
                'contract_id' => $contract->id,
                'orcamento_id' => $baseBudget->id,
            ])
            ->assertSessionHasNoErrors();

        $revision = Orcamento::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $owner->id,
            'closed_by_id' => $owner->id,
            'codigo' => 'ORC-DUP-REV',
            'descricao' => 'Revisão com origem duplicada',
            'categoria' => 'Infraestrutura',
            'encargos_sociais' => 'nao_desonerado',
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        $stage = OrcamentoEtapa::create([
            'tenant_id' => $tenant->id,
            'orcamento_id' => $revision->id,
            'created_by_id' => $owner->id,
            'ordem' => 1,
            'descricao' => 'Etapa duplicada',
            'meta' => ['origin_orcamento_etapa_id' => $baseStage->id],
        ]);

        foreach ([1, 2] as $order) {
            OrcamentoItem::create([
                'tenant_id' => $tenant->id,
                'orcamento_id' => $revision->id,
                'orcamento_etapa_id' => $stage->id,
                'created_by_id' => $owner->id,
                'item_type' => 'insumo',
                'ordem' => $order,
                'codigo' => "DUP-{$order}",
                'descricao' => "Duplicado {$order}",
                'unidade' => 'UN',
                'quantidade' => 10,
                'valor_unitario_nao_desonerado' => 10,
                'valor_unitario_desonerado' => 10,
                'valor_com_bdi_nao_desonerado' => 10,
                'valor_com_bdi_desonerado' => 10,
                'valor_total_nao_desonerado' => 100,
                'valor_total_desonerado' => 100,
                'meta' => ['origin_orcamento_item_id' => $baseItems->get(1)->id],
            ]);
        }

        $this->actingAs($owner)
            ->post(route('tenant.medicao.item.additive.orcamento.store', $tenant), [
                'contract_id' => $contract->id,
                'orcamento_id' => $revision->id,
            ])
            ->assertSessionHasErrors('orcamento_id');

        $this->assertDatabaseCount('medicao_item_additives', 0);
        $this->assertSame(3, MedicaoItem::query()->where('contract_id', $contract->id)->count());
    }

    private function context(bool $owner = true, array $permissions = []): array
    {
        $tenant = Tenant::create([
            'slug' => 'med-fin-'.str()->lower(str()->random(8)),
            'name' => 'Tenant Financeiro',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => $owner ? 'tenant_owner' : 'engineer',
            'status' => 'active',
            'medicao_permissions' => $owner ? null : MedicaoPermissions::normalize($permissions),
        ]);
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-FIN-001',
            'name' => 'Contrato financeiro',
            'status' => 'active',
            'measurement_mode' => 'simple',
        ]);

        if (! $owner) {
            ContractParticipant::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'user_id' => $user->id,
                'side' => 'manager',
                'role' => 'team_member',
                'status' => 'active',
                'medicao_permissions' => MedicaoPermissions::normalize($permissions),
            ]);
        }

        return [$tenant, $user, $contract];
    }

    private function budget(Tenant $tenant, User $user, string $code, int $itemCount): array
    {
        $budget = Orcamento::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $user->id,
            'closed_by_id' => $user->id,
            'codigo' => $code,
            'descricao' => "Orçamento {$code}",
            'categoria' => 'Infraestrutura',
            'encargos_sociais' => 'nao_desonerado',
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        $stage = OrcamentoEtapa::create([
            'tenant_id' => $tenant->id,
            'orcamento_id' => $budget->id,
            'created_by_id' => $user->id,
            'ordem' => 1,
            'descricao' => 'Etapa principal',
            'quantidade' => 1,
        ]);
        $now = now();
        $rows = [];

        for ($order = 1; $order <= $itemCount; $order++) {
            $rows[] = [
                'tenant_id' => $tenant->id,
                'orcamento_id' => $budget->id,
                'orcamento_etapa_id' => $stage->id,
                'created_by_id' => $user->id,
                'item_type' => 'insumo',
                'ordem' => $order,
                'codigo' => 'ITEM-'.str_pad((string) $order, 4, '0', STR_PAD_LEFT),
                'banco' => 'PROPRIA',
                'descricao' => "Item financeiro {$order}",
                'unidade' => 'UN',
                'quantidade' => 100,
                'valor_unitario_nao_desonerado' => 10,
                'valor_unitario_desonerado' => 10,
                'valor_com_bdi_nao_desonerado' => 10,
                'valor_com_bdi_desonerado' => 10,
                'valor_total_nao_desonerado' => 1000,
                'valor_total_desonerado' => 1000,
                'aplicar_bdi' => false,
                'meta' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            OrcamentoItem::query()->insert($chunk);
        }

        return [
            $budget,
            $stage,
            OrcamentoItem::query()->where('orcamento_etapa_id', $stage->id)->get()->keyBy('ordem'),
        ];
    }

    private function revisedBudget(
        Tenant $tenant,
        User $user,
        OrcamentoEtapa $baseStage,
        $baseItems,
        float $firstQuantity
    ): array {
        $budget = Orcamento::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $user->id,
            'closed_by_id' => $user->id,
            'codigo' => 'ORC-REV-01',
            'descricao' => 'Orçamento revisado',
            'categoria' => 'Infraestrutura',
            'encargos_sociais' => 'nao_desonerado',
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        $stage = OrcamentoEtapa::create([
            'tenant_id' => $tenant->id,
            'orcamento_id' => $budget->id,
            'created_by_id' => $user->id,
            'ordem' => 1,
            'descricao' => 'Etapa principal revisada',
            'quantidade' => 1,
            'meta' => ['origin_orcamento_etapa_id' => $baseStage->id],
        ]);
        $now = now();
        $rows = [];

        for ($order = 1; $order <= 999; $order++) {
            $quantity = $order === 1 ? $firstQuantity : 120;
            $rows[] = [
                'tenant_id' => $tenant->id,
                'orcamento_id' => $budget->id,
                'orcamento_etapa_id' => $stage->id,
                'created_by_id' => $user->id,
                'item_type' => 'insumo',
                'ordem' => $order,
                'codigo' => $baseItems->get($order)->codigo,
                'banco' => 'PROPRIA',
                'descricao' => $baseItems->get($order)->descricao,
                'unidade' => 'UN',
                'quantidade' => $quantity,
                'valor_unitario_nao_desonerado' => 10,
                'valor_unitario_desonerado' => 10,
                'valor_com_bdi_nao_desonerado' => 10,
                'valor_com_bdi_desonerado' => 10,
                'valor_total_nao_desonerado' => $quantity * 10,
                'valor_total_desonerado' => $quantity * 10,
                'aplicar_bdi' => false,
                'meta' => json_encode(['origin_orcamento_item_id' => $baseItems->get($order)->id]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $rows[] = [
            'tenant_id' => $tenant->id,
            'orcamento_id' => $budget->id,
            'orcamento_etapa_id' => $stage->id,
            'created_by_id' => $user->id,
            'item_type' => 'insumo',
            'ordem' => 1000,
            'codigo' => 'ITEM-NEW',
            'banco' => 'PROPRIA',
            'descricao' => 'Item acrescentado na revisão',
            'unidade' => 'UN',
            'quantidade' => 25,
            'valor_unitario_nao_desonerado' => 20,
            'valor_unitario_desonerado' => 20,
            'valor_com_bdi_nao_desonerado' => 20,
            'valor_com_bdi_desonerado' => 20,
            'valor_total_nao_desonerado' => 500,
            'valor_total_desonerado' => 500,
            'aplicar_bdi' => false,
            'meta' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach (array_chunk($rows, 500) as $chunk) {
            OrcamentoItem::query()->insert($chunk);
        }

        return [$budget, $stage];
    }

    private function recordApprovedMeasurement(
        Tenant $tenant,
        Contract $contract,
        User $user,
        MedicaoItem $item,
        float $quantity
    ): void {
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '001',
            'nome' => 'Obra financeira',
            'tipo' => 'pai',
        ]);
        $folha = FolhaRosto::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $user->id,
            'codigo' => 'FR-FIN-001',
            'sequencial' => 1,
            'comentario' => 'Medição aprovada para teste financeiro.',
            'status' => 'analisada',
        ]);
        $folhaItem = FolhaRostoItem::create([
            'folha_rosto_id' => $folha->id,
            'medicao_item_id' => $item->id,
            'quantidade_pleiteada' => $quantity,
            'valor_pleiteado' => $quantity * 10,
        ]);
        $analysis = FolhaRostoAnalise::create([
            'folha_rosto_id' => $folha->id,
            'user_id' => $user->id,
            'setor' => 'medicao',
        ]);
        FolhaRostoItemAnalise::create([
            'folha_rosto_item_id' => $folhaItem->id,
            'folha_rosto_analise_id' => $analysis->id,
            'setor' => 'medicao',
            'quantidade_aprovada' => $quantity,
        ]);
    }
}

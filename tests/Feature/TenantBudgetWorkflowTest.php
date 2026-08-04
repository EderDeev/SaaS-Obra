<?php

namespace Tests\Feature;

use App\Models\Orcamento;
use App\Models\OrcamentoEtapa;
use App\Models\OrcamentoInsumo;
use App\Models\OrcamentoItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BudgetPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class TenantBudgetWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_rounding_methods_change_unit_and_total_values(): void
    {
        [$tenant, $admin] = $this->tenantWithAdmin('rounding-lab');
        $insumo = $this->insumo($tenant, $admin, 'ROUND-001', 10.9999);
        $cases = [
            'round_all_2' => ['unit' => '11.000000', 'total' => '33.000000'],
            'round_compositions_2' => ['unit' => '11.000000', 'total' => '33.000000'],
            'round_and_truncate_unit' => ['unit' => '10.990000', 'total' => '32.970000'],
            'truncate_all_2' => ['unit' => '10.990000', 'total' => '32.970000'],
            'none' => ['unit' => '10.999900', 'total' => '32.999700'],
        ];

        $caseNumber = 0;

        foreach ($cases as $rounding => $expected) {
            $caseNumber++;
            $budget = $this->budget($tenant, $admin, 'ORC-'.$caseNumber, [
                'arredondamento' => $rounding,
            ]);
            $stage = $this->stage($tenant, $budget, $admin, '1', 'Servicos');

            $this->actingAs($admin)
                ->post(route('tenant.orcamentos.etapas.insumos.store', [$tenant, $budget, $stage]), [
                    'orcamento_insumo_id' => $insumo->id,
                    'quantidade' => '3',
                    'aplicar_bdi' => false,
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $item = OrcamentoItem::query()
                ->where('orcamento_id', $budget->id)
                ->firstOrFail();

            $this->assertSame($expected['unit'], $item->valor_unitario_desonerado, $rounding);
            $this->assertSame($expected['unit'], $item->valor_com_bdi_desonerado, $rounding);
            $this->assertSame($expected['total'], $item->valor_total_desonerado, $rounding);
            $this->assertSame($expected['total'], $budget->fresh()->valor_desonerado, $rounding);
        }
    }

    public function test_bdi_is_applied_only_to_marked_items_and_accepts_a_differentiated_rate(): void
    {
        [$tenant, $admin] = $this->tenantWithAdmin('bdi-lab');
        $budget = $this->budget($tenant, $admin, 'ORC-BDI', [
            'bdi_percentual' => 10,
            'arredondamento' => 'truncate_all_2',
        ]);
        $stage = $this->stage($tenant, $budget, $admin, '1', 'Servicos');
        $withoutBdi = $this->insumo($tenant, $admin, 'BDI-SEM', 10);
        $withBdi = $this->insumo($tenant, $admin, 'BDI-COM', 10);

        $this->actingAs($admin)
            ->post(route('tenant.orcamentos.etapas.insumos.store', [$tenant, $budget, $stage]), [
                'orcamento_insumo_id' => $withoutBdi->id,
                'quantidade' => '2',
                'aplicar_bdi' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->post(route('tenant.orcamentos.etapas.insumos.store', [$tenant, $budget, $stage]), [
                'orcamento_insumo_id' => $withBdi->id,
                'quantidade' => '2',
                'aplicar_bdi' => true,
            ])
            ->assertSessionHasNoErrors();

        $plainItem = OrcamentoItem::query()->where('orcamento_insumo_id', $withoutBdi->id)->firstOrFail();
        $bdiItem = OrcamentoItem::query()->where('orcamento_insumo_id', $withBdi->id)->firstOrFail();

        $this->assertSame('10.000000', $plainItem->valor_com_bdi_desonerado);
        $this->assertSame('20.000000', $plainItem->valor_total_desonerado);
        $this->assertSame('11.000000', $bdiItem->valor_com_bdi_desonerado);
        $this->assertSame('22.000000', $bdiItem->valor_total_desonerado);
        $this->assertSame('42.000000', $budget->fresh()->valor_desonerado);

        $this->actingAs($admin)
            ->patch(route('tenant.orcamentos.itens.toggle-bdi', [$tenant, $budget, $bdiItem]), [
                'bdi_percentual' => '25',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('12.500000', $bdiItem->fresh()->valor_com_bdi_desonerado);
        $this->assertSame('25.000000', $bdiItem->fresh()->valor_total_desonerado);
        $this->assertSame('45.000000', $budget->fresh()->valor_desonerado);
    }

    public function test_total_budget_bdi_is_applied_after_quantity_instead_of_per_unit(): void
    {
        [$tenant, $admin] = $this->tenantWithAdmin('bdi-total-lab');
        $insumo = $this->insumo($tenant, $admin, 'BDI-TOTAL', 0.05);

        $unitBudget = $this->budget($tenant, $admin, 'ORC-UNIT', [
            'arredondamento' => 'round_all_2',
            'bdi_tipo' => 'unit_price',
            'bdi_percentual' => 10,
        ]);
        $totalBudget = $this->budget($tenant, $admin, 'ORC-TOTAL', [
            'arredondamento' => 'round_all_2',
            'bdi_tipo' => 'total_budget',
            'bdi_percentual' => 10,
        ]);

        foreach ([$unitBudget, $totalBudget] as $budget) {
            $stage = $this->stage($tenant, $budget, $admin, '1', 'Servicos');
            $this->actingAs($admin)
                ->post(route('tenant.orcamentos.etapas.insumos.store', [$tenant, $budget, $stage]), [
                    'orcamento_insumo_id' => $insumo->id,
                    'quantidade' => '3',
                    'aplicar_bdi' => true,
                ])
                ->assertSessionHasNoErrors();
        }

        $unitItem = OrcamentoItem::query()->where('orcamento_id', $unitBudget->id)->firstOrFail();
        $totalItem = OrcamentoItem::query()->where('orcamento_id', $totalBudget->id)->firstOrFail();

        $this->assertSame('0.180000', $unitItem->valor_total_desonerado);
        $this->assertSame('0.170000', $totalItem->valor_total_desonerado);
    }

    public function test_zero_price_requires_budget_opt_in_and_a_manual_value(): void
    {
        [$tenant, $admin] = $this->tenantWithAdmin('zero-price-lab');
        $insumo = $this->insumo($tenant, $admin, 'ZERO-001', 0);
        $blockedBudget = $this->budget($tenant, $admin, 'ORC-BLOCKED');
        $allowedBudget = $this->budget($tenant, $admin, 'ORC-ALLOWED', [
            'permitir_insumos_preco_zerado' => true,
        ]);
        $blockedStage = $this->stage($tenant, $blockedBudget, $admin, '1', 'Servicos');
        $allowedStage = $this->stage($tenant, $allowedBudget, $admin, '1', 'Servicos');

        $this->actingAs($admin)
            ->post(route('tenant.orcamentos.etapas.insumos.store', [$tenant, $blockedBudget, $blockedStage]), [
                'orcamento_insumo_id' => $insumo->id,
                'quantidade' => '1',
            ])
            ->assertSessionHasErrors('orcamento_insumo_id');

        $this->actingAs($admin)
            ->post(route('tenant.orcamentos.etapas.insumos.store', [$tenant, $allowedBudget, $allowedStage]), [
                'orcamento_insumo_id' => $insumo->id,
                'quantidade' => '1',
            ])
            ->assertSessionHasErrors('valor_unitario_manual');

        $this->actingAs($admin)
            ->post(route('tenant.orcamentos.etapas.insumos.store', [$tenant, $allowedBudget, $allowedStage]), [
                'orcamento_insumo_id' => $insumo->id,
                'quantidade' => '2.5',
                'valor_unitario_manual' => '12,3456',
                'aplicar_bdi' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $item = OrcamentoItem::query()->where('orcamento_id', $allowedBudget->id)->firstOrFail();

        $this->assertTrue((bool) ($item->meta['manual_price'] ?? false));
        $this->assertSame('12.340000', $item->valor_unitario_desonerado);
        $this->assertSame('30.850000', $item->valor_total_desonerado);
    }

    public function test_hierarchical_copy_close_and_report_workflow(): void
    {
        [$tenant, $admin] = $this->tenantWithAdmin('workflow-lab');
        $source = $this->budget($tenant, $admin, 'ORC-SOURCE', [
            'bdi_percentual' => 12.5,
        ]);
        $target = $this->budget($tenant, $admin, 'ORC-TARGET');
        $root = $this->stage($tenant, $source, $admin, '1', 'Infraestrutura');
        $child = $this->stage($tenant, $source, $admin, '1.1', 'Terraplenagem');
        $insumo = $this->insumo($tenant, $admin, 'WF-001', 100);

        $this->actingAs($admin)
            ->post(route('tenant.orcamentos.etapas.insumos.store', [$tenant, $source, $child]), [
                'orcamento_insumo_id' => $insumo->id,
                'quantidade' => '2',
                'aplicar_bdi' => true,
            ])
            ->assertSessionHasNoErrors();

        $sourceItem = OrcamentoItem::query()->where('orcamento_id', $source->id)->firstOrFail();

        $this->actingAs($admin)
            ->post(route('tenant.orcamentos.copy.store', [$tenant, $target]), [
                'source_orcamento_id' => $source->id,
                'item_ids' => [$sourceItem->id],
                'price_mode' => 'source',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('orcamento_etapas', [
            'orcamento_id' => $target->id,
            'ordem' => '1',
            'descricao' => $root->descricao,
        ]);
        $this->assertDatabaseHas('orcamento_etapas', [
            'orcamento_id' => $target->id,
            'ordem' => '1.1',
            'descricao' => $child->descricao,
        ]);
        $this->assertDatabaseHas('orcamento_itens', [
            'orcamento_id' => $target->id,
            'codigo' => 'WF-001',
        ]);

        $this->actingAs($admin)
            ->patch(route('tenant.orcamentos.etapas.toggle-hidden', [$tenant, $source, $child]))
            ->assertRedirect();

        $this->assertTrue((bool) ($child->fresh()->meta['hidden'] ?? false));

        $this->actingAs($admin)
            ->get(route('tenant.orcamentos.relatorios.sintetico', [$tenant, $source]))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($admin)
            ->get(route('tenant.orcamentos.relatorios.resumo', [$tenant, $source]))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $zipResponse = $this->actingAs($admin)
            ->get(route('tenant.orcamentos.relatorios.zip', [
                $tenant,
                $source,
                'reports' => ['sintetico', 'resumo'],
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/zip');

        $zipPath = $zipResponse->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertSame(2, $zip->numFiles);
        $zip->close();

        $this->actingAs($admin)
            ->patch(route('tenant.orcamentos.close', [$tenant, $source]))
            ->assertRedirect();

        $this->assertSame('closed', $source->fresh()->status);

        $this->actingAs($admin)
            ->post(route('tenant.orcamentos.etapas.store', [$tenant, $source]), [
                'descricao' => 'Etapa indevida',
            ])
            ->assertSessionHasErrors('orcamento');
    }

    public function test_three_tenants_keep_catalogs_budgets_and_options_isolated(): void
    {
        $contexts = [];

        foreach (['alpha', 'beta', 'gama'] as $slug) {
            [$tenant, $admin] = $this->tenantWithAdmin($slug);
            $budget = $this->budget($tenant, $admin, 'ORC-001', [
                'descricao' => "Orcamento {$slug}",
            ]);
            $insumo = $this->insumo($tenant, $admin, 'CODIGO-IGUAL', 10 + count($contexts));
            $contexts[] = compact('tenant', 'admin', 'budget', 'insumo', 'slug');
        }

        foreach ($contexts as $index => $context) {
            $response = $this->actingAs($context['admin'])
                ->getJson(route('tenant.orcamentos.insumos.options', [
                    $context['tenant'],
                    $context['budget'],
                    'codigo' => 'CODIGO-IGUAL',
                ]))
                ->assertOk();

            $ids = collect($response->json('options'))->pluck('id');
            $this->assertSame([$context['insumo']->id], $ids->all());

            $foreign = $contexts[($index + 1) % count($contexts)];
            $this->actingAs($context['admin'])
                ->get(route('tenant.orcamentos.show', [$context['tenant'], $foreign['budget']]))
                ->assertNotFound();
        }
    }

    public function test_global_permissions_protect_each_budget_capability(): void
    {
        [$tenant, $admin] = $this->tenantWithAdmin('permissions-lab');
        $user = User::factory()->create();
        $budget = $this->budget($tenant, $admin, 'ORC-PERM');

        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'viewer',
            'status' => 'active',
            'budget_permissions' => [BudgetPermissions::VIEW],
        ]);

        $this->actingAs($user)
            ->get(route('tenant.orcamentos.create', $tenant))
            ->assertForbidden();
        $this->actingAs($user)
            ->get(route('tenant.orcamentos.import.create', $tenant))
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('tenant.orcamentos.insumos.store', $tenant), [])
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('tenant.orcamentos.insumos.import', $tenant), [])
            ->assertForbidden();
        $this->actingAs($user)
            ->get(route('tenant.orcamentos.relatorios.sintetico', [$tenant, $budget]))
            ->assertForbidden();
        $this->actingAs($user)
            ->patch(route('tenant.orcamentos.close', [$tenant, $budget]))
            ->assertForbidden();
    }

    private function tenantWithAdmin(string $slug): array
    {
        $tenant = Tenant::create([
            'slug' => $slug,
            'name' => "Tenant {$slug}",
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_owner',
            'status' => 'active',
            'budget_permissions' => BudgetPermissions::all(),
        ]);

        return [$tenant, $admin];
    }

    private function budget(Tenant $tenant, User $creator, string $code, array $overrides = []): Orcamento
    {
        return Orcamento::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $creator->id,
            'codigo' => $code,
            'descricao' => $overrides['descricao'] ?? "Orcamento {$code}",
            'categoria' => 'Outros',
            'permitir_insumos_preco_zerado' => $overrides['permitir_insumos_preco_zerado'] ?? false,
            'arredondamento' => $overrides['arredondamento'] ?? 'truncate_all_2',
            'encargos_sociais' => 'desonerado',
            'bdi_tipo' => $overrides['bdi_tipo'] ?? 'unit_price',
            'bdi_percentual' => $overrides['bdi_percentual'] ?? 0,
            'status' => 'draft',
        ]);
    }

    private function stage(
        Tenant $tenant,
        Orcamento $budget,
        User $creator,
        string $order,
        string $description,
    ): OrcamentoEtapa {
        return OrcamentoEtapa::create([
            'tenant_id' => $tenant->id,
            'orcamento_id' => $budget->id,
            'created_by_id' => $creator->id,
            'ordem' => $order,
            'descricao' => $description,
            'quantidade' => 1,
        ]);
    }

    private function insumo(
        Tenant $tenant,
        User $creator,
        string $code,
        float $price,
    ): OrcamentoInsumo {
        return OrcamentoInsumo::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $creator->id,
            'banco' => 'PROPRIA',
            'tipo' => 'material',
            'classificacao' => 'Material',
            'codigo_insumo' => $code,
            'descricao' => "Insumo {$code}",
            'unidade' => 'UN',
            'origem_preco' => 'manual',
            'preco_nao_desonerado' => $price,
            'preco_desonerado' => $price,
            'data_referencia' => '2026-07-01',
        ]);
    }
}

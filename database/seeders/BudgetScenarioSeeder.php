<?php

namespace Database\Seeders;

use App\Models\Orcamento;
use App\Models\OrcamentoAcesso;
use App\Models\OrcamentoComposicao;
use App\Models\OrcamentoComposicaoItem;
use App\Models\OrcamentoEtapa;
use App\Models\OrcamentoInsumo;
use App\Models\OrcamentoInsumoGrupo;
use App\Models\OrcamentoItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BudgetPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class BudgetScenarioSeeder extends Seeder
{
    private const PASSWORD = 'Senha1!';

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Este cenario visual so pode ser criado no ambiente local.');
        }

        foreach ([
            ['slug' => 'orcamentos-alpha', 'name' => 'Laboratorio Orcamentos Alpha', 'prefix' => 'ALP', 'factor' => 1.00],
            ['slug' => 'orcamentos-beta', 'name' => 'Laboratorio Orcamentos Beta', 'prefix' => 'BET', 'factor' => 1.17],
            ['slug' => 'orcamentos-gama', 'name' => 'Laboratorio Orcamentos Gama', 'prefix' => 'GAM', 'factor' => 0.83],
        ] as $tenantData) {
            $this->seedTenant($tenantData);
        }
    }

    private function seedTenant(array $tenantData): void
    {
        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => $tenantData['slug']],
            [
                'name' => $tenantData['name'],
                'plan' => 'starter',
                'status' => 'active',
            ],
        );
        $users = $this->seedUsers($tenant, $tenantData['prefix']);
        $groups = $this->seedGroups($tenant, $users['owner']);
        $insumos = $this->seedInsumos(
            $tenant,
            $users['catalog'],
            $groups,
            $tenantData['prefix'],
            (float) $tenantData['factor'],
        );
        $composicoes = $this->seedComposicoes(
            $tenant,
            $users['catalog'],
            $insumos,
            $tenantData['prefix'],
            (float) $tenantData['factor'],
        );
        $budgets = $this->seedBudgets(
            $tenant,
            $users['author'],
            $insumos,
            $composicoes,
            $tenantData['prefix'],
        );

        foreach ($budgets as $index => $budget) {
            OrcamentoAcesso::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'orcamento_id' => $budget->id,
                    'user_id' => $users['editor']->id,
                ],
                [
                    'access_level' => OrcamentoAcesso::LEVEL_EDIT,
                    'granted_by_id' => $users['author']->id,
                ],
            );

            if ($index % 2 === 0) {
                OrcamentoAcesso::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'orcamento_id' => $budget->id,
                        'user_id' => $users['viewer']->id,
                    ],
                    [
                        'access_level' => OrcamentoAcesso::LEVEL_VIEW,
                        'granted_by_id' => $users['author']->id,
                    ],
                );
            }
        }
    }

    private function seedUsers(Tenant $tenant, string $prefix): array
    {
        $profiles = [
            'owner' => [
                'name' => "Owner Orcamentos {$prefix}",
                'role' => 'tenant_owner',
                'permissions' => BudgetPermissions::all(),
            ],
            'author' => [
                'name' => "Autor Orcamentos {$prefix}",
                'role' => 'engenheiro_custos',
                'permissions' => [
                    BudgetPermissions::VIEW,
                    BudgetPermissions::CREATE,
                    BudgetPermissions::IMPORT,
                    BudgetPermissions::EDIT,
                    BudgetPermissions::FINALIZE,
                    BudgetPermissions::REPORTS,
                ],
            ],
            'editor' => [
                'name' => "Editor Orcamentos {$prefix}",
                'role' => 'engineer',
                'permissions' => [
                    BudgetPermissions::VIEW,
                    BudgetPermissions::EDIT,
                    BudgetPermissions::REPORTS,
                ],
            ],
            'viewer' => [
                'name' => "Visualizador Orcamentos {$prefix}",
                'role' => 'viewer',
                'permissions' => [
                    BudgetPermissions::VIEW,
                    BudgetPermissions::REPORTS,
                ],
            ],
            'catalog' => [
                'name' => "Catalogos Orcamentos {$prefix}",
                'role' => 'engineer',
                'permissions' => [
                    BudgetPermissions::VIEW,
                    BudgetPermissions::MANAGE_CATALOGS,
                    BudgetPermissions::IMPORT_CATALOGS,
                ],
            ],
            'blocked' => [
                'name' => "Sem Acesso Orcamentos {$prefix}",
                'role' => 'viewer',
                'permissions' => [],
            ],
        ];
        $users = [];

        foreach ($profiles as $key => $profile) {
            $email = strtolower("{$key}.orcamentos.{$prefix}@obras.test");
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $profile['name'],
                    'password' => Hash::make(self::PASSWORD),
                    'email_verified_at' => now(),
                ],
            );

            $tenant->memberships()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'role' => $profile['role'],
                    'status' => 'active',
                    'budget_permissions' => $profile['permissions'],
                ],
            );
            $users[$key] = $user;
        }

        return $users;
    }

    private function seedGroups(Tenant $tenant, User $creator): array
    {
        $groups = [];

        foreach ([
            'Materiais' => 'Materiais de aplicacao direta.',
            'Mao de obra' => 'Equipes e profissionais.',
            'Equipamentos' => 'Equipamentos produtivos e improdutivos.',
        ] as $name => $description) {
            $group = OrcamentoInsumoGrupo::withTrashed()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'nome' => $name],
                [
                    'created_by_id' => $creator->id,
                    'descricao' => $description,
                    'deleted_at' => null,
                ],
            );
            $groups[$name] = $group;
        }

        return $groups;
    }

    private function seedInsumos(
        Tenant $tenant,
        User $creator,
        array $groups,
        string $prefix,
        float $factor,
    ): array {
        $definitions = [
            'cimento' => [
                'group' => 'Materiais',
                'type' => 'material',
                'class' => 'Material',
                'code' => "{$prefix}-MAT-001",
                'description' => 'Cimento Portland CP II - saco 50 kg',
                'unit' => 'SC',
                'price' => 37.456789,
            ],
            'aco' => [
                'group' => 'Materiais',
                'type' => 'material',
                'class' => 'Material',
                'code' => "{$prefix}-MAT-002",
                'description' => 'Aco CA-50 fornecimento e corte',
                'unit' => 'KG',
                'price' => 8.987654,
            ],
            'pedreiro' => [
                'group' => 'Mao de obra',
                'type' => 'labor',
                'class' => 'Mao de obra',
                'code' => "{$prefix}-MDO-001",
                'description' => 'Pedreiro com encargos complementares',
                'unit' => 'H',
                'price' => 31.337501,
            ],
            'escavadeira' => [
                'group' => 'Equipamentos',
                'type' => 'equipment',
                'class' => 'Equipamento',
                'code' => "{$prefix}-EQP-001",
                'description' => 'Escavadeira hidraulica sobre esteiras',
                'unit' => 'CHP',
                'price' => 310.999999,
                'idle' => 128.445566,
            ],
            'zerado' => [
                'group' => 'Materiais',
                'type' => 'material',
                'class' => 'Material',
                'code' => "{$prefix}-ZER-001",
                'description' => 'Insumo sem referencia de preco',
                'unit' => 'UN',
                'price' => 0,
            ],
        ];
        $insumos = [];

        foreach ($definitions as $key => $definition) {
            $price = (float) $definition['price'] * $factor;
            $idle = (float) ($definition['idle'] ?? 0) * $factor;
            $insumos[$key] = OrcamentoInsumo::withTrashed()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'codigo_insumo' => $definition['code'],
                ],
                [
                    'created_by_id' => $creator->id,
                    'grupo_id' => $groups[$definition['group']]->id,
                    'banco' => 'PROPRIA',
                    'tipo' => $definition['type'],
                    'classificacao' => $definition['class'],
                    'descricao' => $definition['description'],
                    'unidade' => $definition['unit'],
                    'uf' => 'PA',
                    'origem_preco' => 'manual',
                    'preco_nao_desonerado' => round($price * 1.08, 6),
                    'preco_desonerado' => round($price, 6),
                    'custo_improdutivo_nao_desonerado' => round($idle * 1.08, 6),
                    'custo_improdutivo_desonerado' => round($idle, 6),
                    'data_referencia' => '2026-07-01',
                    'observacao' => 'Registro criado para os testes completos de orcamentos.',
                    'deleted_at' => null,
                ],
            );
        }

        return $insumos;
    }

    private function seedComposicoes(
        Tenant $tenant,
        User $creator,
        array $insumos,
        string $prefix,
        float $factor,
    ): array {
        $definitions = [
            'concreto' => [
                'code' => "{$prefix}-COMP-001",
                'description' => 'Concreto estrutural preparado em obra',
                'type' => 'Estruturas de concreto',
                'unit' => 'M3',
                'method' => 'round_2',
                'price' => 548.765432 * $factor,
                'items' => [
                    ['insumo' => 'cimento', 'coefficient' => 7.25],
                    ['insumo' => 'pedreiro', 'coefficient' => 1.75],
                ],
            ],
            'armadura' => [
                'code' => "{$prefix}-COMP-002",
                'description' => 'Armadura CA-50 montada',
                'type' => 'Armaduras',
                'unit' => 'KG',
                'method' => 'truncate_2',
                'price' => 14.239876 * $factor,
                'items' => [
                    ['insumo' => 'aco', 'coefficient' => 1.05],
                    ['insumo' => 'pedreiro', 'coefficient' => 0.08],
                ],
            ],
            'escavacao' => [
                'code' => "{$prefix}-COMP-003",
                'description' => 'Escavacao mecanizada em solo',
                'type' => 'Movimento de terra',
                'unit' => 'M3',
                'method' => 'sicro3_round_4_2',
                'model' => 'SICRO3',
                'price' => 12.345678 * $factor,
                'items' => [
                    ['insumo' => 'escavadeira', 'coefficient' => 0.035],
                    ['insumo' => 'pedreiro', 'coefficient' => 0.02],
                ],
            ],
        ];
        $composicoes = [];

        foreach ($definitions as $key => $definition) {
            $price = (float) $definition['price'];
            $composition = OrcamentoComposicao::withTrashed()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'codigo' => $definition['code'],
                ],
                [
                    'created_by_id' => $creator->id,
                    'is_global' => false,
                    'descricao' => $definition['description'],
                    'tipo_composicao' => $definition['type'],
                    'unidade' => $definition['unit'],
                    'uf' => null,
                    'modelo' => $definition['model'] ?? 'PROPRIA',
                    'metodo_calculo' => $definition['method'],
                    'producao_equipe' => $key === 'escavacao' ? 28 : null,
                    'fator_influencia_chuvas' => $key === 'escavacao' ? 0.047 : null,
                    'observacao' => 'Composicao analitica do laboratorio de orcamentos.',
                    'preco_onerado' => round($price * 1.08, 6),
                    'preco_desonerado' => round($price, 6),
                    'deleted_at' => null,
                ],
            );

            foreach ($definition['items'] as $itemDefinition) {
                $insumo = $insumos[$itemDefinition['insumo']];
                $coefficient = (float) $itemDefinition['coefficient'];
                OrcamentoComposicaoItem::withTrashed()->updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'orcamento_composicao_id' => $composition->id,
                        'orcamento_insumo_id' => $insumo->id,
                    ],
                    [
                        'created_by_id' => $creator->id,
                        'item_type' => 'insumo',
                        'sicro3_section' => $key === 'escavacao' && $itemDefinition['insumo'] === 'escavadeira'
                            ? 'equipamentos'
                            : ($itemDefinition['insumo'] === 'pedreiro' ? 'mao_de_obra' : 'material'),
                        'base' => 'PROPRIA',
                        'codigo' => $insumo->codigo_insumo,
                        'descricao' => $insumo->descricao,
                        'tipo' => $insumo->tipo,
                        'unidade' => $insumo->unidade,
                        'preco_unitario_onerado' => $insumo->preco_nao_desonerado,
                        'preco_unitario_desonerado' => $insumo->preco_desonerado,
                        'coeficiente' => $coefficient,
                        'preco_onerado' => round((float) $insumo->preco_nao_desonerado * $coefficient, 6),
                        'preco_desonerado' => round((float) $insumo->preco_desonerado * $coefficient, 6),
                        'deleted_at' => null,
                    ],
                );
            }

            $composicoes[$key] = $composition;
        }

        return $composicoes;
    }

    private function seedBudgets(
        Tenant $tenant,
        User $creator,
        array $insumos,
        array $composicoes,
        string $prefix,
    ): Collection {
        $definitions = [
            [
                'code' => "{$prefix}-ORC-001",
                'description' => 'Edificacao - arredondamento integral',
                'rounding' => 'round_all_2',
                'bdi_type' => 'unit_price',
                'bdi' => 18.75,
                'allow_zero' => false,
                'status' => 'draft',
            ],
            [
                'code' => "{$prefix}-ORC-002",
                'description' => 'Infraestrutura - composicoes arredondadas',
                'rounding' => 'round_compositions_2',
                'bdi_type' => 'unit_price',
                'bdi' => 24.37,
                'allow_zero' => false,
                'status' => 'draft',
            ],
            [
                'code' => "{$prefix}-ORC-003",
                'description' => 'Reforma - unitarios truncados',
                'rounding' => 'round_and_truncate_unit',
                'bdi_type' => 'unit_price',
                'bdi' => 12.5,
                'allow_zero' => true,
                'status' => 'draft',
            ],
            [
                'code' => "{$prefix}-ORC-004",
                'description' => 'Terraplenagem - padrao TCU',
                'rounding' => 'truncate_all_2',
                'bdi_type' => 'unit_price',
                'bdi' => 29.78,
                'allow_zero' => false,
                'status' => 'closed',
            ],
            [
                'code' => "{$prefix}-ORC-005",
                'description' => 'Obra linear - BDI no total sem arredondamento',
                'rounding' => 'none',
                'bdi_type' => 'total_budget',
                'bdi' => 7.125,
                'allow_zero' => true,
                'status' => 'draft',
            ],
        ];

        return collect($definitions)->map(function (array $definition, int $index) use (
            $tenant,
            $creator,
            $insumos,
            $composicoes,
        ): Orcamento {
            $budget = Orcamento::withTrashed()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'codigo' => $definition['code'],
                ],
                [
                    'created_by_id' => $creator->id,
                    'closed_by_id' => $definition['status'] === 'closed' ? $creator->id : null,
                    'descricao' => $definition['description'],
                    'categoria' => $index % 2 === 0 ? 'Pavimentacao e drenagem' : 'Outros',
                    'prazo_entrega_at' => now()->addDays(30 + ($index * 15)),
                    'permitir_insumos_preco_zerado' => $definition['allow_zero'],
                    'arredondamento' => $definition['rounding'],
                    'encargos_sociais' => $index % 2 === 0 ? 'desonerado' : 'nao_desonerado',
                    'encargos_horista' => 95.54,
                    'encargos_mensalista' => 53.45,
                    'bdi_tipo' => $definition['bdi_type'],
                    'bdi_percentual' => $definition['bdi'],
                    'base_references' => [[
                        'nome' => 'PROPRIA',
                        'uf' => 'PA',
                        'localidade' => 'Para',
                        'data' => '07/2026',
                    ]],
                    'status' => $definition['status'],
                    'closed_at' => $definition['status'] === 'closed' ? now() : null,
                    'deleted_at' => null,
                ],
            );

            $root = $this->upsertStage($tenant, $budget, $creator, '1', 'Servicos preliminares');
            $child = $this->upsertStage($tenant, $budget, $creator, '1.1', 'Mobilizacao e canteiro');
            $structure = $this->upsertStage($tenant, $budget, $creator, '2', 'Estrutura e acabamentos');

            $this->upsertBudgetItem($tenant, $budget, $child, $creator, [
                'type' => 'composicao',
                'composition' => $composicoes['escavacao'],
                'order' => 1,
                'quantity' => 17.357 + $index,
                'apply_bdi' => true,
                'differentiated_bdi' => $index === 1 ? 15.375 : null,
            ]);
            $this->upsertBudgetItem($tenant, $budget, $structure, $creator, [
                'type' => 'composicao',
                'composition' => $composicoes['concreto'],
                'order' => 1,
                'quantity' => 8.125 + ($index * 0.5),
                'apply_bdi' => true,
            ]);
            $this->upsertBudgetItem($tenant, $budget, $structure, $creator, [
                'type' => 'composicao',
                'composition' => $composicoes['armadura'],
                'order' => 2,
                'quantity' => 148.875 + ($index * 12.5),
                'apply_bdi' => false,
            ]);
            $this->upsertBudgetItem($tenant, $budget, $root, $creator, [
                'type' => 'insumo',
                'insumo' => $insumos['cimento'],
                'order' => 2,
                'quantity' => 32.75 + $index,
                'apply_bdi' => $index % 2 === 0,
            ]);

            if ($definition['allow_zero']) {
                $this->upsertBudgetItem($tenant, $budget, $root, $creator, [
                    'type' => 'insumo',
                    'insumo' => $insumos['zerado'],
                    'order' => 3,
                    'quantity' => 4.25,
                    'manual_price' => 19.876543 + $index,
                    'apply_bdi' => false,
                ]);
            }

            $this->recalculateBudget($budget);

            return $budget;
        });
    }

    private function upsertStage(
        Tenant $tenant,
        Orcamento $budget,
        User $creator,
        string $order,
        string $description,
    ): OrcamentoEtapa {
        return OrcamentoEtapa::withTrashed()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'orcamento_id' => $budget->id,
                'ordem' => $order,
            ],
            [
                'created_by_id' => $creator->id,
                'descricao' => $description,
                'quantidade' => 1,
                'meta' => ['scenario' => 'budget_complete_test'],
                'deleted_at' => null,
            ],
        );
    }

    private function upsertBudgetItem(
        Tenant $tenant,
        Orcamento $budget,
        OrcamentoEtapa $stage,
        User $creator,
        array $definition,
    ): OrcamentoItem {
        $source = $definition['composition'] ?? $definition['insumo'];
        $manualPrice = $definition['manual_price'] ?? null;
        $unitOnerado = $manualPrice ?? (
            $definition['type'] === 'composicao'
                ? $source->preco_onerado
                : $source->preco_nao_desonerado
        );
        $unitDesonerado = $manualPrice ?? (
            $definition['type'] === 'composicao'
                ? $source->preco_desonerado
                : $source->preco_desonerado
        );
        $meta = [
            'scenario' => 'budget_complete_test',
            'base_label' => 'Base propria',
            'manual_price' => $manualPrice !== null,
            'manual_price_value' => $manualPrice,
        ];

        if ($definition['differentiated_bdi'] ?? null) {
            $meta['bdi_percentual'] = $definition['differentiated_bdi'];
            $meta['bdi_diferenciado'] = true;
        }

        return OrcamentoItem::withTrashed()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'orcamento_id' => $budget->id,
                'orcamento_etapa_id' => $stage->id,
                'codigo' => $definition['type'] === 'composicao'
                    ? $source->codigo
                    : $source->codigo_insumo,
            ],
            [
                'created_by_id' => $creator->id,
                'item_type' => $definition['type'],
                'orcamento_composicao_id' => $definition['type'] === 'composicao' ? $source->id : null,
                'orcamento_insumo_id' => $definition['type'] === 'insumo' ? $source->id : null,
                'ordem' => $definition['order'],
                'banco' => 'PROPRIA',
                'descricao' => $source->descricao,
                'unidade' => $source->unidade,
                'quantidade' => $definition['quantity'],
                'valor_unitario_nao_desonerado' => $unitOnerado,
                'valor_unitario_desonerado' => $unitDesonerado,
                'aplicar_bdi' => $definition['apply_bdi'],
                'meta' => $meta,
                'deleted_at' => null,
            ],
        );
    }

    private function recalculateBudget(Orcamento $budget): void
    {
        $stages = OrcamentoEtapa::query()
            ->where('tenant_id', $budget->tenant_id)
            ->where('orcamento_id', $budget->id)
            ->with('itens')
            ->get();
        $unitMethod = $this->unitCalculationMethod($budget);
        $totalMethod = $this->totalCalculationMethod($budget);

        foreach ($stages as $stage) {
            foreach ($stage->itens as $item) {
                $meta = $item->meta ?? [];
                $bdi = (float) ($meta['bdi_percentual'] ?? $budget->bdi_percentual);
                $multiplier = $item->aplicar_bdi ? 1 + ($bdi / 100) : 1;
                $unitOnerado = $this->money($item->valor_unitario_nao_desonerado, $unitMethod);
                $unitDesonerado = $this->money($item->valor_unitario_desonerado, $unitMethod);
                $withBdiOnerado = $this->money($unitOnerado * $multiplier, $unitMethod);
                $withBdiDesonerado = $this->money($unitDesonerado * $multiplier, $unitMethod);
                $quantity = (float) $item->quantidade;
                $totalOnerado = $budget->bdi_tipo === 'total_budget' && $item->aplicar_bdi
                    ? $unitOnerado * $quantity * $multiplier
                    : $withBdiOnerado * $quantity;
                $totalDesonerado = $budget->bdi_tipo === 'total_budget' && $item->aplicar_bdi
                    ? $unitDesonerado * $quantity * $multiplier
                    : $withBdiDesonerado * $quantity;

                $item->forceFill([
                    'valor_unitario_nao_desonerado' => round($unitOnerado, 6),
                    'valor_unitario_desonerado' => round($unitDesonerado, 6),
                    'valor_com_bdi_nao_desonerado' => round($withBdiOnerado, 6),
                    'valor_com_bdi_desonerado' => round($withBdiDesonerado, 6),
                    'valor_total_nao_desonerado' => round($this->money($totalOnerado, $totalMethod), 6),
                    'valor_total_desonerado' => round($this->money($totalDesonerado, $totalMethod), 6),
                ])->save();
            }
        }

        $stages->each->load('itens');
        $memo = [];
        $calculateStage = function (OrcamentoEtapa $stage) use (&$calculateStage, &$memo, $stages, $totalMethod): array {
            if (isset($memo[$stage->id])) {
                return $memo[$stage->id];
            }

            $onerado = (float) $stage->itens->sum('valor_total_nao_desonerado');
            $desonerado = (float) $stage->itens->sum('valor_total_desonerado');

            foreach ($stages->filter(fn (OrcamentoEtapa $candidate): bool => $this->isDirectChild($candidate, $stage)) as $child) {
                $childTotal = $calculateStage($child);
                $onerado += $childTotal['onerado'];
                $desonerado += $childTotal['desonerado'];
            }

            $memo[$stage->id] = [
                'onerado' => $this->money($onerado, $totalMethod),
                'desonerado' => $this->money($desonerado, $totalMethod),
            ];
            $stage->forceFill([
                'valor_nao_desonerado' => round($memo[$stage->id]['onerado'], 6),
                'valor_desonerado' => round($memo[$stage->id]['desonerado'], 6),
            ])->save();

            return $memo[$stage->id];
        };

        foreach ($stages as $stage) {
            $calculateStage($stage);
        }

        $roots = $stages->filter(fn (OrcamentoEtapa $stage): bool => ! str_contains((string) $stage->ordem, '.'));
        $budget->forceFill([
            'valor_nao_desonerado' => round($this->money(
                $roots->sum(fn (OrcamentoEtapa $stage): float => $memo[$stage->id]['onerado']),
                $totalMethod,
            ), 6),
            'valor_desonerado' => round($this->money(
                $roots->sum(fn (OrcamentoEtapa $stage): float => $memo[$stage->id]['desonerado']),
                $totalMethod,
            ), 6),
        ])->save();
    }

    private function isDirectChild(OrcamentoEtapa $candidate, OrcamentoEtapa $parent): bool
    {
        $candidateOrder = (string) $candidate->ordem;
        $parentOrder = (string) $parent->ordem;

        return str_starts_with($candidateOrder, $parentOrder.'.')
            && substr_count($candidateOrder, '.') === substr_count($parentOrder, '.') + 1;
    }

    private function unitCalculationMethod(Orcamento $budget): string
    {
        return match ($budget->arredondamento) {
            'round_all_2', 'round_compositions_2' => 'round',
            'none' => 'none',
            default => 'truncate',
        };
    }

    private function totalCalculationMethod(Orcamento $budget): string
    {
        return match ($budget->arredondamento) {
            'round_all_2', 'round_compositions_2', 'round_and_truncate_unit' => 'round',
            'none' => 'none',
            default => 'truncate',
        };
    }

    private function money(float|int|string|null $value, string $method): float
    {
        $number = (float) ($value ?? 0);

        return match ($method) {
            'round' => round($number, 2),
            'none' => $number,
            default => $this->truncate($number),
        };
    }

    private function truncate(float $value): float
    {
        $scaled = $value * 100;
        $epsilon = 1e-9;

        return ($value < 0 ? ceil($scaled - $epsilon) : floor($scaled + $epsilon)) / 100;
    }
}

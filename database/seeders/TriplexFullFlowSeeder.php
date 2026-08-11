<?php

namespace Database\Seeders;

use App\Models\BoletimMedicao;
use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\Empresa;
use App\Models\FolhaRosto;
use App\Models\FolhaRostoAnalise;
use App\Models\FolhaRostoFluxoHistorico;
use App\Models\MedicaoIndiceReajuste;
use App\Models\MedicaoIndiceReajusteCompetencia;
use App\Models\MedicaoItem;
use App\Models\MedicaoItemAdditive;
use App\Models\MedicaoItemReajusteIndice;
use App\Models\MedicaoItemVersion;
use App\Models\Obra;
use App\Models\Orcamento;
use App\Models\OrcamentoComposicao;
use App\Models\OrcamentoEtapa;
use App\Models\OrcamentoInsumo;
use App\Models\OrcamentoItem;
use App\Models\OrdemServico;
use App\Models\OrdemServicoAnalise;
use App\Models\OrdemServicoComentario;
use App\Models\OrdemServicoContractSetting;
use App\Models\OrdemServicoObraResponsavel;
use App\Models\OrdemServicoResponsavel;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TipoEmpresa;
use App\Models\User;
use App\Support\BudgetPermissions;
use App\Support\MedicaoItemValueResolver;
use App\Support\MedicaoPermissions;
use App\Support\MedicaoReajusteCalculator;
use App\Support\OrdemServicoPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TriplexFullFlowSeeder extends Seeder
{
    private const TENANT_SLUG = 'triplex';

    private const CONTRACT_CODE = '2026/08';

    private const ITEM_COUNT = 1000;

    private const MONTH_COUNT = 15;

    private const STAGES = [
        'Servicos preliminares e canteiro',
        'Movimentacao de terra',
        'Fundacoes e infraestrutura',
        'Estrutura do pavimento terreo',
        'Estrutura dos pavimentos superiores',
        'Alvenarias e vedacoes',
        'Cobertura e estrutura de telhado',
        'Impermeabilizacoes',
        'Instalacoes hidrossanitarias',
        'Instalacoes eletricas e dados',
        'Climatizacao e ventilacao',
        'Esquadrias e vidros',
        'Revestimentos internos',
        'Revestimentos externos e fachadas',
        'Pisos e rodapes',
        'Pintura e acabamentos',
        'Loucas, metais e acessorios',
        'Marcenaria e mobiliario fixo',
        'Paisagismo e urbanizacao',
        'Limpeza, testes e entrega',
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('A massa Triplex so pode ser executada no ambiente local.');
        }

        $user = User::query()->where('email', 'admin@obras.test')->first();

        if (! $user) {
            throw new RuntimeException('Usuario admin@obras.test nao encontrado.');
        }

        $existing = Tenant::query()->where('slug', self::TENANT_SLUG)->first();

        if ($existing) {
            $summary = $this->audit($existing);
            $this->command?->warn('O tenant Triplex ja existe. Nenhum dado foi sobrescrito.');
            $this->printSummary($summary);

            return;
        }

        $summary = DB::transaction(function () use ($user): array {
            $tenant = $this->createTenant($user);
            [$contract, $obra, $companies] = $this->createContractStructure($tenant, $user);
            [$insumos, $composicoes] = $this->createCatalog($tenant, $user);
            [$baseBudget, $baseStages, $baseItems] = $this->createBaseBudget(
                $tenant,
                $user,
                $companies['cliente'],
                $insumos,
                $composicoes
            );
            [$revisedBudget, $revisedStages, $revisedItems] = $this->createRevisedBudget(
                $tenant,
                $user,
                $companies['cliente'],
                $baseBudget,
                $baseStages,
                $baseItems
            );
            $medicaoItems = $this->importBudgetBase($tenant, $contract, $user, $baseBudget, $baseStages, $baseItems);
            $this->applyBudgetRevision(
                $tenant,
                $contract,
                $user,
                $revisedBudget,
                $revisedStages,
                $revisedItems,
                $medicaoItems
            );
            $detailItems = MedicaoItem::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $contract->id)
                ->where('nivel', 2)
                ->with('versions')
                ->orderBy('item')
                ->get();
            $index = $this->createAdjustmentIndex($tenant, $contract, $user, $detailItems);
            $order = $this->createServiceOrder($tenant, $contract, $obra, $companies, $user, $detailItems);
            $this->createMeasurementCycle($tenant, $contract, $obra, $companies, $user, $order, $detailItems);

            $contract->forceFill([
                'total_value' => round($detailItems->sum(fn (MedicaoItem $item): float => (float) $item->valor_total), 2),
            ])->save();

            return $this->audit($tenant, $contract, $index);
        }, 3);

        $this->printSummary($summary);
    }

    private function createTenant(User $user): Tenant
    {
        $tenant = Tenant::create([
            'slug' => self::TENANT_SLUG,
            'name' => 'Triplex',
            'cnpj' => '48.620.260/0001-08',
            'plan' => 'professional',
            'status' => 'active',
            'settings' => [
                'persistent_test_scenario' => true,
                'scenario' => 'Residencia Triplex - orcamento, OS e 15 medicoes',
            ],
        ]);

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'tenant_owner',
            'status' => 'active',
            'budget_permissions' => BudgetPermissions::all(),
            'ordem_servico_permissions' => OrdemServicoPermissions::all(),
            'medicao_permissions' => MedicaoPermissions::all(),
            'invited_at' => '2026-07-20 09:00:00',
            'joined_at' => '2026-07-20 09:05:00',
        ]);

        return $tenant;
    }

    private function createContractStructure(Tenant $tenant, User $user): array
    {
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => self::CONTRACT_CODE,
            'name' => 'Construcao Residencial Triplex',
            'description' => 'Construcao completa de residencia triplex com tres pavimentos, area de lazer e urbanizacao.',
            'client_company_name' => 'Familia Horizonte Participacoes Ltda.',
            'contractor_company_name' => 'Construtora Casa Alta Ltda.',
            'total_value' => 0,
            'currency' => 'BRL',
            'city' => 'Belem',
            'state' => 'PA',
            'starts_at' => '2026-08-01',
            'ends_at' => '2027-10-31',
            'status' => 'active',
            'measurement_mode' => 'controlled',
        ]);

        ContractParticipant::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'manager',
            'status' => 'active',
            'ordem_servico_permissions' => OrdemServicoPermissions::all(),
            'medicao_permissions' => MedicaoPermissions::all(),
            'joined_at' => '2026-07-20 09:05:00',
        ]);

        $types = collect(['cliente', 'construtora', 'gerenciadora'])
            ->mapWithKeys(fn (string $type): array => [
                $type => TipoEmpresa::query()->firstOrCreate(['nome' => $type]),
            ]);
        $companies = [
            'cliente' => Empresa::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'tipo_empresa_id' => $types['cliente']->id,
                'nome' => 'Familia Horizonte Participacoes Ltda.',
                'cnpj' => '48.620.260/0001-08',
                'sigla' => 'FHP',
            ]),
            'construtora' => Empresa::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'tipo_empresa_id' => $types['construtora']->id,
                'nome' => 'Construtora Casa Alta Ltda.',
                'cnpj' => '37.184.290/0001-66',
                'sigla' => 'CCA',
            ]),
            'gerenciadora' => Empresa::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'tipo_empresa_id' => $types['gerenciadora']->id,
                'nome' => 'Gerenciadora Norte Engenharia Ltda.',
                'cnpj' => '62.305.140/0001-17',
                'sigla' => 'GNE',
            ]),
        ];
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'codigo' => '001',
            'nome' => 'Residencia Triplex Horizonte',
            'tipo' => 'pai',
        ]);

        $contract->forceFill([
            'obra_id' => $obra->id,
            'cliente_empresa_id' => $companies['cliente']->id,
            'construtora_empresa_id' => $companies['construtora']->id,
            'fiscalizadora_empresa_id' => $companies['gerenciadora']->id,
        ])->save();
        TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->update(['empresa_id' => $companies['gerenciadora']->id]);

        OrdemServicoContractSetting::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'require_project' => false,
            'require_document' => false,
            'require_deadline' => true,
            'require_execution_responsible' => true,
            'created_by_id' => $user->id,
            'updated_by_id' => $user->id,
        ]);
        foreach (['fiscal', 'aprovador'] as $type) {
            OrdemServicoObraResponsavel::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'user_id' => $user->id,
                'created_by_id' => $user->id,
                'tipo' => $type,
                'status' => 'active',
            ]);
        }

        return [$contract, $obra, $companies];
    }

    private function createCatalog(Tenant $tenant, User $user): array
    {
        $now = Carbon::parse('2026-07-10 08:00:00');
        $inputRows = [];

        for ($index = 1; $index <= 600; $index++) {
            $price = round(12.5 + (($index * 19) % 420) * 1.37, 6);
            $inputRows[] = [
                'tenant_id' => $tenant->id,
                'created_by_id' => $user->id,
                'banco' => $index % 9 === 0 ? 'SICRO3' : ($index % 7 === 0 ? 'PROPRIA' : 'SINAPI'),
                'tipo' => $index % 4 === 0 ? 'servico' : 'material',
                'codigo_insumo' => 'TRP-INS-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'descricao' => 'Insumo residencial detalhado '.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'unidade' => $this->unitFor($index),
                'uf' => 'PA',
                'origem_preco' => 'massa_triplex',
                'preco_nao_desonerado' => $price,
                'preco_desonerado' => round($price * 0.965, 6),
                'data_referencia' => '2026-07-01',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertChunks('orcamento_insumos', $inputRows);
        $insumos = OrcamentoInsumo::query()
            ->where('tenant_id', $tenant->id)
            ->where('origem_preco', 'massa_triplex')
            ->orderBy('codigo_insumo')
            ->get();

        $compositionRows = [];
        for ($index = 1; $index <= 400; $index++) {
            $price = round(75 + (($index * 23) % 900) * 1.19, 6);
            $compositionRows[] = [
                'tenant_id' => $tenant->id,
                'created_by_id' => $user->id,
                'is_global' => false,
                'codigo' => 'TRP-CMP-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'descricao' => 'Composicao executiva da residencia triplex '.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'tipo_composicao' => self::STAGES[($index - 1) % count(self::STAGES)],
                'unidade' => $this->unitFor($index + 2),
                'uf' => 'PA',
                'modelo' => $index % 8 === 0 ? 'SICRO3' : 'SINAPI',
                'metodo_calculo' => 'itens',
                'base_references' => json_encode([['modelo' => 'SINAPI', 'uf' => 'PA', 'referencia' => '07/2026']]),
                'preco_onerado' => $price,
                'preco_desonerado' => round($price * 0.965, 6),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertChunks('orcamento_composicoes', $compositionRows);
        $composicoes = OrcamentoComposicao::query()
            ->where('tenant_id', $tenant->id)
            ->where('codigo', 'like', 'TRP-CMP-%')
            ->orderBy('codigo')
            ->get();
        $analyticRows = [];
        foreach ($composicoes as $offset => $composition) {
            foreach ([0, 1] as $component) {
                $input = $insumos[($offset * 2 + $component) % $insumos->count()];
                $coefficient = $component === 0 ? 1.25 : 0.35;
                $analyticRows[] = [
                    'tenant_id' => $tenant->id,
                    'orcamento_composicao_id' => $composition->id,
                    'created_by_id' => $user->id,
                    'item_type' => 'insumo',
                    'orcamento_insumo_id' => $input->id,
                    'base' => $input->banco,
                    'codigo' => $input->codigo_insumo,
                    'descricao' => $input->descricao,
                    'tipo' => $input->tipo,
                    'unidade' => $input->unidade,
                    'preco_unitario_onerado' => $input->preco_nao_desonerado,
                    'preco_unitario_desonerado' => $input->preco_desonerado,
                    'coeficiente' => $coefficient,
                    'preco_onerado' => round((float) $input->preco_nao_desonerado * $coefficient, 6),
                    'preco_desonerado' => round((float) $input->preco_desonerado * $coefficient, 6),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        $this->insertChunks('orcamento_composicao_items', $analyticRows);

        return [$insumos, $composicoes];
    }

    private function createBaseBudget(
        Tenant $tenant,
        User $user,
        Empresa $client,
        Collection $insumos,
        Collection $composicoes
    ): array {
        $budget = Orcamento::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $user->id,
            'closed_by_id' => $user->id,
            'cliente_empresa_id' => $client->id,
            'codigo' => 'TRP-ORC-BASE-2026',
            'descricao' => 'Orcamento executivo - Residencia Triplex Horizonte',
            'categoria' => 'Edificacao residencial de alto padrao',
            'prazo_entrega_at' => '2027-10-31 18:00:00',
            'permitir_insumos_preco_zerado' => false,
            'is_licitacao' => false,
            'arredondamento' => 'truncate_all_2',
            'encargos_sociais' => 'nao_desonerado',
            'bdi_tipo' => 'unit_price',
            'bdi_percentual' => 22.5,
            'base_references' => [
                ['modelo' => 'SINAPI', 'uf' => 'PA', 'referencia' => '07/2026'],
                ['modelo' => 'SICRO3', 'uf' => 'PA', 'referencia' => '07/2026'],
            ],
            'status' => 'closed',
            'closed_at' => '2026-07-25 17:30:00',
        ]);

        $stages = collect();
        foreach (self::STAGES as $offset => $description) {
            $stages->push(OrcamentoEtapa::create([
                'tenant_id' => $tenant->id,
                'orcamento_id' => $budget->id,
                'created_by_id' => $user->id,
                'ordem' => $offset + 1,
                'descricao' => $description,
                'quantidade' => 1,
                'meta' => ['scenario' => 'triplex', 'base' => true],
            ]));
        }

        $rows = [];
        for ($index = 1; $index <= self::ITEM_COUNT; $index++) {
            $stageOffset = intdiv($index - 1, 50);
            $position = (($index - 1) % 50) + 1;
            $stage = $stages[$stageOffset];
            $isInput = $index % 5 < 3;
            $source = $isInput
                ? $insumos[($index - 1) % $insumos->count()]
                : $composicoes[($index - 1) % $composicoes->count()];
            $quantity = round(15 + ($index % 11) * 1.25 + $stageOffset * 0.2, 6);
            $unitValue = round(48 + ($stageOffset + 1) * 13.75 + (($index * 17) % 90) * 3.15, 6);
            $withBdi = round($unitValue * 1.225, 6);
            $rows[] = [
                'tenant_id' => $tenant->id,
                'orcamento_id' => $budget->id,
                'orcamento_etapa_id' => $stage->id,
                'created_by_id' => $user->id,
                'item_type' => $isInput ? 'insumo' : 'composicao',
                'orcamento_composicao_id' => $isInput ? null : $source->id,
                'orcamento_insumo_id' => $isInput ? $source->id : null,
                'ordem' => $position,
                'codigo' => $isInput ? $source->codigo_insumo : $source->codigo,
                'banco' => $isInput ? $source->banco : $source->modelo,
                'descricao' => $this->itemDescription($stageOffset, $position),
                'unidade' => $this->unitFor($index),
                'quantidade' => $quantity,
                'valor_unitario_nao_desonerado' => $unitValue,
                'valor_unitario_desonerado' => round($unitValue * 0.965, 6),
                'valor_com_bdi_nao_desonerado' => $withBdi,
                'valor_com_bdi_desonerado' => round($withBdi * 0.965, 6),
                'valor_total_nao_desonerado' => round($quantity * $withBdi, 6),
                'valor_total_desonerado' => round($quantity * $withBdi * 0.965, 6),
                'aplicar_bdi' => true,
                'meta' => json_encode(['scenario' => 'triplex', 'line_number' => $index]),
                'created_at' => '2026-07-20 10:00:00',
                'updated_at' => '2026-07-25 17:30:00',
            ];
        }
        $this->insertChunks('orcamento_itens', $rows);
        $items = OrcamentoItem::query()->where('orcamento_id', $budget->id)->orderBy('id')->get();
        $this->updateBudgetTotals($budget, $stages, $items);

        return [$budget->fresh(), $stages, $items];
    }

    private function createRevisedBudget(
        Tenant $tenant,
        User $user,
        Empresa $client,
        Orcamento $baseBudget,
        Collection $baseStages,
        Collection $baseItems
    ): array {
        $budget = Orcamento::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $user->id,
            'closed_by_id' => $user->id,
            'cliente_empresa_id' => $client->id,
            'codigo' => 'TRP-ORC-REV-01-2027',
            'descricao' => 'Revisao 01 do orcamento executivo - Residencia Triplex Horizonte',
            'categoria' => 'Edificacao residencial de alto padrao',
            'prazo_entrega_at' => '2027-10-31 18:00:00',
            'permitir_insumos_preco_zerado' => false,
            'is_licitacao' => false,
            'arredondamento' => 'truncate_all_2',
            'encargos_sociais' => 'nao_desonerado',
            'bdi_tipo' => 'unit_price',
            'bdi_percentual' => 22.5,
            'base_references' => $baseBudget->base_references,
            'status' => 'closed',
            'closed_at' => '2026-12-20 16:00:00',
        ]);
        $stages = collect();
        $stageMap = [];

        foreach ($baseStages as $baseStage) {
            $stage = OrcamentoEtapa::create([
                'tenant_id' => $tenant->id,
                'orcamento_id' => $budget->id,
                'created_by_id' => $user->id,
                'ordem' => $baseStage->ordem,
                'descricao' => $baseStage->descricao,
                'quantidade' => 1,
                'meta' => [
                    'scenario' => 'triplex',
                    'copied_from_etapa_id' => $baseStage->id,
                    'origin_orcamento_etapa_id' => $baseStage->id,
                ],
            ]);
            $stages->push($stage);
            $stageMap[$baseStage->id] = $stage;
        }

        $rows = [];
        foreach ($baseItems as $offset => $baseItem) {
            $number = $offset + 1;
            $quantity = (float) $baseItem->quantidade;
            $unitValue = (float) $baseItem->valor_unitario_nao_desonerado;
            $bucket = $number % 10;

            if (in_array($bucket, [0, 1], true)) {
                $quantity *= 1.20;
            } elseif ($bucket === 2) {
                $quantity *= 0.90;
            }

            if ($number % 20 === 3) {
                $unitValue *= 1.03;
            }

            $quantity = round($quantity, 6);
            $unitValue = round($unitValue, 6);
            $withBdi = round($unitValue * 1.225, 6);
            $rows[] = [
                'tenant_id' => $tenant->id,
                'orcamento_id' => $budget->id,
                'orcamento_etapa_id' => $stageMap[$baseItem->orcamento_etapa_id]->id,
                'created_by_id' => $user->id,
                'item_type' => $baseItem->item_type,
                'orcamento_composicao_id' => $baseItem->orcamento_composicao_id,
                'orcamento_insumo_id' => $baseItem->orcamento_insumo_id,
                'ordem' => $baseItem->ordem,
                'codigo' => $baseItem->codigo,
                'banco' => $baseItem->banco,
                'descricao' => $baseItem->descricao,
                'unidade' => $baseItem->unidade,
                'quantidade' => $quantity,
                'valor_unitario_nao_desonerado' => $unitValue,
                'valor_unitario_desonerado' => round($unitValue * 0.965, 6),
                'valor_com_bdi_nao_desonerado' => $withBdi,
                'valor_com_bdi_desonerado' => round($withBdi * 0.965, 6),
                'valor_total_nao_desonerado' => round($quantity * $withBdi, 6),
                'valor_total_desonerado' => round($quantity * $withBdi * 0.965, 6),
                'aplicar_bdi' => true,
                'meta' => json_encode([
                    'scenario' => 'triplex',
                    'copied_from_item_id' => $baseItem->id,
                    'origin_orcamento_item_id' => $baseItem->id,
                ]),
                'created_at' => '2026-12-15 09:00:00',
                'updated_at' => '2026-12-20 16:00:00',
            ];
        }
        $this->insertChunks('orcamento_itens', $rows);
        $items = OrcamentoItem::query()->where('orcamento_id', $budget->id)->orderBy('id')->get();
        $this->updateBudgetTotals($budget, $stages, $items);

        return [$budget->fresh(), $stages, $items];
    }

    private function importBudgetBase(
        Tenant $tenant,
        Contract $contract,
        User $user,
        Orcamento $budget,
        Collection $stages,
        Collection $items
    ): Collection {
        $now = Carbon::parse('2026-07-26 09:00:00');
        $stageRows = [];
        $detailRows = [];

        foreach ($stages as $stage) {
            $total = $items->where('orcamento_etapa_id', $stage->id)
                ->sum(fn (OrcamentoItem $item): float => (float) $item->valor_total_nao_desonerado);
            $stageRows[] = [
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'created_by_id' => $user->id,
                'source_type' => 'orcamento',
                'source_orcamento_id' => $budget->id,
                'source_orcamento_etapa_id' => $stage->id,
                'source_orcamento_item_id' => null,
                'item' => (string) $stage->ordem,
                'nivel' => 1,
                'item_type' => 'etapa',
                'descricao' => $stage->descricao,
                'quantidade_prevista' => 1,
                'valor_unitario' => 0,
                'valor_com_bdi' => 0,
                'valor_total' => round($total, 6),
                'meta' => json_encode([
                    'orcamento_codigo' => $budget->codigo,
                    'budget_origin_etapa_id' => $stage->id,
                    'scenario' => 'triplex',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach ($items as $item) {
            $stage = $stages->firstWhere('id', $item->orcamento_etapa_id);
            $detailRows[] = [
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'created_by_id' => $user->id,
                'source_type' => 'orcamento',
                'source_orcamento_id' => $budget->id,
                'source_orcamento_etapa_id' => $item->orcamento_etapa_id,
                'source_orcamento_item_id' => $item->id,
                'item' => $stage->ordem.'.'.$item->ordem,
                'nivel' => 2,
                'item_type' => $item->item_type,
                'codigo' => $item->codigo,
                'banco' => $item->banco,
                'descricao' => $item->descricao,
                'unidade' => $item->unidade,
                'quantidade_prevista' => $item->quantidade,
                'valor_unitario' => $item->valor_unitario_nao_desonerado,
                'valor_com_bdi' => $item->valor_com_bdi_nao_desonerado,
                'valor_total' => $item->valor_total_nao_desonerado,
                'meta' => json_encode([
                    'orcamento_codigo' => $budget->codigo,
                    'orcamento_encargos_sociais' => $budget->encargos_sociais,
                    'budget_origin_etapa_id' => $item->orcamento_etapa_id,
                    'budget_origin_item_id' => $item->id,
                    'scenario' => 'triplex',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertChunks('medicao_itens', $stageRows);
        $this->insertChunks('medicao_itens', $detailRows);
        $medicaoItems = MedicaoItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->orderBy('id')
            ->get();
        $versionRows = $medicaoItems->map(fn (MedicaoItem $item): array => [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'medicao_item_id' => $item->id,
            'created_by_id' => $user->id,
            'version_number' => 1,
            'version_label' => 'Base',
            'change_type' => 'base',
            'quantidade_prevista' => $item->quantidade_prevista,
            'valor_unitario' => $item->valor_unitario,
            'valor_com_bdi' => $item->valor_com_bdi,
            'valor_total' => $item->valor_total,
            'starts_at' => '2026-08-01 00:00:00',
            'snapshot' => json_encode($this->itemSnapshot($item)),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
        $this->insertChunks('medicao_item_versions', $versionRows);

        return $medicaoItems;
    }

    private function applyBudgetRevision(
        Tenant $tenant,
        Contract $contract,
        User $user,
        Orcamento $revisedBudget,
        Collection $revisedStages,
        Collection $revisedItems,
        Collection $medicaoItems
    ): void {
        $additive = MedicaoItemAdditive::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'source_orcamento_id' => $revisedBudget->id,
            'number' => 1,
            'title' => 'Aditivo 1 - Revisao de quantitativos do Triplex',
            'reason' => 'Compatibilizacao executiva, reforcos estruturais e ajuste de quantitativos apos cinco meses de obra.',
            'source_type' => 'orcamento',
            'status' => 'applied',
            'effective_at' => '2027-01-01 00:00:00',
            'applied_at' => '2026-12-20 16:15:00',
            'meta' => ['scenario' => 'triplex', 'orcamento_codigo' => $revisedBudget->codigo],
        ]);
        $revisionItemMap = $revisedItems->mapWithKeys(fn (OrcamentoItem $item): array => [
            (int) ($item->meta['origin_orcamento_item_id'] ?? 0) => $item,
        ]);
        $revisionStageMap = $revisedStages->mapWithKeys(fn (OrcamentoEtapa $stage): array => [
            (int) ($stage->meta['origin_orcamento_etapa_id'] ?? 0) => $stage,
        ]);
        $baseVersionIds = MedicaoItemVersion::query()
            ->where('contract_id', $contract->id)
            ->where('version_number', 1)
            ->pluck('id', 'medicao_item_id');
        $snapshotRows = [];

        foreach ($medicaoItems as $item) {
            $old = $this->itemSnapshot($item);
            $revisionItem = $item->source_orcamento_item_id
                ? $revisionItemMap->get((int) $item->source_orcamento_item_id)
                : null;
            $revisionStage = ! $item->source_orcamento_item_id
                ? $revisionStageMap->get((int) $item->source_orcamento_etapa_id)
                : null;

            if ($revisionItem) {
                $new = [
                    ...$old,
                    'source_orcamento_id' => $revisedBudget->id,
                    'source_orcamento_etapa_id' => $revisionItem->orcamento_etapa_id,
                    'source_orcamento_item_id' => $revisionItem->id,
                    'quantidade_prevista' => (float) $revisionItem->quantidade,
                    'valor_unitario' => (float) $revisionItem->valor_unitario_nao_desonerado,
                    'valor_com_bdi' => (float) $revisionItem->valor_com_bdi_nao_desonerado,
                    'valor_total' => (float) $revisionItem->valor_total_nao_desonerado,
                    'meta' => array_merge($item->meta ?? [], [
                        'latest_additive_id' => $additive->id,
                        'latest_additive_number' => 1,
                        'orcamento_codigo' => $revisedBudget->codigo,
                    ]),
                ];
            } else {
                $stageItems = $revisedItems->where('orcamento_etapa_id', $revisionStage->id);
                $new = [
                    ...$old,
                    'source_orcamento_id' => $revisedBudget->id,
                    'source_orcamento_etapa_id' => $revisionStage->id,
                    'valor_total' => (float) $stageItems->sum('valor_total_nao_desonerado'),
                    'meta' => array_merge($item->meta ?? [], [
                        'latest_additive_id' => $additive->id,
                        'latest_additive_number' => 1,
                        'orcamento_codigo' => $revisedBudget->codigo,
                    ]),
                ];
            }

            $changed = $this->itemChanged($old, $new);
            $versionId = (int) $baseVersionIds[$item->id];

            if ($changed) {
                $version = MedicaoItemVersion::create([
                    'tenant_id' => $tenant->id,
                    'contract_id' => $contract->id,
                    'medicao_item_id' => $item->id,
                    'additive_id' => $additive->id,
                    'created_by_id' => $user->id,
                    'version_number' => 2,
                    'version_label' => 'Aditivo 1',
                    'change_type' => 'changed',
                    'quantidade_prevista' => $new['quantidade_prevista'],
                    'valor_unitario' => $new['valor_unitario'],
                    'valor_com_bdi' => $new['valor_com_bdi'],
                    'valor_total' => $new['valor_total'],
                    'starts_at' => $additive->effective_at,
                    'snapshot' => $new,
                ]);
                $versionId = $version->id;
                $item->forceFill([
                    'source_orcamento_id' => $new['source_orcamento_id'],
                    'source_orcamento_etapa_id' => $new['source_orcamento_etapa_id'],
                    'source_orcamento_item_id' => $new['source_orcamento_item_id'] ?? null,
                    'quantidade_prevista' => $new['quantidade_prevista'],
                    'valor_unitario' => $new['valor_unitario'],
                    'valor_com_bdi' => $new['valor_com_bdi'],
                    'valor_total' => $new['valor_total'],
                    'meta' => $new['meta'],
                ])->save();
            }

            $snapshotRows[] = [
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'additive_id' => $additive->id,
                'medicao_item_id' => $item->id,
                'medicao_item_version_id' => $versionId,
                'status' => $changed ? 'alterado' : 'sem_alteracao',
                'item' => $item->item,
                'codigo' => $item->codigo,
                'banco' => $item->banco,
                'descricao' => $item->descricao,
                'unidade' => $item->unidade,
                'quantidade_anterior' => $old['quantidade_prevista'],
                'quantidade_nova' => $new['quantidade_prevista'],
                'valor_unitario_anterior' => $old['valor_unitario'],
                'valor_unitario_novo' => $new['valor_unitario'],
                'valor_com_bdi_anterior' => $old['valor_com_bdi'],
                'valor_com_bdi_novo' => $new['valor_com_bdi'],
                'valor_total_anterior' => $old['valor_total'],
                'valor_total_novo' => $new['valor_total'],
                'meta' => json_encode(['old' => $old, 'new' => $new, 'scenario' => 'triplex']),
                'created_at' => '2026-12-20 16:15:00',
                'updated_at' => '2026-12-20 16:15:00',
            ];
        }
        $this->insertChunks('medicao_item_additive_items', $snapshotRows);
    }

    private function createAdjustmentIndex(
        Tenant $tenant,
        Contract $contract,
        User $user,
        Collection $items
    ): MedicaoIndiceReajuste {
        $index = MedicaoIndiceReajuste::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'nome' => 'Indice Nacional de Custo da Construcao',
            'codigo' => 'INCC-TRP',
            'indice_base' => 100,
            'data_base' => '2026-08-01',
            'indice_atual' => 109.2,
            'data_atual' => '2027-10-01',
            'observacao' => 'Reajuste aplicado somente apos o primeiro aniversario do contrato.',
        ]);
        $values = [
            '2026-08-01' => 100,
            '2026-12-01' => 100,
            '2027-07-01' => 100,
            '2027-08-01' => 108,
            '2027-09-01' => 108.6,
            '2027-10-01' => 109.2,
        ];
        foreach ($values as $competencia => $value) {
            MedicaoIndiceReajusteCompetencia::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'medicao_indice_reajuste_id' => $index->id,
                'created_by_id' => $user->id,
                'competencia' => $competencia,
                'valor_indice' => $value,
                'data_publicacao' => Carbon::parse($competencia)->addDays(12),
                'observacao' => $value > 100 ? 'Competencia reajustada apos aniversario.' : 'Competencia sem reajuste contratual.',
            ]);
        }
        $rows = $items->map(fn (MedicaoItem $item): array => [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'medicao_item_id' => $item->id,
            'medicao_indice_reajuste_id' => $index->id,
            'created_by_id' => $user->id,
            'item_codigo' => $item->codigo,
            'indice_codigo' => $index->codigo,
            'source_type' => 'manual',
            'created_at' => '2026-07-28 10:00:00',
            'updated_at' => '2026-07-28 10:00:00',
        ])->all();
        $this->insertChunks('medicao_item_reajuste_indices', $rows);

        return $index;
    }

    private function createServiceOrder(
        Tenant $tenant,
        Contract $contract,
        Obra $obra,
        array $companies,
        User $user,
        Collection $items
    ): OrdemServico {
        $total = round($items->sum(fn (MedicaoItem $item): float => (float) $item->valor_total), 2);
        $order = OrdemServico::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'gerenciadora_empresa_id' => $companies['gerenciadora']->id,
            'construtora_empresa_id' => $companies['construtora']->id,
            'created_by_id' => $user->id,
            'codigo' => '2026-08-001-OS-001',
            'sequencial' => 1,
            'titulo' => 'Execucao integral da Residencia Triplex Horizonte',
            'descricao' => 'Ordem de servico para execucao dos 1.000 itens do orcamento e de suas revisoes aprovadas.',
            'prazo_inicio' => '2026-08-01',
            'prazo_finalizacao' => '2027-10-31',
            'prazo_execucao' => '2027-10-31',
            'custo_previsto' => $total,
            'custo_observacao' => 'Escopo atualizado pelo Aditivo 1 de itens em janeiro de 2027.',
            'status' => 'concluida',
            'submitted_for_review_at' => '2026-07-27 09:00:00',
            'submitted_for_review_by_id' => $user->id,
            'analyzed_at' => '2026-07-28 14:00:00',
            'analyzed_by_id' => $user->id,
            'analysis_observation' => 'Escopo, quantitativos e prazo conferidos.',
            'approval_decided_at' => '2026-07-29 11:00:00',
            'approval_decided_by_id' => $user->id,
            'approval_observation' => 'OS aprovada para execucao integral.',
            'execution_started_at' => '2026-08-01 07:30:00',
            'execution_started_by_id' => $user->id,
            'completed_at' => '2027-10-31 17:00:00',
            'completed_by_id' => $user->id,
            'completion_summary' => 'Todos os itens foram medidos e o saldo contratual foi esgotado nos 15 boletins.',
        ]);
        $itemRows = $items->map(fn (MedicaoItem $item): array => [
            'ordem_servico_id' => $order->id,
            'medicao_item_id' => $item->id,
            'quantidade_solicitada' => $item->quantidade_prevista,
            'valor_previsto' => round((float) $item->valor_total, 2),
            'created_at' => '2026-07-26 14:00:00',
            'updated_at' => '2026-12-20 16:30:00',
        ])->all();
        $this->insertChunks('ordem_servico_itens', $itemRows);
        OrdemServicoResponsavel::create([
            'ordem_servico_id' => $order->id,
            'user_id' => $user->id,
            'papel' => 'responsavel',
        ]);
        foreach ([
            ['tipo' => 'analise', 'decisao' => 'aprovada', 'observacao' => 'Analise tecnica aprovada sem ressalvas.', 'created_at' => '2026-07-28 14:00:00'],
            ['tipo' => 'aprovacao', 'decisao' => 'aprovada', 'observacao' => 'Liberada para execucao.', 'created_at' => '2026-07-29 11:00:00'],
        ] as $analysis) {
            $record = OrdemServicoAnalise::create([
                'ordem_servico_id' => $order->id,
                'user_id' => $user->id,
                'tipo' => $analysis['tipo'],
                'decisao' => $analysis['decisao'],
                'observacao' => $analysis['observacao'],
            ]);
            $record->forceFill(['created_at' => $analysis['created_at'], 'updated_at' => $analysis['created_at']])->save();
        }
        OrdemServicoComentario::create([
            'tenant_id' => $tenant->id,
            'ordem_servico_id' => $order->id,
            'user_id' => $user->id,
            'tipo' => 'comentario',
            'body' => 'A revisao de quantitativos do orcamento foi incorporada ao escopo da OS.',
            'status' => 'resolvida',
            'resolved_at' => '2027-01-05 16:00:00',
            'resolved_by_id' => $user->id,
        ]);

        return $order;
    }

    private function createMeasurementCycle(
        Tenant $tenant,
        Contract $contract,
        Obra $obra,
        array $companies,
        User $user,
        OrdemServico $order,
        Collection $items
    ): void {
        $memoryPath = "tenant-{$tenant->id}/medicao/triplex/memoria-calculo-triplex.zip";
        Storage::disk('public')->put($memoryPath, base64_decode('UEsFBgAAAAAAAAAAAAAAAAAAAAAAAA==', true));
        $orderItems = DB::table('ordem_servico_itens')
            ->where('ordem_servico_id', $order->id)
            ->pluck('id', 'medicao_item_id');
        $items->load(['versions', 'reajusteIndice.indice.competencias']);
        $distributions = $items->mapWithKeys(function (MedicaoItem $item): array {
            $base = (float) ($item->versions->firstWhere('version_number', 1)?->quantidade_prevista ?? $item->quantidade_prevista);

            return [$item->id => $this->quantityDistribution($base, (float) $item->quantidade_prevista)];
        });

        for ($month = 0; $month < self::MONTH_COUNT; $month++) {
            $period = CarbonImmutable::parse('2026-08-01')->addMonths($month);
            $sequence = $month + 1;
            $code = 'BM-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
            $boletim = BoletimMedicao::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'created_by_id' => $user->id,
                'codigo' => $code,
                'sequencial' => $sequence,
                'periodo' => $period,
                'tipo' => 'normal',
                'status' => 'finalizado',
            ]);
            $boletim->forceFill([
                'created_at' => $period->addDays(1)->setTime(8, 0),
                'updated_at' => $period->addDays(25)->setTime(17, 0),
            ])->save();
            $folha = FolhaRosto::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'ordem_servico_id' => $order->id,
                'boletim_medicao_id' => $boletim->id,
                'construtora_empresa_id' => $companies['construtora']->id,
                'created_by_id' => $user->id,
                'codigo' => $order->codigo.'-FR-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                'sequencial' => $sequence,
                'comentario' => $sequence <= 5
                    ? 'Medicao do orcamento-base da Residencia Triplex.'
                    : ($sequence <= 12
                        ? 'Medicao com quantitativos atualizados pelo Aditivo 1.'
                        : 'Medicao apos aniversario contratual com reajuste INCC.'),
                'memoria_calculo_path' => $memoryPath,
                'memoria_calculo_nome_original' => "memoria-triplex-{$code}.zip",
                'memoria_calculo_mime_type' => 'application/zip',
                'memoria_calculo_size' => 22,
                'status' => 'analisada',
                'submitted_for_analysis_at' => $period->addDays(12)->setTime(9, 0),
            ]);
            $folha->forceFill([
                'created_at' => $period->addDays(10)->setTime(8, 0),
                'updated_at' => $period->addDays(22)->setTime(16, 0),
            ])->save();
            $analysisByStage = collect(['fiscal', 'qualidade', 'medicao'])->mapWithKeys(function (string $stage, int $stageOffset) use ($folha, $user, $period): array {
                $analysis = FolhaRostoAnalise::create([
                    'folha_rosto_id' => $folha->id,
                    'user_id' => $user->id,
                    'setor' => $stage,
                    'comentario_geral' => match ($stage) {
                        'fiscal' => 'Quantidades verificadas no local de execucao.',
                        'qualidade' => 'Servicos e evidencias aceitos.',
                        default => 'Quantidades consolidadas para o boletim.',
                    },
                ]);
                $date = $period->addDays(15 + $stageOffset * 2)->setTime(14, 0);
                $analysis->forceFill(['created_at' => $date, 'updated_at' => $date])->save();

                return [$stage => $analysis];
            });
            $folhaRows = [];
            foreach ($items as $item) {
                $quantity = (float) $distributions[$item->id][$month];
                $values = MedicaoItemValueResolver::resolve($item, $period);
                $adjusted = MedicaoReajusteCalculator::adjustedValue(
                    $values['preco_unitario_p0'],
                    $item,
                    $period
                );
                $folhaRows[] = [
                    'folha_rosto_id' => $folha->id,
                    'ordem_servico_item_id' => $orderItems[$item->id],
                    'medicao_item_id' => $item->id,
                    'quantidade_pleiteada' => $quantity,
                    'valor_pleiteado' => round($quantity * $adjusted, 2),
                    'precisa_analise_topografica' => false,
                    'precisa_analise_qualidade' => true,
                    'created_at' => $period->addDays(10)->setTime(8, 0),
                    'updated_at' => $period->addDays(22)->setTime(16, 0),
                ];
            }
            $this->insertChunks('folha_rosto_itens', $folhaRows);
            $folhaItems = DB::table('folha_rosto_itens')
                ->where('folha_rosto_id', $folha->id)
                ->get(['id', 'quantidade_pleiteada']);
            $analysisRows = [];
            foreach ($folhaItems as $folhaItem) {
                foreach ($analysisByStage as $stage => $analysis) {
                    $analysisRows[] = [
                        'folha_rosto_item_id' => $folhaItem->id,
                        'folha_rosto_analise_id' => $analysis->id,
                        'setor' => $stage,
                        'quantidade_aprovada' => $folhaItem->quantidade_pleiteada,
                        'comentario' => 'Quantidade aprovada integralmente.',
                        'created_at' => $analysis->created_at,
                        'updated_at' => $analysis->updated_at,
                    ];
                }
            }
            $this->insertChunks('folha_rosto_item_analises', $analysisRows);
            $this->createFlowHistory($folha, $user, $period);
        }
    }

    private function createFlowHistory(FolhaRosto $folha, User $user, CarbonImmutable $period): void
    {
        $responsibles = json_encode([[
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]]);
        $steps = [
            ['rascunho', 'analise_fiscal', 'enviar_analise', 12],
            ['analise_fiscal', 'analise_qualidade', 'qualidade', 15],
            ['analise_qualidade', 'analise_medicao', 'medicao', 17],
            ['analise_medicao', 'analisada', 'finalizar', 19],
        ];
        foreach ($steps as [$from, $to, $action, $day]) {
            $date = $period->addDays($day)->setTime(14, 0);
            DB::table('folha_rosto_fluxo_historicos')->insert([
                'folha_rosto_id' => $folha->id,
                'user_id' => $user->id,
                'status_origem' => $from,
                'status_destino' => $to,
                'acao' => $action,
                'motivo' => null,
                'responsaveis_snapshot' => $responsibles,
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        }
    }

    private function quantityDistribution(float $baseQuantity, float $finalQuantity): array
    {
        $initialWeights = [0.03, 0.05, 0.06, 0.08, 0.10];
        $remainingWeights = [0.08, 0.09, 0.10, 0.11, 0.12, 0.12, 0.12, 0.10, 0.09, 0.07];
        $distribution = collect($initialWeights)
            ->map(fn (float $weight): float => round($baseQuantity * $weight, 6))
            ->all();
        $remaining = $finalQuantity - array_sum($distribution);

        foreach ($remainingWeights as $offset => $weight) {
            $distribution[] = $offset === array_key_last($remainingWeights)
                ? round($finalQuantity - array_sum($distribution), 6)
                : round($remaining * $weight, 6);
        }

        return $distribution;
    }

    private function updateBudgetTotals(Orcamento $budget, Collection $stages, Collection $items): void
    {
        foreach ($stages as $stage) {
            $stageItems = $items->where('orcamento_etapa_id', $stage->id);
            $stage->forceFill([
                'valor_nao_desonerado' => round((float) $stageItems->sum('valor_total_nao_desonerado'), 6),
                'valor_desonerado' => round((float) $stageItems->sum('valor_total_desonerado'), 6),
            ])->save();
        }
        $budget->forceFill([
            'valor_nao_desonerado' => round((float) $items->sum('valor_total_nao_desonerado'), 6),
            'valor_desonerado' => round((float) $items->sum('valor_total_desonerado'), 6),
        ])->save();
    }

    private function audit(
        Tenant $tenant,
        ?Contract $contract = null,
        ?MedicaoIndiceReajuste $index = null
    ): array {
        $contract ??= Contract::query()
            ->where('tenant_id', $tenant->id)
            ->where('code', self::CONTRACT_CODE)
            ->firstOrFail();
        $index ??= MedicaoIndiceReajuste::query()
            ->where('contract_id', $contract->id)
            ->where('codigo', 'INCC-TRP')
            ->firstOrFail();
        $budgets = Orcamento::query()->where('tenant_id', $tenant->id)->get();
        $items = MedicaoItem::query()
            ->where('contract_id', $contract->id)
            ->where('nivel', 2)
            ->with('reajusteIndice.indice.competencias')
            ->get();
        $approved = DB::table('folha_rosto_item_analises as analyses')
            ->join('folha_rosto_itens as lines', 'lines.id', '=', 'analyses.folha_rosto_item_id')
            ->join('folhas_rosto as covers', 'covers.id', '=', 'lines.folha_rosto_id')
            ->where('covers.contract_id', $contract->id)
            ->where('covers.status', 'analisada')
            ->where('analyses.setor', 'medicao')
            ->groupBy('lines.medicao_item_id')
            ->selectRaw('lines.medicao_item_id, SUM(analyses.quantidade_aprovada) as quantity')
            ->pluck('quantity', 'medicao_item_id');
        $balances = $items->map(fn (MedicaoItem $item): float => round(
            (float) $item->quantidade_prevista - (float) ($approved[$item->id] ?? 0),
            6
        ));
        $firstItem = $items->first();
        $checks = [
            'membership' => TenantUser::query()->where('tenant_id', $tenant->id)->where('status', 'active')->exists(),
            'controlled_mode' => $contract->measurement_mode === 'controlled',
            'budgets' => $budgets->count() === 2 && $budgets->every(fn (Orcamento $budget): bool => $budget->status === 'closed'),
            'budget_lines' => $budgets->every(fn (Orcamento $budget): bool => $budget->itens()->count() === self::ITEM_COUNT),
            'contract_items' => $items->count() === self::ITEM_COUNT,
            'service_order_items' => DB::table('ordem_servico_itens')->whereIn('ordem_servico_id', OrdemServico::query()->where('contract_id', $contract->id)->pluck('id'))->count() === self::ITEM_COUNT,
            'bulletins' => BoletimMedicao::query()->where('contract_id', $contract->id)->count() === self::MONTH_COUNT,
            'covers' => FolhaRosto::query()->where('contract_id', $contract->id)->count() === self::MONTH_COUNT,
            'measurement_lines' => DB::table('folha_rosto_itens')->whereIn('folha_rosto_id', FolhaRosto::query()->where('contract_id', $contract->id)->pluck('id'))->count() === self::ITEM_COUNT * self::MONTH_COUNT,
            'balances_exhausted' => $balances->every(fn (float $balance): bool => abs($balance) <= 0.000001),
            'no_negative_balance' => $balances->every(fn (float $balance): bool => $balance >= -0.000001),
            'no_early_adjustment' => abs(MedicaoReajusteCalculator::percentage($firstItem, '2027-07-01')) < 0.000001,
            'annual_adjustment' => abs(MedicaoReajusteCalculator::percentage($firstItem, '2027-08-01') - 8.0) < 0.000001,
        ];
        $failed = collect($checks)->filter(fn (bool $passed): bool => ! $passed)->keys();

        if ($failed->isNotEmpty()) {
            throw new RuntimeException('Auditoria Triplex falhou: '.$failed->implode(', '));
        }

        return [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'budget_count' => $budgets->count(),
            'budget_lines' => $budgets->sum(fn (Orcamento $budget): int => $budget->itens()->count()),
            'contract_items' => $items->count(),
            'additives' => MedicaoItemAdditive::query()->where('contract_id', $contract->id)->count(),
            'service_orders' => OrdemServico::query()->where('contract_id', $contract->id)->count(),
            'bulletins' => BoletimMedicao::query()->where('contract_id', $contract->id)->count(),
            'covers' => FolhaRosto::query()->where('contract_id', $contract->id)->count(),
            'measurement_lines' => self::ITEM_COUNT * self::MONTH_COUNT,
            'contract_value' => (float) $contract->fresh()->total_value,
            'adjustment_percentage' => MedicaoReajusteCalculator::percentage($firstItem, '2027-10-01'),
            'checks' => count($checks),
        ];
    }

    private function printSummary(array $summary): void
    {
        $this->command?->info('Massa Triplex validada e preservada no banco local.');
        $this->command?->table(
            ['Tenant', 'Contrato', 'Orcamentos', 'Linhas orcamento', 'Itens contrato', 'OS', 'BMs', 'FRs', 'Linhas medidas', 'Reajuste final'],
            [[
                $summary['tenant_id'],
                $summary['contract_id'],
                $summary['budget_count'],
                $summary['budget_lines'],
                $summary['contract_items'],
                $summary['service_orders'],
                $summary['bulletins'],
                $summary['covers'],
                $summary['measurement_lines'],
                number_format($summary['adjustment_percentage'], 2, ',', '.').'%',
            ]]
        );
        $this->command?->info($summary['checks'].' verificacoes financeiras e estruturais aprovadas.');
    }

    private function insertChunks(string $table, array $rows, int $size = 500): void
    {
        foreach (array_chunk($rows, $size) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private function itemSnapshot(MedicaoItem $item): array
    {
        return [
            'source_orcamento_id' => $item->source_orcamento_id,
            'source_orcamento_etapa_id' => $item->source_orcamento_etapa_id,
            'source_orcamento_item_id' => $item->source_orcamento_item_id,
            'item' => $item->item,
            'nivel' => $item->nivel,
            'item_type' => $item->item_type,
            'codigo' => $item->codigo,
            'banco' => $item->banco,
            'descricao' => $item->descricao,
            'unidade' => $item->unidade,
            'quantidade_prevista' => (float) $item->quantidade_prevista,
            'valor_unitario' => (float) $item->valor_unitario,
            'valor_com_bdi' => (float) $item->valor_com_bdi,
            'valor_total' => (float) $item->valor_total,
            'meta' => $item->meta ?? [],
        ];
    }

    private function itemChanged(array $old, array $new): bool
    {
        foreach (['quantidade_prevista', 'valor_unitario', 'valor_com_bdi', 'valor_total'] as $field) {
            if (abs((float) $old[$field] - (float) $new[$field]) > 0.000001) {
                return true;
            }
        }

        return false;
    }

    private function unitFor(int $index): string
    {
        return ['UN', 'M', 'M2', 'M3', 'KG', 'H'][$index % 6];
    }

    private function itemDescription(int $stageOffset, int $position): string
    {
        $actions = [
            'fornecimento e instalacao',
            'execucao completa',
            'preparo, aplicacao e acabamento',
            'montagem, testes e liberacao',
            'servico complementar por ambiente',
        ];

        return self::STAGES[$stageOffset].' - '.$actions[$position % count($actions)].' - detalhe '.str_pad((string) $position, 2, '0', STR_PAD_LEFT);
    }
}

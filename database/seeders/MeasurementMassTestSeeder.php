<?php

namespace Database\Seeders;

use App\Models\BoletimMedicao;
use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\Empresa;
use App\Models\FolhaRosto;
use App\Models\FolhaRostoAnalise;
use App\Models\FolhaRostoFluxoHistorico;
use App\Models\FolhaRostoItem;
use App\Models\FolhaRostoItemAnalise;
use App\Models\MedicaoIndiceReajuste;
use App\Models\MedicaoIndiceReajusteCompetencia;
use App\Models\MedicaoItem;
use App\Models\MedicaoItemAdditive;
use App\Models\MedicaoItemAdditiveItem;
use App\Models\MedicaoItemReajusteIndice;
use App\Models\MedicaoItemVersion;
use App\Models\Obra;
use App\Models\OrdemServico;
use App\Models\OrdemServicoItem;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MeasurementMassTestSeeder extends Seeder
{
    private const CONTRACTS = [
        'simple' => [
            'code' => 'TST-MED-SIM-0726',
            'name' => 'Teste em massa - Medicao simples',
            'obra_code' => '901',
        ],
        'controlled' => [
            'code' => 'TST-MED-CTL-0726',
            'name' => 'Teste em massa - Medicao controlada',
            'obra_code' => '902',
        ],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Esta massa de teste so pode ser executada no ambiente local.');
        }

        $tenant = Tenant::query()->where('slug', 'tour-local')->first();
        $user = User::query()->where('email', 'admin@obras.test')->first();

        if (! $tenant || ! $user) {
            throw new RuntimeException('Tenant tour-local ou usuario admin@obras.test nao encontrado.');
        }

        $existingCodes = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('code', collect(self::CONTRACTS)->pluck('code'))
            ->pluck('code');

        if ($existingCodes->isNotEmpty()) {
            $this->command?->warn(
                'Massa nao alterada. Contrato(s) de teste ja existente(s): '.$existingCodes->implode(', ')
            );

            return;
        }

        $memoryPath = "tenant-{$tenant->id}/medicao/massa-teste/memoria-calculo-teste.zip";
        Storage::disk('public')->put(
            $memoryPath,
            base64_decode('UEsFBgAAAAAAAAAAAAAAAAAAAAAAAA==', true)
        );

        $summary = DB::transaction(function () use ($tenant, $user, $memoryPath): array {
            $summary = [];

            foreach (self::CONTRACTS as $mode => $definition) {
                $summary[$mode] = $this->createScenario(
                    $tenant,
                    $user,
                    $mode,
                    $definition,
                    $memoryPath
                );
            }

            return $summary;
        });

        foreach ($summary as $mode => $data) {
            $this->command?->info(sprintf(
                '%s: contrato #%d, %d itens, %d OS, %d BMs e %d FRs.',
                $mode === 'simple' ? 'Medicao simples' : 'Medicao controlada',
                $data['contract_id'],
                $data['items'],
                $data['orders'],
                $data['boletins'],
                $data['folhas']
            ));
        }
    }

    private function createScenario(
        Tenant $tenant,
        User $user,
        string $mode,
        array $definition,
        string $memoryPath
    ): array {
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => $definition['code'],
            'name' => $definition['name'],
            'description' => 'Cenario persistente para validacao dos fluxos de medicao, reajuste e aditivo de itens.',
            'client_company_name' => 'Cliente Infraestrutura Teste S.A.',
            'contractor_company_name' => 'Construtora Massa Teste Ltda.',
            'total_value' => 12500000,
            'currency' => 'BRL',
            'city' => 'Sao Paulo',
            'state' => 'SP',
            'starts_at' => '2026-01-05',
            'ends_at' => '2027-12-31',
            'status' => 'active',
            'measurement_mode' => $mode,
        ]);

        ContractParticipant::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'manager',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $companies = $this->createCompanies($tenant, $contract, $mode);
        $obra = Obra::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'codigo' => $definition['obra_code'],
            'nome' => $mode === 'simple'
                ? 'Corredor demonstrativo - simples'
                : 'Corredor demonstrativo - controlada',
            'tipo' => 'pai',
        ]);

        $contract->forceFill([
            'obra_id' => $obra->id,
            'cliente_empresa_id' => $companies['cliente']->id,
            'construtora_empresa_id' => $companies['construtora']->id,
            'fiscalizadora_empresa_id' => $companies['gerenciadora']->id,
        ])->save();

        $baseDefinitions = $this->baseItemDefinitions();
        $items = collect($baseDefinitions)
            ->map(fn (array $item): MedicaoItem => $this->createBaseItem($tenant, $contract, $user, $item));
        $basePrices = $items->mapWithKeys(
            fn (MedicaoItem $item): array => [$item->id => (float) $item->valor_com_bdi]
        );

        $additive = $this->applyItemAdditive($tenant, $contract, $user, $items);
        $items = MedicaoItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->where('nivel', 2)
            ->orderBy('item')
            ->get();

        $this->createAdjustmentIndexes($tenant, $contract, $user, $items);
        $boletins = $this->createBoletins($tenant, $contract, $user);
        $orders = $mode === 'controlled'
            ? $this->createOrders($tenant, $contract, $obra, $companies, $user, $items)
            : collect();

        $folhas = $this->createFolhas(
            $tenant,
            $contract,
            $obra,
            $companies['construtora'],
            $user,
            $items,
            $basePrices,
            $boletins,
            $orders,
            $memoryPath,
            $mode,
            $additive
        );

        return [
            'contract_id' => $contract->id,
            'items' => $items->count(),
            'orders' => $orders->count(),
            'boletins' => $boletins->count(),
            'folhas' => $folhas->count(),
        ];
    }

    private function createCompanies(Tenant $tenant, Contract $contract, string $mode): array
    {
        $suffix = $mode === 'simple' ? '11' : '22';

        return [
            'cliente' => Empresa::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'tipo_empresa_id' => TipoEmpresa::query()->where('nome', 'cliente')->valueOrFail('id'),
                'nome' => 'Cliente Infraestrutura Teste S.A.',
                'cnpj' => "10.000.000/0001-{$suffix}",
                'sigla' => 'CIT',
            ]),
            'construtora' => Empresa::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'tipo_empresa_id' => TipoEmpresa::query()->where('nome', 'construtora')->valueOrFail('id'),
                'nome' => 'Construtora Massa Teste Ltda.',
                'cnpj' => "20.000.000/0001-{$suffix}",
                'sigla' => 'CMT',
            ]),
            'gerenciadora' => Empresa::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'tipo_empresa_id' => TipoEmpresa::query()->where('nome', 'gerenciadora')->valueOrFail('id'),
                'nome' => 'Gerenciadora Controle Teste Ltda.',
                'cnpj' => "30.000.000/0001-{$suffix}",
                'sigla' => 'GCT',
            ]),
        ];
    }

    private function createBaseItem(
        Tenant $tenant,
        Contract $contract,
        User $user,
        array $definition
    ): MedicaoItem {
        $item = MedicaoItem::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'source_type' => 'manual',
            'item' => $definition['item'],
            'nivel' => 2,
            'item_type' => 'manual',
            'codigo' => $definition['codigo'],
            'banco' => 'BASE TESTE',
            'descricao' => $definition['descricao'],
            'unidade' => $definition['unidade'],
            'quantidade_prevista' => $definition['quantidade'],
            'valor_unitario' => $definition['valor'],
            'valor_com_bdi' => $definition['valor'],
            'valor_total' => $definition['quantidade'] * $definition['valor'],
            'meta' => ['mass_test' => true, 'scenario' => $contract->measurement_mode],
        ]);

        MedicaoItemVersion::create([
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
            'starts_at' => '2026-01-05 08:00:00',
            'snapshot' => $item->only([
                'item',
                'codigo',
                'banco',
                'descricao',
                'unidade',
                'quantidade_prevista',
                'valor_unitario',
                'valor_com_bdi',
                'valor_total',
            ]),
        ]);

        return $item;
    }

    private function applyItemAdditive(
        Tenant $tenant,
        Contract $contract,
        User $user,
        $items
    ): MedicaoItemAdditive {
        $additive = MedicaoItemAdditive::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'number' => 1,
            'title' => 'Aditivo 1 - Readequacao de quantitativos',
            'reason' => 'Ajuste de volumes executivos e inclusao de servicos identificados durante a obra.',
            'source_type' => 'manual',
            'status' => 'applied',
            'effective_at' => '2026-08-01 08:00:00',
            'applied_at' => '2026-07-25 14:30:00',
            'meta' => ['mass_test' => true],
        ]);

        $changes = [
            '2.1' => ['quantidade' => 14500, 'valor' => 24],
            '3.2' => ['quantidade' => 1500, 'valor' => 825],
            '5.3' => ['quantidade' => 5200, 'valor' => 645],
        ];

        foreach ($changes as $itemCode => $change) {
            $item = $items->firstWhere('item', $itemCode);
            $old = $item->only([
                'quantidade_prevista',
                'valor_unitario',
                'valor_com_bdi',
                'valor_total',
            ]);
            $newTotal = $change['quantidade'] * $change['valor'];

            $item->forceFill([
                'quantidade_prevista' => $change['quantidade'],
                'valor_unitario' => $change['valor'],
                'valor_com_bdi' => $change['valor'],
                'valor_total' => $newTotal,
                'meta' => array_merge($item->meta ?? [], [
                    'latest_additive_id' => $additive->id,
                    'latest_additive_number' => $additive->number,
                ]),
            ])->save();

            $version = MedicaoItemVersion::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'medicao_item_id' => $item->id,
                'additive_id' => $additive->id,
                'created_by_id' => $user->id,
                'version_number' => 2,
                'version_label' => 'Aditivo 1',
                'change_type' => 'changed',
                'quantidade_prevista' => $change['quantidade'],
                'valor_unitario' => $change['valor'],
                'valor_com_bdi' => $change['valor'],
                'valor_total' => $newTotal,
                'starts_at' => $additive->effective_at,
                'snapshot' => array_merge($item->only([
                    'item',
                    'codigo',
                    'banco',
                    'descricao',
                    'unidade',
                ]), [
                    'quantidade_prevista' => $change['quantidade'],
                    'valor_unitario' => $change['valor'],
                    'valor_com_bdi' => $change['valor'],
                    'valor_total' => $newTotal,
                ]),
            ]);

            MedicaoItemAdditiveItem::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'additive_id' => $additive->id,
                'medicao_item_id' => $item->id,
                'medicao_item_version_id' => $version->id,
                'status' => 'alterado',
                'item' => $item->item,
                'codigo' => $item->codigo,
                'banco' => $item->banco,
                'descricao' => $item->descricao,
                'unidade' => $item->unidade,
                'quantidade_anterior' => $old['quantidade_prevista'],
                'quantidade_nova' => $change['quantidade'],
                'valor_unitario_anterior' => $old['valor_unitario'],
                'valor_unitario_novo' => $change['valor'],
                'valor_com_bdi_anterior' => $old['valor_com_bdi'],
                'valor_com_bdi_novo' => $change['valor'],
                'valor_total_anterior' => $old['valor_total'],
                'valor_total_novo' => $newTotal,
                'meta' => ['mass_test' => true],
            ]);
        }

        foreach ([
            [
                'item' => '4.4',
                'codigo' => 'DRE-DN800',
                'descricao' => 'Drenagem tubular DN 800 mm',
                'unidade' => 'M',
                'quantidade' => 700,
                'valor' => 460,
            ],
            [
                'item' => '5.5',
                'codigo' => 'DEF-MET',
                'descricao' => 'Defensa metalica semimaleavel',
                'unidade' => 'M',
                'quantidade' => 1200,
                'valor' => 520,
            ],
        ] as $definition) {
            $item = MedicaoItem::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'created_by_id' => $user->id,
                'source_type' => 'aditivo',
                'item' => $definition['item'],
                'nivel' => 2,
                'item_type' => 'manual',
                'codigo' => $definition['codigo'],
                'banco' => 'ADITIVO TESTE',
                'descricao' => $definition['descricao'],
                'unidade' => $definition['unidade'],
                'quantidade_prevista' => $definition['quantidade'],
                'valor_unitario' => $definition['valor'],
                'valor_com_bdi' => $definition['valor'],
                'valor_total' => $definition['quantidade'] * $definition['valor'],
                'meta' => [
                    'mass_test' => true,
                    'additive_id' => $additive->id,
                    'additive_number' => $additive->number,
                ],
            ]);

            $version = MedicaoItemVersion::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'medicao_item_id' => $item->id,
                'additive_id' => $additive->id,
                'created_by_id' => $user->id,
                'version_number' => 1,
                'version_label' => 'Aditivo 1',
                'change_type' => 'new',
                'quantidade_prevista' => $item->quantidade_prevista,
                'valor_unitario' => $item->valor_unitario,
                'valor_com_bdi' => $item->valor_com_bdi,
                'valor_total' => $item->valor_total,
                'starts_at' => $additive->effective_at,
                'snapshot' => $item->only([
                    'item',
                    'codigo',
                    'banco',
                    'descricao',
                    'unidade',
                    'quantidade_prevista',
                    'valor_unitario',
                    'valor_com_bdi',
                    'valor_total',
                ]),
            ]);

            MedicaoItemAdditiveItem::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'additive_id' => $additive->id,
                'medicao_item_id' => $item->id,
                'medicao_item_version_id' => $version->id,
                'status' => 'novo',
                'item' => $item->item,
                'codigo' => $item->codigo,
                'banco' => $item->banco,
                'descricao' => $item->descricao,
                'unidade' => $item->unidade,
                'quantidade_nova' => $item->quantidade_prevista,
                'valor_unitario_novo' => $item->valor_unitario,
                'valor_com_bdi_novo' => $item->valor_com_bdi,
                'valor_total_novo' => $item->valor_total,
                'meta' => ['mass_test' => true],
            ]);
        }

        return $additive;
    }

    private function createAdjustmentIndexes(
        Tenant $tenant,
        Contract $contract,
        User $user,
        $items
    ): void {
        $indexes = [
            [
                'nome' => 'Indice Nacional da Construcao Civil',
                'codigo' => 'INCC',
                'base' => 1000,
                'current' => 1082,
                'items' => ['1.1', '1.2', '3.1', '3.2', '3.3', '3.4', '4.1', '4.2', '4.3', '4.4'],
                'competencias' => [
                    ['2026-06-01', 1025],
                    ['2026-07-01', 1045],
                    ['2026-08-01', 1070],
                    ['2026-09-01', 1082],
                ],
            ],
            [
                'nome' => 'Indice de Obras Rodoviarias',
                'codigo' => 'IRO',
                'base' => 100,
                'current' => 106.5,
                'items' => ['2.1', '2.2', '2.3', '5.1', '5.2', '5.3', '5.4', '5.5', '6.1', '6.2', '7.1', '7.2'],
                'competencias' => [
                    ['2026-06-01', 101.2],
                    ['2026-07-01', 103.1],
                    ['2026-08-01', 104.8],
                    ['2026-09-01', 106.5],
                ],
            ],
        ];

        foreach ($indexes as $definition) {
            $index = MedicaoIndiceReajuste::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'created_by_id' => $user->id,
                'nome' => $definition['nome'],
                'codigo' => $definition['codigo'],
                'indice_base' => $definition['base'],
                'data_base' => '2026-01-01',
                'indice_atual' => $definition['current'],
                'data_atual' => '2026-09-01',
                'observacao' => 'Indice criado para a massa de validacao.',
            ]);

            foreach ($definition['competencias'] as [$date, $value]) {
                MedicaoIndiceReajusteCompetencia::create([
                    'tenant_id' => $tenant->id,
                    'contract_id' => $contract->id,
                    'medicao_indice_reajuste_id' => $index->id,
                    'created_by_id' => $user->id,
                    'competencia' => $date,
                    'valor_indice' => $value,
                    'data_publicacao' => Carbon::parse($date)->addDays(10),
                    'observacao' => 'Competencia de teste.',
                ]);
            }

            foreach ($items->whereIn('item', $definition['items']) as $item) {
                MedicaoItemReajusteIndice::create([
                    'tenant_id' => $tenant->id,
                    'contract_id' => $contract->id,
                    'medicao_item_id' => $item->id,
                    'medicao_indice_reajuste_id' => $index->id,
                    'created_by_id' => $user->id,
                    'item_codigo' => $item->codigo,
                    'indice_codigo' => $index->codigo,
                    'source_type' => 'manual',
                ]);
            }
        }
    }

    private function createBoletins(Tenant $tenant, Contract $contract, User $user)
    {
        return collect([
            ['periodo' => '2026-07-01', 'tipo' => 'normal', 'status' => 'finalizado'],
            ['periodo' => '2026-08-01', 'tipo' => 'normal', 'status' => 'congelado'],
            ['periodo' => '2026-09-01', 'tipo' => 'reequilibrio', 'status' => 'aberto_lancamento'],
        ])->map(function (array $definition) use ($tenant, $contract, $user): BoletimMedicao {
            $next = ((int) BoletimMedicao::withTrashed()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $contract->id)
                ->max('sequencial')) + 1;

            return BoletimMedicao::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'created_by_id' => $user->id,
                'codigo' => 'BM-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT),
                'sequencial' => $next,
                'periodo' => $definition['periodo'],
                'tipo' => $definition['tipo'],
                'status' => $definition['status'],
            ]);
        });
    }

    private function createOrders(
        Tenant $tenant,
        Contract $contract,
        Obra $obra,
        array $companies,
        User $user,
        $items
    ) {
        $ranges = [
            [0, 8],
            [6, 14],
            [12, 21],
        ];

        return collect($ranges)->map(function (array $range, int $index) use (
            $tenant,
            $contract,
            $obra,
            $companies,
            $user,
            $items
        ): OrdemServico {
            $sequence = $index + 1;
            $order = OrdemServico::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'gerenciadora_empresa_id' => $companies['gerenciadora']->id,
                'construtora_empresa_id' => $companies['construtora']->id,
                'created_by_id' => $user->id,
                'codigo' => "{$contract->code}-{$obra->codigo}-OS-".str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                'sequencial' => $sequence,
                'titulo' => "OS {$sequence} - Frente de servicos ".chr(64 + $sequence),
                'descricao' => 'Ordem de servico aprovada para validar o fluxo de medicao controlada.',
                'prazo_execucao' => Carbon::parse('2026-08-01')->addMonths($sequence),
                'custo_previsto' => 0,
                'custo_observacao' => 'Valor calculado pelos itens vinculados.',
                'status' => 'aprovada',
                'submitted_for_review_at' => Carbon::parse('2026-06-01')->addDays($sequence),
                'submitted_for_review_by_id' => $user->id,
                'analyzed_at' => Carbon::parse('2026-06-05')->addDays($sequence),
                'analyzed_by_id' => $user->id,
                'analysis_observation' => 'Escopo conferido para a massa de teste.',
                'approval_decided_at' => Carbon::parse('2026-06-08')->addDays($sequence),
                'approval_decided_by_id' => $user->id,
                'approval_observation' => 'OS aprovada para execucao e medicao.',
            ]);

            $total = 0;
            foreach ($items->slice($range[0], $range[1] - $range[0] + 1) as $item) {
                $requested = (float) $item->quantidade_prevista;
                $value = $requested * (float) $item->valor_com_bdi;
                OrdemServicoItem::create([
                    'ordem_servico_id' => $order->id,
                    'medicao_item_id' => $item->id,
                    'quantidade_solicitada' => $requested,
                    'valor_previsto' => $value,
                ]);
                $total += $value;
            }

            $order->forceFill(['custo_previsto' => round($total, 2)])->save();

            return $order;
        });
    }

    private function createFolhas(
        Tenant $tenant,
        Contract $contract,
        Obra $obra,
        Empresa $construtora,
        User $user,
        $items,
        $basePrices,
        $boletins,
        $orders,
        string $memoryPath,
        string $mode,
        MedicaoItemAdditive $additive
    ) {
        $plans = [
            ['bm' => 0, 'indexes' => [0, 1, 2, 3], 'percent' => 0.10, 'status' => 'analisada'],
            ['bm' => 0, 'indexes' => [4, 5, 6, 7], 'percent' => 0.08, 'status' => 'analisada'],
            ['bm' => 0, 'indexes' => [0, 2, 6, 8], 'percent' => 0.12, 'status' => 'analisada'],
            ['bm' => 1, 'indexes' => [6, 7, 8, 9, 10], 'percent' => 0.15, 'status' => 'analisada'],
            ['bm' => 1, 'indexes' => [9, 10, 11, 12, 13], 'percent' => 0.10, 'status' => 'analisada'],
            ['bm' => 1, 'indexes' => [10, 11, 12, 13, 14], 'percent' => 0.18, 'status' => 'analisada'],
            ['bm' => 2, 'indexes' => [12, 14, 15, 16, 17], 'percent' => 0.08, 'status' => 'analisada'],
            ['bm' => 2, 'indexes' => [13, 15, 18, 19, 20], 'percent' => 0.06, 'status' => 'analise_medicao'],
            ['bm' => 2, 'indexes' => [12, 14, 19, 20, 21], 'percent' => 0.05, 'status' => 'rascunho'],
        ];

        $orderItemMap = collect();
        if ($mode === 'controlled') {
            foreach ($orders as $order) {
                foreach ($order->itens()->get() as $orderItem) {
                    $orderItemMap->push($orderItem);
                }
            }
        }

        return collect($plans)->map(function (array $plan, int $planIndex) use (
            $tenant,
            $contract,
            $obra,
            $construtora,
            $user,
            $items,
            $basePrices,
            $boletins,
            $orders,
            $orderItemMap,
            $memoryPath,
            $mode,
            $additive
        ): FolhaRosto {
            $sequence = $planIndex + 1;
            $order = null;

            if ($mode === 'controlled') {
                $order = $orders[$plan['bm']] ?? $orders->last();
            }

            $codePrefix = $order?->codigo ?? $contract->code;
            $folha = FolhaRosto::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'ordem_servico_id' => $order?->id,
                'boletim_medicao_id' => $boletins[$plan['bm']]->id,
                'construtora_empresa_id' => $construtora->id,
                'created_by_id' => $user->id,
                'codigo' => "{$codePrefix}-FR-".str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                'sequencial' => $sequence,
                'comentario' => $this->folhaComment($planIndex, $mode, $additive),
                'memoria_calculo_path' => $memoryPath,
                'memoria_calculo_nome_original' => "memoria-calculo-{$contract->code}-{$sequence}.zip",
                'memoria_calculo_mime_type' => 'application/zip',
                'memoria_calculo_size' => 22,
                'status' => $plan['status'],
                'submitted_for_analysis_at' => $plan['status'] === 'rascunho'
                    ? null
                    : Carbon::parse($boletins[$plan['bm']]->periodo)->addDays(5 + $planIndex),
            ]);

            $createdAt = Carbon::parse($boletins[$plan['bm']]->periodo)
                ->addDays(3 + $planIndex)
                ->setTime(9, 0);
            $folha->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

            foreach ($plan['indexes'] as $itemIndex) {
                $item = $items[$itemIndex];
                $quantity = round((float) $item->quantidade_prevista * $plan['percent'], 4);
                $historicalPrice = $plan['bm'] < 2
                    ? ($basePrices[$item->id] ?? (float) $item->valor_com_bdi)
                    : (float) $item->valor_com_bdi;
                $orderItem = null;

                if ($mode === 'controlled') {
                    $orderItem = $orderItemMap
                        ->where('ordem_servico_id', $order->id)
                        ->firstWhere('medicao_item_id', $item->id);

                    if (! $orderItem) {
                        $orderItem = $orderItemMap->firstWhere('medicao_item_id', $item->id);
                    }
                }

                $folhaItem = FolhaRostoItem::create([
                    'folha_rosto_id' => $folha->id,
                    'ordem_servico_item_id' => $orderItem?->id,
                    'medicao_item_id' => $item->id,
                    'quantidade_pleiteada' => $quantity,
                    'valor_pleiteado' => round($quantity * $historicalPrice, 2),
                    'precisa_analise_topografica' => in_array($item->item, ['2.1', '2.3', '4.1', '4.4'], true),
                    'precisa_analise_qualidade' => in_array($item->item, ['3.1', '3.2', '3.3', '5.3'], true),
                ]);

                $this->createAnalysisData($folha, $folhaItem, $user, $quantity, $plan['status']);
            }

            $this->createFlowHistory($folha, $user, $plan['status']);

            return $folha;
        });
    }

    private function createAnalysisData(
        FolhaRosto $folha,
        FolhaRostoItem $item,
        User $user,
        float $quantity,
        string $status
    ): void {
        $stages = match ($status) {
            'analisada' => [
                'fiscal' => 0.98,
                'qualidade' => 0.96,
                'medicao' => 0.95,
            ],
            'analise_medicao' => [
                'fiscal' => 0.98,
                'qualidade' => 0.96,
            ],
            default => [],
        };

        foreach ($stages as $stage => $factor) {
            $analysis = FolhaRostoAnalise::firstOrCreate(
                ['folha_rosto_id' => $folha->id, 'setor' => $stage],
                [
                    'user_id' => $user->id,
                    'comentario_geral' => match ($stage) {
                        'fiscal' => 'Quantitativos conferidos em campo.',
                        'qualidade' => 'Evidencias e criterios de qualidade conferidos.',
                        default => 'Quantidade consolidada para o boletim.',
                    },
                ]
            );

            FolhaRostoItemAnalise::create([
                'folha_rosto_item_id' => $item->id,
                'folha_rosto_analise_id' => $analysis->id,
                'setor' => $stage,
                'quantidade_aprovada' => round($quantity * $factor, 4),
                'comentario' => 'Aprovacao parcial criada para o teste de acumulados.',
            ]);
        }
    }

    private function createFlowHistory(FolhaRosto $folha, User $user, string $status): void
    {
        if ($status === 'rascunho') {
            return;
        }

        $steps = [
            ['rascunho', 'analise_fiscal', 'enviar_analise'],
            ['analise_fiscal', 'analise_qualidade', 'qualidade'],
            ['analise_qualidade', 'analise_medicao', 'medicao'],
        ];

        if ($status === 'analisada') {
            $steps[] = ['analise_medicao', 'analisada', 'finalizar'];
        }

        foreach ($steps as [$from, $to, $action]) {
            FolhaRostoFluxoHistorico::create([
                'folha_rosto_id' => $folha->id,
                'user_id' => $user->id,
                'status_origem' => $from,
                'status_destino' => $to,
                'acao' => $action,
                'motivo' => null,
            ]);
        }
    }

    private function folhaComment(int $planIndex, string $mode, MedicaoItemAdditive $additive): string
    {
        if ($planIndex === 8) {
            return "Pleito em rascunho com itens alterados e novos do {$additive->title}.";
        }

        $labels = [
            'Servicos iniciais e mobilizacao',
            'Terraplenagem e estruturas',
            'Drenagem e pavimentacao',
            'Redistribuicao de quantitativos do BM anterior',
            'Avanco das frentes intermediarias',
            'Consolidacao de itens medidos no periodo',
            'Medicao com valores reajustados',
            'Pleito em analise de medicao',
        ];

        return ($labels[$planIndex] ?? 'Pleito de teste')
            .' - fluxo '
            .($mode === 'simple' ? 'simples' : 'controlado');
    }

    private function baseItemDefinitions(): array
    {
        return [
            ['item' => '1.1', 'codigo' => 'MOB-001', 'descricao' => 'Mobilizacao e desmobilizacao', 'unidade' => 'UN', 'quantidade' => 1, 'valor' => 120000],
            ['item' => '1.2', 'codigo' => 'ADM-LOC', 'descricao' => 'Administracao local da obra', 'unidade' => 'MES', 'quantidade' => 12, 'valor' => 48000],
            ['item' => '2.1', 'codigo' => 'ESC-MEC', 'descricao' => 'Escavacao mecanica de material', 'unidade' => 'M3', 'quantidade' => 12000, 'valor' => 22.50],
            ['item' => '2.2', 'codigo' => 'TRN-MAT', 'descricao' => 'Transporte de material escavado', 'unidade' => 'M3XKM', 'quantidade' => 85000, 'valor' => 1.85],
            ['item' => '2.3', 'codigo' => 'ATR-CMP', 'descricao' => 'Aterro compactado a 100% do Proctor', 'unidade' => 'M3', 'quantidade' => 9000, 'valor' => 48.20],
            ['item' => '3.1', 'codigo' => 'CON-MAG', 'descricao' => 'Concreto magro para regularizacao', 'unidade' => 'M3', 'quantidade' => 420, 'valor' => 520],
            ['item' => '3.2', 'codigo' => 'CON-EST', 'descricao' => 'Concreto estrutural fck 30 MPa', 'unidade' => 'M3', 'quantidade' => 1250, 'valor' => 780],
            ['item' => '3.3', 'codigo' => 'ACO-CA50', 'descricao' => 'Aco CA-50 fornecimento e montagem', 'unidade' => 'KG', 'quantidade' => 135000, 'valor' => 10.50],
            ['item' => '3.4', 'codigo' => 'FOR-CMP', 'descricao' => 'Forma compensada resinada', 'unidade' => 'M2', 'quantidade' => 9000, 'valor' => 142],
            ['item' => '4.1', 'codigo' => 'DRE-DN600', 'descricao' => 'Drenagem tubular DN 600 mm', 'unidade' => 'M', 'quantidade' => 2400, 'valor' => 320],
            ['item' => '4.2', 'codigo' => 'BUE-CEL', 'descricao' => 'Bueiro celular de concreto', 'unidade' => 'M', 'quantidade' => 180, 'valor' => 3900],
            ['item' => '4.3', 'codigo' => 'CX-INS', 'descricao' => 'Caixa de inspecao em concreto', 'unidade' => 'UN', 'quantidade' => 85, 'valor' => 1850],
            ['item' => '5.1', 'codigo' => 'SUB-BAS', 'descricao' => 'Sub-base de solo estabilizado', 'unidade' => 'M3', 'quantidade' => 6000, 'valor' => 115],
            ['item' => '5.2', 'codigo' => 'BAS-BRT', 'descricao' => 'Base de brita graduada', 'unidade' => 'M3', 'quantidade' => 5000, 'valor' => 145],
            ['item' => '5.3', 'codigo' => 'PAV-CBUQ', 'descricao' => 'Pavimento asfaltico em CBUQ', 'unidade' => 'T', 'quantidade' => 4500, 'valor' => 610],
            ['item' => '5.4', 'codigo' => 'MFI-CON', 'descricao' => 'Meio-fio de concreto pre-moldado', 'unidade' => 'M', 'quantidade' => 7800, 'valor' => 78],
            ['item' => '6.1', 'codigo' => 'SIN-HOR', 'descricao' => 'Sinalizacao horizontal viaria', 'unidade' => 'M2', 'quantidade' => 12000, 'valor' => 32],
            ['item' => '6.2', 'codigo' => 'SIN-VER', 'descricao' => 'Sinalizacao vertical de regulamentacao', 'unidade' => 'UN', 'quantidade' => 320, 'valor' => 480],
            ['item' => '7.1', 'codigo' => 'PAI-001', 'descricao' => 'Paisagismo e recomposicao vegetal', 'unidade' => 'M2', 'quantidade' => 15000, 'valor' => 28],
            ['item' => '7.2', 'codigo' => 'LIM-FIN', 'descricao' => 'Limpeza final da obra', 'unidade' => 'M2', 'quantidade' => 80000, 'valor' => 5.80],
        ];
    }
}

<?php

use App\Models\BoletimMedicao;
use App\Models\Contract;
use App\Models\Empresa;
use App\Models\FolhaRosto;
use App\Models\FolhaRostoAnalise;
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
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$prefix = 'SIM-ADITIVO-HIST';

$tenant = Tenant::query()->orderBy('id')->firstOrFail();
$user = User::query()->orderBy('id')->firstOrFail();

$result = DB::transaction(function () use ($tenant, $user, $prefix): array {
    $tipoCliente = TipoEmpresa::firstOrCreate(['nome' => 'cliente']);
    $tipoConstrutora = TipoEmpresa::firstOrCreate(['nome' => 'construtora']);
    $tipoGerenciadora = TipoEmpresa::firstOrCreate(['nome' => 'gerenciadora']);

    $cliente = Empresa::firstOrCreate(
        ['tenant_id' => $tenant->id, 'sigla' => "{$prefix}-CLI"],
        ['tipo_empresa_id' => $tipoCliente->id, 'nome' => 'Cliente Simulação Aditivo Histórico', 'cnpj' => '00.000.000/0001-01']
    );

    $construtora = Empresa::firstOrCreate(
        ['tenant_id' => $tenant->id, 'sigla' => "{$prefix}-CTX"],
        ['tipo_empresa_id' => $tipoConstrutora->id, 'nome' => 'Construtora Simulação Aditivo Histórico', 'cnpj' => '00.000.000/0001-02']
    );

    $gerenciadora = Empresa::firstOrCreate(
        ['tenant_id' => $tenant->id, 'sigla' => "{$prefix}-GER"],
        ['tipo_empresa_id' => $tipoGerenciadora->id, 'nome' => 'Gerenciadora Simulação Aditivo Histórico', 'cnpj' => '00.000.000/0001-03']
    );

    $contract = Contract::updateOrCreate(
        ['tenant_id' => $tenant->id, 'code' => $prefix],
        [
            'cliente_empresa_id' => $cliente->id,
            'construtora_empresa_id' => $construtora->id,
            'fiscalizadora_empresa_id' => $gerenciadora->id,
            'name' => 'Contrato Simulação Aditivos, Reajuste e Histórico',
            'description' => 'Massa de simulação para testar aditivos, histórico de medição e impacto de novos índices.',
            'client_company_name' => $cliente->nome,
            'contractor_company_name' => $construtora->nome,
            'total_value' => 500000,
            'currency' => 'BRL',
            'city' => 'Belém',
            'state' => 'PA',
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
            'status' => 'active',
        ]
    );

    foreach ([$cliente, $construtora, $gerenciadora] as $empresa) {
        $empresa->forceFill(['contract_id' => $contract->id])->save();
    }

    $obra = Obra::updateOrCreate(
        ['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'codigo' => '900'],
        ['nome' => 'Obra Simulação Aditivos e Reajuste', 'tipo' => 'pai']
    );

    $contract->forceFill(['obra_id' => $obra->id])->save();

    $indice = MedicaoIndiceReajuste::updateOrCreate(
        ['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'codigo' => "{$prefix}-INCC"],
        [
            'created_by_id' => $user->id,
            'nome' => 'Índice Simulação INCC Histórico',
            'indice_base' => 100,
            'data_base' => '2026-01-01',
            'indice_atual' => 112,
            'data_atual' => '2026-02-01',
            'observacao' => 'Índice usado para testar histórico de BMs após novas competências.',
        ]
    );

    foreach ([
        ['competencia' => '2026-01-01', 'valor_indice' => 105, 'observacao' => 'Competência inicial usada no BM-01 da simulação.'],
        ['competencia' => '2026-02-01', 'valor_indice' => 112, 'observacao' => 'Competência usada no BM-02 da simulação.'],
        ['competencia' => '2026-03-01', 'valor_indice' => 128, 'observacao' => 'Competência posterior criada para revelar impacto retroativo nos relatórios.'],
    ] as $competencia) {
        MedicaoIndiceReajusteCompetencia::updateOrCreate(
            [
                'medicao_indice_reajuste_id' => $indice->id,
                'competencia' => $competencia['competencia'],
            ],
            [
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'created_by_id' => $user->id,
                'valor_indice' => $competencia['valor_indice'],
                'data_publicacao' => $competencia['competencia'],
                'observacao' => $competencia['observacao'],
            ]
        );
    }

    $itemsPayload = [
        [
            'item' => '9.9.1',
            'codigo' => "{$prefix}-A",
            'descricao' => 'Item A - base com acréscimo posterior de quantitativo',
            'unidade' => 'm',
            'quantidade_prevista' => 100,
            'valor_unitario' => 90,
            'valor_com_bdi' => 100,
        ],
        [
            'item' => '9.9.2',
            'codigo' => "{$prefix}-B",
            'descricao' => 'Item B - redução abaixo do quantitativo já medido',
            'unidade' => 'm²',
            'quantidade_prevista' => 50,
            'valor_unitario' => 180,
            'valor_com_bdi' => 200,
        ],
        [
            'item' => '9.9.3',
            'codigo' => "{$prefix}-C",
            'descricao' => 'Item C - item estável para comparação',
            'unidade' => 'un',
            'quantidade_prevista' => 20,
            'valor_unitario' => 475,
            'valor_com_bdi' => 500,
        ],
    ];

    $items = collect($itemsPayload)->mapWithKeys(function (array $payload) use ($tenant, $contract, $user, $indice): array {
        $item = MedicaoItem::updateOrCreate(
            ['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'codigo' => $payload['codigo']],
            [
                'created_by_id' => $user->id,
                'source_type' => 'simulacao',
                'item' => $payload['item'],
                'nivel' => 3,
                'item_type' => 'servico',
                'banco' => 'SIM',
                'descricao' => $payload['descricao'],
                'unidade' => $payload['unidade'],
                'quantidade_prevista' => $payload['quantidade_prevista'],
                'valor_unitario' => $payload['valor_unitario'],
                'valor_com_bdi' => $payload['valor_com_bdi'],
                'valor_total' => $payload['quantidade_prevista'] * $payload['valor_com_bdi'],
                'meta' => ['simulation' => 'aditivos-reajuste-historico'],
            ]
        );

        MedicaoItemVersion::updateOrCreate(
            ['medicao_item_id' => $item->id, 'version_number' => 1],
            [
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'created_by_id' => $user->id,
                'version_label' => 'Base simulação',
                'change_type' => 'base',
                'quantidade_prevista' => $payload['quantidade_prevista'],
                'valor_unitario' => $payload['valor_unitario'],
                'valor_com_bdi' => $payload['valor_com_bdi'],
                'valor_total' => $payload['quantidade_prevista'] * $payload['valor_com_bdi'],
                'starts_at' => '2026-01-01 00:00:00',
                'snapshot' => $payload,
            ]
        );

        MedicaoItemReajusteIndice::updateOrCreate(
            ['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'medicao_item_id' => $item->id],
            [
                'medicao_indice_reajuste_id' => $indice->id,
                'created_by_id' => $user->id,
                'item_codigo' => $item->codigo,
                'indice_codigo' => $indice->codigo,
                'source_type' => 'simulacao',
            ]
        );

        return [$payload['codigo'] => $item->fresh()];
    });

    $os = OrdemServico::updateOrCreate(
        ['tenant_id' => $tenant->id, 'codigo' => "{$prefix}-OS-001"],
        [
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'gerenciadora_empresa_id' => $gerenciadora->id,
            'construtora_empresa_id' => $construtora->id,
            'created_by_id' => $user->id,
            'sequencial' => 1,
            'titulo' => 'OS Simulação Aditivos/Reajuste',
            'descricao' => 'OS usada para simular medições antes e depois de aditivos.',
            'prazo_execucao' => '2026-12-31',
            'custo_previsto' => $items->sum('valor_total'),
            'status' => 'aprovada',
        ]
    );

    $osItems = $items->mapWithKeys(function (MedicaoItem $item) use ($os): array {
        $osItem = OrdemServicoItem::updateOrCreate(
            ['ordem_servico_id' => $os->id, 'medicao_item_id' => $item->id],
            [
                'quantidade_solicitada' => $item->quantidade_prevista,
                'valor_previsto' => $item->valor_total,
            ]
        );

        return [$item->codigo => $osItem];
    });

    $bm1 = BoletimMedicao::updateOrCreate(
        ['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'codigo' => "{$prefix}-BM-001"],
        [
            'sequencial' => 9001,
            'periodo' => '2026-01-01',
            'tipo' => 'normal',
            'status' => 'aberto_lancamento',
            'created_by_id' => $user->id,
        ]
    );

    $bm2 = BoletimMedicao::updateOrCreate(
        ['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'codigo' => "{$prefix}-BM-002"],
        [
            'sequencial' => 9002,
            'periodo' => '2026-02-01',
            'tipo' => 'normal',
            'status' => 'aberto_lancamento',
            'created_by_id' => $user->id,
        ]
    );

    $createFr = function (BoletimMedicao $bm, int $sequencial, string $comentario, array $quantidades) use ($tenant, $contract, $obra, $os, $user, $construtora, $osItems): FolhaRosto {
        $fr = FolhaRosto::updateOrCreate(
            ['tenant_id' => $tenant->id, 'codigo' => "{$bm->codigo}-FR-".str_pad((string) $sequencial, 3, '0', STR_PAD_LEFT)],
            [
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'ordem_servico_id' => $os->id,
                'boletim_medicao_id' => $bm->id,
                'construtora_empresa_id' => $construtora->id,
                'created_by_id' => $user->id,
                'sequencial' => $sequencial,
                'comentario' => $comentario,
                'status' => 'analisada',
                'submitted_for_analysis_at' => $bm->periodo,
            ]
        );

        $analise = FolhaRostoAnalise::updateOrCreate(
            ['folha_rosto_id' => $fr->id, 'setor' => 'medicao'],
            [
                'user_id' => $user->id,
                'comentario_geral' => "Medição finalizada para {$fr->codigo}",
            ]
        );

        foreach ($quantidades as $codigoItem => $quantidade) {
            /** @var OrdemServicoItem $osItem */
            $osItem = $osItems[$codigoItem];
            $precoUnitario = (float) $osItem->valor_previsto / max(1, (float) $osItem->quantidade_solicitada);
            $frItem = FolhaRostoItem::updateOrCreate(
                ['folha_rosto_id' => $fr->id, 'ordem_servico_item_id' => $osItem->id],
                [
                    'quantidade_pleiteada' => $quantidade,
                    'valor_pleiteado' => round($quantidade * $precoUnitario, 2),
                    'precisa_analise_topografica' => false,
                    'precisa_analise_qualidade' => false,
                ]
            );

            FolhaRostoItemAnalise::updateOrCreate(
                ['folha_rosto_item_id' => $frItem->id, 'setor' => 'medicao'],
                [
                    'folha_rosto_analise_id' => $analise->id,
                    'quantidade_aprovada' => $quantidade,
                    'comentario' => "Qtd. aprovada na simulação: {$quantidade}",
                ]
            );
        }

        return $fr;
    };

    $fr1 = $createFr($bm1, 1, 'BM 01 antes do aditivo: mede A=40, B=30, C=5.', [
        "{$prefix}-A" => 40,
        "{$prefix}-B" => 30,
        "{$prefix}-C" => 5,
    ]);

    $nextAdditiveNumber = ((int) MedicaoItemAdditive::query()
        ->where('tenant_id', $tenant->id)
        ->where('contract_id', $contract->id)
        ->max('number')) + 1;

    $additive = MedicaoItemAdditive::updateOrCreate(
        ['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'number' => 9001],
        [
            'created_by_id' => $user->id,
            'title' => 'Aditivo simulação: acréscimo e redução crítica',
            'reason' => 'Aumenta Item A, reduz Item B para abaixo do medido, mantém Item C.',
            'source_type' => 'simulacao',
            'status' => 'applied',
            'effective_at' => '2026-02-01 00:00:00',
            'applied_at' => now(),
            'meta' => [
                'simulation' => 'aditivos-reajuste-historico',
                'note' => "Próximo número normal seria {$nextAdditiveNumber}; usado 9001 para isolar a simulação.",
            ],
        ]
    );

    $additivePayloads = [
        "{$prefix}-A" => ['quantidade_prevista' => 150, 'valor_com_bdi' => 110, 'valor_unitario' => 99],
        "{$prefix}-B" => ['quantidade_prevista' => 20, 'valor_com_bdi' => 210, 'valor_unitario' => 189],
        "{$prefix}-C" => ['quantidade_prevista' => 20, 'valor_com_bdi' => 500, 'valor_unitario' => 475],
    ];

    foreach ($additivePayloads as $codigo => $payload) {
        /** @var MedicaoItem $item */
        $item = $items[$codigo]->fresh();
        $old = [
            'item' => $item->item,
            'codigo' => $item->codigo,
            'banco' => $item->banco,
            'descricao' => $item->descricao,
            'unidade' => $item->unidade,
            'quantidade_prevista' => (float) $item->quantidade_prevista,
            'valor_unitario' => (float) $item->valor_unitario,
            'valor_com_bdi' => (float) $item->valor_com_bdi,
            'valor_total' => (float) $item->valor_total,
        ];
        $new = array_merge($old, $payload, [
            'valor_total' => $payload['quantidade_prevista'] * $payload['valor_com_bdi'],
            'meta' => ['simulation_additive' => true],
        ]);

        $version = MedicaoItemVersion::updateOrCreate(
            ['medicao_item_id' => $item->id, 'version_number' => 2],
            [
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'additive_id' => $additive->id,
                'created_by_id' => $user->id,
                'version_label' => 'Aditivo 9001',
                'change_type' => $new == $old ? 'unchanged' : 'changed',
                'quantidade_prevista' => $new['quantidade_prevista'],
                'valor_unitario' => $new['valor_unitario'],
                'valor_com_bdi' => $new['valor_com_bdi'],
                'valor_total' => $new['valor_total'],
                'starts_at' => '2026-02-01 00:00:00',
                'snapshot' => $new,
            ]
        );

        $item->fill([
            'quantidade_prevista' => $new['quantidade_prevista'],
            'valor_unitario' => $new['valor_unitario'],
            'valor_com_bdi' => $new['valor_com_bdi'],
            'valor_total' => $new['valor_total'],
            'meta' => array_merge($item->meta ?? [], ['latest_additive_id' => $additive->id, 'latest_additive_number' => 9001]),
        ])->save();

        MedicaoItemAdditiveItem::updateOrCreate(
            ['additive_id' => $additive->id, 'medicao_item_id' => $item->id],
            [
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'medicao_item_version_id' => $version->id,
                'status' => $new == $old ? 'sem_alteracao' : 'alterado',
                'item' => $new['item'],
                'codigo' => $new['codigo'],
                'banco' => $new['banco'],
                'descricao' => $new['descricao'],
                'unidade' => $new['unidade'],
                'quantidade_anterior' => $old['quantidade_prevista'],
                'quantidade_nova' => $new['quantidade_prevista'],
                'valor_unitario_anterior' => $old['valor_unitario'],
                'valor_unitario_novo' => $new['valor_unitario'],
                'valor_com_bdi_anterior' => $old['valor_com_bdi'],
                'valor_com_bdi_novo' => $new['valor_com_bdi'],
                'valor_total_anterior' => $old['valor_total'],
                'valor_total_novo' => $new['valor_total'],
                'meta' => ['old' => $old, 'new' => $new],
            ]
        );
    }

    $items = MedicaoItem::query()
        ->where('tenant_id', $tenant->id)
        ->where('contract_id', $contract->id)
        ->whereIn('codigo', array_keys($additivePayloads))
        ->get()
        ->keyBy('codigo');

    foreach ($items as $codigo => $item) {
        $osItems[$codigo]->fill([
            'quantidade_solicitada' => $item->quantidade_prevista,
            'valor_previsto' => $item->valor_total,
        ])->save();
    }

    $fr2 = $createFr($bm2, 2, 'BM 02 após aditivo: mede A=50, B=5 mesmo com limite novo menor que acumulado, C=5.', [
        "{$prefix}-A" => 50,
        "{$prefix}-B" => 5,
        "{$prefix}-C" => 5,
    ]);

    $medidoB = 30 + 5;
    $limiteNovoB = 20;

    return [
        'tenant_slug' => $tenant->slug,
        'contract_id' => $contract->id,
        'contract_code' => $contract->code,
        'boletins' => [
            ['id' => $bm1->id, 'codigo' => $bm1->codigo, 'periodo' => $bm1->periodo?->format('m/y')],
            ['id' => $bm2->id, 'codigo' => $bm2->codigo, 'periodo' => $bm2->periodo?->format('m/y')],
        ],
        'folhas_rosto' => [$fr1->codigo, $fr2->codigo],
        'aditivo' => [
            'id' => $additive->id,
            'number' => $additive->number,
            'item_b_limite_novo' => $limiteNovoB,
            'item_b_medido_total' => $medidoB,
            'item_b_inconsistencia_intencional' => $medidoB > $limiteNovoB,
        ],
        'indice' => [
            'id' => $indice->id,
            'codigo' => $indice->codigo,
            'competencias' => $indice->competencias()->orderBy('competencia')->get(['competencia', 'valor_indice'])->map(fn ($c) => [
                'competencia' => $c->competencia?->format('m/Y'),
                'valor' => (float) $c->valor_indice,
            ])->all(),
        ],
        'urls' => [
            'relatorios' => "/{$tenant->slug}/medicao/relatorios?contract_id={$contract->id}",
            'relatorio_bm1_sintetico' => "/{$tenant->slug}/medicao/relatorios?contract_id={$contract->id}&boletim_id={$bm1->id}&relatorio=sintetico",
            'relatorio_bm2_por_fr' => "/{$tenant->slug}/medicao/relatorios?contract_id={$contract->id}&boletim_id={$bm2->id}&relatorio=por_fr",
            'itens' => "/{$tenant->slug}/medicao/item?contract_id={$contract->id}",
            'indices' => "/{$tenant->slug}/medicao/indice-reajuste?contract_id={$contract->id}",
        ],
    ];
});

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

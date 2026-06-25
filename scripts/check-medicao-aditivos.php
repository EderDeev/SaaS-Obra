<?php

use App\Http\Controllers\Tenant\MedicaoRelatorioController;
use App\Models\BoletimMedicao;
use App\Models\Contract;
use App\Models\MedicaoItem;
use App\Models\OrdemServicoItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$prefix = 'SIM-ADITIVO-HIST';
$tenant = Tenant::query()->where('slug', 'demo')->first() ?? Tenant::query()->orderBy('id')->firstOrFail();
$contract = Contract::query()
    ->where('tenant_id', $tenant->id)
    ->where('code', $prefix)
    ->firstOrFail();

$controller = app(MedicaoRelatorioController::class);
$rowsForReport = new ReflectionMethod($controller, 'rowsForReport');
$rowsForReport->setAccessible(true);
$medicaoController = app(App\Http\Controllers\Tenant\MedicaoController::class);
$processAdditivePayloads = new ReflectionMethod($medicaoController, 'processAdditivePayloads');
$processAdditivePayloads->setAccessible(true);

$bm1 = BoletimMedicao::query()->where('tenant_id', $tenant->id)->where('contract_id', $contract->id)->where('codigo', "{$prefix}-BM-001")->firstOrFail();
$bm2 = BoletimMedicao::query()->where('tenant_id', $tenant->id)->where('contract_id', $contract->id)->where('codigo', "{$prefix}-BM-002")->firstOrFail();

$items = MedicaoItem::query()
    ->where('tenant_id', $tenant->id)
    ->where('contract_id', $contract->id)
    ->whereIn('codigo', ["{$prefix}-A", "{$prefix}-B", "{$prefix}-C"])
    ->with(['versions' => fn ($query) => $query->orderBy('version_number')])
    ->get()
    ->keyBy('codigo');

$osItems = OrdemServicoItem::query()
    ->whereIn('medicao_item_id', $items->pluck('id'))
    ->get()
    ->keyBy('medicao_item_id');

$sinteticoBm1 = collect($rowsForReport->invoke($controller, $tenant, $bm1, 'sintetico'));
$sinteticoBm2 = collect($rowsForReport->invoke($controller, $tenant, $bm2, 'sintetico'));
$porFrBm2 = collect($rowsForReport->invoke($controller, $tenant, $bm2, 'por_fr'));

$itemB = $items["{$prefix}-B"];
$itemBRowBm2 = $sinteticoBm2->firstWhere('item', $itemB->item);
$user = User::query()->orderBy('id')->firstOrFail();
$request = Request::create('/simulacao/aditivo-invalido', 'POST');
$request->setUserResolver(fn () => $user);
$invalidAdditiveBlocked = false;
$invalidAdditiveMessage = null;

try {
    $processAdditivePayloads->invoke(
        $medicaoController,
        $request,
        $tenant,
        $contract,
        [[
            'item' => $itemB->item,
            'nivel' => 3,
            'item_type' => 'servico',
            'codigo' => $itemB->codigo,
            'banco' => $itemB->banco,
            'descricao' => $itemB->descricao,
            'unidade' => $itemB->unidade,
            'quantidade_prevista' => 10,
            'valor_unitario' => (float) $itemB->valor_unitario,
            'valor_com_bdi' => (float) $itemB->valor_com_bdi,
            'valor_total' => 10 * (float) $itemB->valor_com_bdi,
            'meta' => ['simulation_test' => 'invalid_reduction_should_be_blocked'],
        ]],
        'simulacao_teste_bloqueio',
        null,
        [
            'additive_title' => 'Teste bloqueio redução abaixo do medido',
            'additive_reason' => 'Este aditivo não deve ser criado.',
            'effective_at' => '2026-04-01',
        ]
    );
} catch (ValidationException $exception) {
    $invalidAdditiveBlocked = true;
    $invalidAdditiveMessage = collect($exception->errors())->flatten()->first();
}

$diagnostico = [
    'contract' => [
        'id' => $contract->id,
        'code' => $contract->code,
    ],
    'bms' => [
        'bm1' => ['id' => $bm1->id, 'codigo' => $bm1->codigo],
        'bm2' => ['id' => $bm2->id, 'codigo' => $bm2->codigo],
    ],
    'itens' => $items->map(function (MedicaoItem $item) use ($osItems): array {
        $base = $item->versions->firstWhere('version_number', 1);
        $aditivo = $item->versions->firstWhere('version_number', 2);
        $osItem = $osItems->get($item->id);

        return [
            'item' => $item->item,
            'codigo' => $item->codigo,
            'quantidade_base' => (float) ($base?->quantidade_prevista ?? 0),
            'preco_p0_base' => (float) ($base?->valor_com_bdi ?? 0),
            'quantidade_atual_apos_aditivo' => (float) $item->quantidade_prevista,
            'preco_p0_atual_apos_aditivo' => (float) $item->valor_com_bdi,
            'os_quantidade_atual' => (float) ($osItem?->quantidade_solicitada ?? 0),
            'os_valor_atual' => (float) ($osItem?->valor_previsto ?? 0),
            'aditivo_quantidade' => (float) ($aditivo?->quantidade_prevista ?? 0),
            'aditivo_preco_p0' => (float) ($aditivo?->valor_com_bdi ?? 0),
        ];
    })->values(),
    'sintetico_bm1_linhas' => $sinteticoBm1->map(fn (array $row): array => [
        'item' => $row['item'] ?? null,
        'qtd_periodo' => $row['qtd_no_periodo'] ?? null,
        'valor_periodo' => $row['valor_no_periodo'] ?? null,
        'valor_reajuste_periodo' => $row['valor_reajuste_periodo'] ?? null,
        'indice_reajuste_percentual' => $row['indice_reajuste'] ?? null,
    ])->values(),
    'sintetico_bm2_item_b' => $itemBRowBm2,
    'alertas' => [
        'item_b_medido_maior_que_limite_atual' => [
            'limite_atual' => (float) $itemB->quantidade_prevista,
            'medido_acumulado_no_relatorio_bm2' => (float) ($itemBRowBm2['qtd_acumulado_atual'] ?? 0),
            'tem_inconsistencia' => (float) ($itemBRowBm2['qtd_acumulado_atual'] ?? 0) > (float) $itemB->quantidade_prevista,
        ],
        'bm1_usa_indice_mais_recente' => [
            'indice_exibido_bm1' => $sinteticoBm1->first()['indice_reajuste'] ?? null,
            'esperado_se_fosse_competencia_jan_2026' => 5.0,
            'indicio_de_recalculo_retroativo' => (float) ($sinteticoBm1->first()['indice_reajuste'] ?? 0) !== 5.0,
        ],
        'fluxo_oficial_bloqueia_aditivo_abaixo_do_medido' => [
            'bloqueou' => $invalidAdditiveBlocked,
            'mensagem' => $invalidAdditiveMessage,
        ],
    ],
    'por_fr_bm2' => $porFrBm2->map(fn (array $row): array => [
        'grupo' => $row['group_title'] ?? null,
        'item' => $row['item'] ?? null,
        'descricao' => $row['descricao'] ?? null,
        'qtd_no_periodo' => $row['qtd_no_periodo'] ?? null,
        'valor_no_periodo' => $row['valor_no_periodo'] ?? null,
        'total_fr' => (bool) ($row['_is_fr_total'] ?? false),
    ])->values(),
    'urls' => [
        'bm1_sintetico' => "/{$tenant->slug}/medicao/relatorios?contract_id={$contract->id}&boletim_id={$bm1->id}&relatorio=sintetico",
        'bm2_sintetico' => "/{$tenant->slug}/medicao/relatorios?contract_id={$contract->id}&boletim_id={$bm2->id}&relatorio=sintetico",
        'bm2_por_fr' => "/{$tenant->slug}/medicao/relatorios?contract_id={$contract->id}&boletim_id={$bm2->id}&relatorio=por_fr",
    ],
];

echo json_encode($diagnostico, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

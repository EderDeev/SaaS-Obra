<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tables = [
    // Folhas de rosto e BMs
    'folha_rosto_item_analises',
    'folha_rosto_analises',
    'folha_rosto_fluxo_historicos',
    'folha_rosto_itens',
    'folhas_rosto',
    'boletins_medicao',

    // Itens de medição, aditivos, versões e reajustes
    'medicao_item_reajuste_indices',
    'medicao_indice_reajuste_competencias',
    'medicao_indices_reajuste',
    'medicao_item_additive_items',
    'medicao_item_versions',
    'medicao_item_additives',
    'medicao_itens',

    // Itens vinculados às OS; as OS ficam, mas sem itens vinculados
    'ordem_servico_itens',

    // Orçamento e bases associadas do módulo
    'orcamento_itens',
    'orcamento_etapas',
    'orcamentos',
    'orcamento_composicao_analitico_items',
    'orcamento_composicao_items',
    'orcamento_composicoes',
    'orcamento_insumos',
    'orcamento_insumo_grupos',
];

$existingTables = array_values(array_filter($tables, fn (string $table): bool => Schema::hasTable($table)));

$countRows = function (array $tables): array {
    $counts = [];

    foreach ($tables as $table) {
        $counts[$table] = (int) DB::table($table)->count();
    }

    return $counts;
};

$before = $countRows($existingTables);

DB::transaction(function () use ($existingTables): void {
    if ($existingTables === []) {
        return;
    }

    $quoted = collect($existingTables)
        ->map(fn (string $table): string => '"'.str_replace('"', '""', $table).'"')
        ->implode(', ');

    DB::statement("TRUNCATE TABLE {$quoted} RESTART IDENTITY CASCADE");
});

$after = $countRows($existingTables);

echo json_encode([
    'status' => 'ok',
    'message' => 'Orçamento, itens de medição, BMs, FRs, aditivos e reajustes foram zerados.',
    'preservado' => [
        'tenants',
        'users',
        'contracts',
        'obras',
        'empresas',
        'ordem_servicos',
        'folha_rosto_analise_responsaveis',
    ],
    'antes' => $before,
    'depois' => $after,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

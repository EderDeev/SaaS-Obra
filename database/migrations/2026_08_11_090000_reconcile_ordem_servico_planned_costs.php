<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ordem_servicos')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($orders): void {
                foreach ($orders as $order) {
                    $plannedCost = DB::table('ordem_servico_itens')
                        ->where('ordem_servico_id', $order->id)
                        ->sum('valor_previsto');

                    DB::table('ordem_servicos')
                        ->where('id', $order->id)
                        ->update(['custo_previsto' => $plannedCost]);
                }
            });
    }

    public function down(): void
    {
        // Previous manually entered totals cannot be reconstructed safely.
    }
};

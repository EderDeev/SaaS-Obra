<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            foreach ($this->priceColumns() as $table => $columns) {
                foreach ($columns as $column) {
                    DB::statement(sprintf(
                        'ALTER TABLE "%s" ALTER COLUMN "%s" TYPE NUMERIC(18, 6) USING "%s"::NUMERIC(18, 6)',
                        $table,
                        $column,
                        $column,
                    ));
                }
            }

            return;
        }

        if ($driver === 'mysql') {
            foreach ($this->priceColumns() as $table => $columns) {
                foreach ($columns as $column) {
                    DB::statement(sprintf(
                        'ALTER TABLE `%s` MODIFY `%s` DECIMAL(18, 6)',
                        $table,
                        $column,
                    ));
                }
            }
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            foreach ($this->priceColumns() as $table => $columns) {
                foreach ($columns as $column) {
                    DB::statement(sprintf(
                        'ALTER TABLE "%s" ALTER COLUMN "%s" TYPE NUMERIC(15, 2) USING "%s"::NUMERIC(15, 2)',
                        $table,
                        $column,
                        $column,
                    ));
                }
            }

            return;
        }

        if ($driver === 'mysql') {
            foreach ($this->priceColumns() as $table => $columns) {
                foreach ($columns as $column) {
                    DB::statement(sprintf(
                        'ALTER TABLE `%s` MODIFY `%s` DECIMAL(15, 2)',
                        $table,
                        $column,
                    ));
                }
            }
        }
    }

    private function priceColumns(): array
    {
        return [
            'orcamento_insumos' => [
                'preco_nao_desonerado',
                'preco_desonerado',
            ],
            'orcamento_composicoes' => [
                'preco_onerado',
                'preco_desonerado',
            ],
            'orcamento_composicao_items' => [
                'preco_unitario_onerado',
                'preco_unitario_desonerado',
                'preco_onerado',
                'preco_desonerado',
            ],
        ];
    }
};

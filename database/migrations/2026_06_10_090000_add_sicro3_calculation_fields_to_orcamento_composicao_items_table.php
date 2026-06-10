<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_composicao_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('orcamento_composicao_items', 'custo_improdutivo_onerado')) {
                $table->decimal('custo_improdutivo_onerado', 18, 6)->nullable()->after('preco_unitario_desonerado');
            }

            if (! Schema::hasColumn('orcamento_composicao_items', 'custo_improdutivo_desonerado')) {
                $table->decimal('custo_improdutivo_desonerado', 18, 6)->nullable()->after('custo_improdutivo_onerado');
            }

            if (! Schema::hasColumn('orcamento_composicao_items', 'sicro3_utilizacao_operativa')) {
                $table->decimal('sicro3_utilizacao_operativa', 8, 6)->nullable()->after('coeficiente');
            }

            if (! Schema::hasColumn('orcamento_composicao_items', 'sicro3_utilizacao_improdutiva')) {
                $table->decimal('sicro3_utilizacao_improdutiva', 8, 6)->nullable()->after('sicro3_utilizacao_operativa');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orcamento_composicao_items', function (Blueprint $table): void {
            foreach ([
                'sicro3_utilizacao_improdutiva',
                'sicro3_utilizacao_operativa',
                'custo_improdutivo_desonerado',
                'custo_improdutivo_onerado',
            ] as $column) {
                if (Schema::hasColumn('orcamento_composicao_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_composicao_analitico_items', function (Blueprint $table): void {
            $table->text('descricao_composicao')->nullable()->after('codigo_composicao');
            $table->string('unidade_composicao', 20)->nullable()->after('descricao_composicao');
            $table->decimal('producao_equipe', 15, 6)->nullable()->after('unidade_composicao');
            $table->decimal('fator_influencia_chuvas', 15, 6)->nullable()->after('producao_equipe');
            $table->string('secao', 20)->nullable()->after('coeficiente');
            $table->string('tipo_transporte', 40)->nullable()->after('secao');
            $table->string('codigo_item_referenciado', 50)->nullable()->after('tipo_transporte');
            $table->decimal('utilizacao_operativa', 15, 6)->nullable()->after('codigo_item_referenciado');
            $table->decimal('utilizacao_improdutiva', 15, 6)->nullable()->after('utilizacao_operativa');

            $table->index(
                ['modelo', 'codigo_composicao', 'uf', 'data_referencia', 'secao'],
                'orc_comp_analitico_sicro3_secao_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('orcamento_composicao_analitico_items', function (Blueprint $table): void {
            $table->dropIndex('orc_comp_analitico_sicro3_secao_idx');
            $table->dropColumn([
                'descricao_composicao',
                'unidade_composicao',
                'producao_equipe',
                'fator_influencia_chuvas',
                'secao',
                'tipo_transporte',
                'codigo_item_referenciado',
                'utilizacao_operativa',
                'utilizacao_improdutiva',
            ]);
        });
    }
};

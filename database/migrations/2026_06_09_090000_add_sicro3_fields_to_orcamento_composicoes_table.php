<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_composicoes', function (Blueprint $table): void {
            if (! Schema::hasColumn('orcamento_composicoes', 'producao_equipe')) {
                $table->decimal('producao_equipe', 18, 6)->nullable()->after('metodo_calculo');
            }

            if (! Schema::hasColumn('orcamento_composicoes', 'adicional_mao_obra')) {
                $table->decimal('adicional_mao_obra', 18, 6)->nullable()->after('producao_equipe');
            }

            if (! Schema::hasColumn('orcamento_composicoes', 'fator_influencia_chuvas')) {
                $table->decimal('fator_influencia_chuvas', 18, 6)->nullable()->after('adicional_mao_obra');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orcamento_composicoes', function (Blueprint $table): void {
            foreach (['fator_influencia_chuvas', 'adicional_mao_obra', 'producao_equipe'] as $column) {
                if (Schema::hasColumn('orcamento_composicoes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

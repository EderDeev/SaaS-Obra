<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table) {
            $table->renameColumn('prazo_acao_corretiva', 'prazo_resposta_acao_corretiva');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table) {
            $table->renameColumn('prazo_resposta_acao_corretiva', 'prazo_acao_corretiva');
        });
    }
};

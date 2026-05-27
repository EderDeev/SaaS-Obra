<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorio_nao_conformidade_acoes_corretivas', function (Blueprint $table) {
            $table->date('prazo_execucao_proposto')->nullable()->after('descricao_proposta');
        });
    }

    public function down(): void
    {
        Schema::table('relatorio_nao_conformidade_acoes_corretivas', function (Blueprint $table) {
            $table->dropColumn('prazo_execucao_proposto');
        });
    }
};

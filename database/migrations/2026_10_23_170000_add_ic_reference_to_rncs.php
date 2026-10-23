<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table) {
            $table->foreignId('instrucao_campo_id')->nullable()->constrained('instrucoes_campo');
        });
    }

    public function down(): void
    {
        Schema::table('relatorio_nao_conformidades', fn (Blueprint $table) => $table->dropConstrainedForeignId('instrucao_campo_id'));
    }
};

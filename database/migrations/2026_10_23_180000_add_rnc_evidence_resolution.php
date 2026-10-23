<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorio_nao_conformidade_evidencias', function (Blueprint $table) {
            $table->string('resolution_result', 16)->nullable();
            $table->text('resolution_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('relatorio_nao_conformidade_evidencias', fn (Blueprint $table) => $table->dropColumn(['resolution_result', 'resolution_reason']));
    }
};

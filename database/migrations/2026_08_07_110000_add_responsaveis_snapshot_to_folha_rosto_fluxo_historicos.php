<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folha_rosto_fluxo_historicos', function (Blueprint $table): void {
            $table->json('responsaveis_snapshot')->nullable()->after('motivo');
        });
    }

    public function down(): void
    {
        Schema::table('folha_rosto_fluxo_historicos', function (Blueprint $table): void {
            $table->dropColumn('responsaveis_snapshot');
        });
    }
};

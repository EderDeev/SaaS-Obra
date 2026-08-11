<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordem_servicos', function (Blueprint $table): void {
            $table->date('prazo_inicio')->nullable()->after('descricao');
            $table->date('prazo_finalizacao')->nullable()->after('prazo_inicio');
        });

        DB::table('ordem_servicos')
            ->whereNotNull('prazo_execucao')
            ->whereNull('prazo_finalizacao')
            ->update(['prazo_finalizacao' => DB::raw('prazo_execucao')]);
    }

    public function down(): void
    {
        Schema::table('ordem_servicos', function (Blueprint $table): void {
            $table->dropColumn(['prazo_inicio', 'prazo_finalizacao']);
        });
    }
};

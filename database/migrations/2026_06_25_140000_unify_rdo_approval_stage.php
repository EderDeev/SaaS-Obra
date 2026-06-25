<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rdo_diarios')
            ->whereIn('status', ['em_analise_gerenciadora', 'em_analise_cliente'])
            ->update(['status' => 'em_aprovacao']);

        DB::table('rdo_analises')
            ->whereIn('status_novo', ['em_analise_gerenciadora', 'em_analise_cliente'])
            ->update(['status_novo' => 'em_aprovacao']);
    }

    public function down(): void
    {
        DB::table('rdo_diarios')
            ->where('status', 'em_aprovacao')
            ->update(['status' => 'em_analise_gerenciadora']);

        DB::table('rdo_analises')
            ->where('status_novo', 'em_aprovacao')
            ->update(['status_novo' => 'em_analise_gerenciadora']);
    }
};

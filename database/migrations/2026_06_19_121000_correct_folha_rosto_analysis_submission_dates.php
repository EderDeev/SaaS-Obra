<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('folhas_rosto')
            ->whereIn('status', ['analise_fiscal', 'analise_qualidade', 'analise_medicao', 'analisada'])
            ->whereColumn('submitted_for_analysis_at', 'created_at')
            ->whereColumn('updated_at', '>', 'created_at')
            ->update([
                'submitted_for_analysis_at' => DB::raw('updated_at'),
            ]);
    }

    public function down(): void
    {
        // Correção de dados sem reversão segura.
    }
};

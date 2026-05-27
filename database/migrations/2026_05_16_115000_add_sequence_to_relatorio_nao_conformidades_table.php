<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table) {
            $table->unsignedInteger('sequence_number')->nullable()->after('id');
            $table->unsignedSmallInteger('sequence_year')->nullable()->after('sequence_number');
        });

        $counters = [];
        $rncs = DB::table('relatorio_nao_conformidades')
            ->select(['id', 'tenant_id', 'opened_at', 'created_at'])
            ->orderBy('tenant_id')
            ->orderBy('opened_at')
            ->orderBy('id')
            ->get();

        foreach ($rncs as $rnc) {
            $year = (int) substr((string) ($rnc->opened_at ?: $rnc->created_at), 0, 4);

            if ($year < 1) {
                $year = (int) now()->format('Y');
            }

            $key = $rnc->tenant_id.'-'.$year;
            $counters[$key] = ($counters[$key] ?? 0) + 1;

            DB::table('relatorio_nao_conformidades')
                ->where('id', $rnc->id)
                ->update([
                    'sequence_number' => $counters[$key],
                    'sequence_year' => $year,
                ]);
        }

        Schema::table('relatorio_nao_conformidades', function (Blueprint $table) {
            $table->unique(['tenant_id', 'sequence_year', 'sequence_number'], 'rnc_tenant_year_sequence_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table) {
            $table->dropUnique('rnc_tenant_year_sequence_unique');
            $table->dropColumn(['sequence_number', 'sequence_year']);
        });
    }
};

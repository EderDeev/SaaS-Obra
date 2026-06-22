<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folhas_rosto', function (Blueprint $table): void {
            $table->timestamp('submitted_for_analysis_at')->nullable()->after('status');
        });

        DB::table('folhas_rosto')
            ->whereIn('status', ['analise_fiscal', 'analise_qualidade', 'analise_medicao', 'analisada'])
            ->whereNull('submitted_for_analysis_at')
            ->update([
                'submitted_for_analysis_at' => DB::raw('updated_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('folhas_rosto', function (Blueprint $table): void {
            $table->dropColumn('submitted_for_analysis_at');
        });
    }
};

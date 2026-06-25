<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rdo_secao_registros', function (Blueprint $table) {
            $table->foreignId('obra_id')->nullable()->after('rdo_diario_id')->constrained('obras')->cascadeOnDelete();
            $table->dropUnique('rdo_diario_secao_unique');
        });

        DB::table('rdo_secao_registros')
            ->select(['id', 'rdo_diario_id'])
            ->orderBy('id')
            ->each(function (object $section): void {
                $obraId = DB::table('rdo_diarios')->where('id', $section->rdo_diario_id)->value('obra_id');
                DB::table('rdo_secao_registros')->where('id', $section->id)->update(['obra_id' => $obraId]);
            });

        Schema::table('rdo_secao_registros', function (Blueprint $table) {
            $table->unique(['rdo_diario_id', 'obra_id', 'secao'], 'rdo_diario_obra_secao_unique');
            $table->index(['obra_id', 'secao']);
        });
    }

    public function down(): void
    {
        Schema::table('rdo_secao_registros', function (Blueprint $table) {
            $table->dropUnique('rdo_diario_obra_secao_unique');
            $table->dropIndex(['obra_id', 'secao']);
            $table->dropConstrainedForeignId('obra_id');
            $table->unique(['rdo_diario_id', 'secao'], 'rdo_diario_secao_unique');
        });
    }
};

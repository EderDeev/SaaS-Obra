<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rdo_secao_registros', function (Blueprint $table) {
            $table->string('filling_party', 20)->default('construtora');
            $table->dropUnique('rdo_diario_obra_secao_unique');
            $table->unique(['rdo_diario_id', 'obra_id', 'secao', 'filling_party'], 'rdo_front_section_party_unique');
        });
        // Preserve the owner of records created with the earlier single-filler setting.
        DB::table('rdo_diarios')->whereNotNull('workflow_snapshot')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $workflow = json_decode($row->workflow_snapshot, true);
                $party = $workflow['fill_party'] ?? $workflow['fill_parties'][0] ?? 'construtora';
                DB::table('rdo_secao_registros')->where('rdo_diario_id', $row->id)->update(['filling_party' => $party]);
            }
        });
    }

    public function down(): void
    {
        // Consolidating independent records could discard one party's work.
        throw new RuntimeException('Use a forward migration to preserve independent RDO records.');
    }
};

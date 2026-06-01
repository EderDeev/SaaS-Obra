<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table): void {
            $table->foreignId('disciplina_id')->nullable()->after('project_document_id');
            $table->foreign('disciplina_id', 'rnc_disciplina_fk')
                ->references('id')
                ->on('disciplinas')
                ->nullOnDelete();
            $table->index(['tenant_id', 'disciplina_id'], 'rnc_tenant_disciplina_idx');
        });
    }

    public function down(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table): void {
            $table->dropForeign('rnc_disciplina_fk');
            $table->dropIndex('rnc_tenant_disciplina_idx');
            $table->dropColumn('disciplina_id');
        });
    }
};

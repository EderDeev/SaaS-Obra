<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table): void {
            $table->foreignId('project_document_id')->nullable()->after('obra_id');
            $table->foreign('project_document_id', 'rnc_project_document_fk')
                ->references('id')
                ->on('project_documents')
                ->nullOnDelete();
            $table->index(['tenant_id', 'project_document_id'], 'rnc_tenant_project_idx');
        });
    }

    public function down(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table): void {
            $table->dropForeign('rnc_project_document_fk');
            $table->dropIndex('rnc_tenant_project_idx');
            $table->dropColumn('project_document_id');
        });
    }
};

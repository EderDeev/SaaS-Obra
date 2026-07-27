<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rnc_project_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('relatorio_nao_conformidade_id');
            $table->unsignedBigInteger('project_document_id');
            $table->timestamps();

            $table->foreign('relatorio_nao_conformidade_id', 'rnc_projects_rnc_fk')
                ->references('id')
                ->on('relatorio_nao_conformidades')
                ->cascadeOnDelete();
            $table->foreign('project_document_id', 'rnc_projects_project_fk')
                ->references('id')
                ->on('project_documents')
                ->cascadeOnDelete();
            $table->unique(
                ['relatorio_nao_conformidade_id', 'project_document_id'],
                'rnc_project_documents_unique'
            );
            $table->index('project_document_id', 'rnc_project_documents_project_idx');
        });

        DB::table('rnc_project_documents')->insertUsing(
            ['relatorio_nao_conformidade_id', 'project_document_id', 'created_at', 'updated_at'],
            DB::table('relatorio_nao_conformidades')
                ->select([
                    'id',
                    'project_document_id',
                    'created_at',
                    'updated_at',
                ])
                ->whereNotNull('project_document_id')
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('rnc_project_documents');
    }
};

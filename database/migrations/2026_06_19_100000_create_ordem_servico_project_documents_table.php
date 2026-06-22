<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordem_servico_project_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_servico_id')->constrained('ordem_servicos')->cascadeOnDelete();
            $table->foreignId('project_document_id')->constrained('project_documents')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ordem_servico_id', 'project_document_id'], 'os_project_documents_unique');
            $table->index('project_document_id', 'os_project_documents_project_idx');
        });

        DB::table('ordem_servico_project_documents')->insertUsing(
            ['ordem_servico_id', 'project_document_id', 'created_at', 'updated_at'],
            DB::table('ordem_servicos')
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
        Schema::dropIfExists('ordem_servico_project_documents');
    }
};

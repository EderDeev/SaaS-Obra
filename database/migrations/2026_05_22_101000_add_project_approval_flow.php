<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_discipline_responsaveis', function (Blueprint $table): void {
            $table->dropUnique('project_discipline_responsaveis_unique');
            $table->string('tipo', 20)->default('analise')->after('user_id');
            $table->unique(['contract_id', 'disciplina_id', 'user_id', 'tipo'], 'project_discipline_responsaveis_unique_tipo');
            $table->index(['tenant_id', 'tipo', 'status'], 'project_discipline_responsaveis_tipo_status_index');
        });

        Schema::table('project_documents', function (Blueprint $table): void {
            $table->foreignId('approved_by_id')->nullable()->after('reviewed_by_id')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('review_notes');
            $table->text('approval_notes')->nullable()->after('approved_at');
            $table->index(['tenant_id', 'status', 'approved_at'], 'project_documents_tenant_status_approved_index');
        });
    }

    public function down(): void
    {
        Schema::table('project_documents', function (Blueprint $table): void {
            $table->dropIndex('project_documents_tenant_status_approved_index');
            $table->dropForeign(['approved_by_id']);
            $table->dropColumn(['approved_by_id', 'approved_at', 'approval_notes']);
        });

        Schema::table('project_discipline_responsaveis', function (Blueprint $table): void {
            $table->dropIndex('project_discipline_responsaveis_tipo_status_index');
            $table->dropUnique('project_discipline_responsaveis_unique_tipo');
            $table->dropColumn('tipo');
            $table->unique(['contract_id', 'disciplina_id', 'user_id'], 'project_discipline_responsaveis_unique');
        });
    }
};

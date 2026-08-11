<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordem_servico_contract_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->boolean('require_project')->default(false);
            $table->boolean('require_document')->default(false);
            $table->boolean('require_deadline')->default(false);
            $table->boolean('require_execution_responsible')->default(false);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'contract_id'], 'os_contract_settings_unique');
        });

        Schema::table('ordem_servicos', function (Blueprint $table): void {
            $table->timestamp('execution_started_at')->nullable()->after('approval_observation');
            $table->foreignId('execution_started_by_id')->nullable()->after('execution_started_at')->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable()->after('execution_started_by_id');
            $table->foreignId('completed_by_id')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->text('completion_summary')->nullable()->after('completed_by_id');
            $table->timestamp('cancelled_at')->nullable()->after('completion_summary');
            $table->foreignId('cancelled_by_id')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by_id');
        });

        Schema::create('ordem_servico_comentarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ordem_servico_id')->constrained('ordem_servicos')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('ordem_servico_comentarios')->cascadeOnDelete();
            $table->string('tipo', 20)->default('comentario');
            $table->text('body');
            $table->string('status', 20)->default('aberta');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ordem_servico_id', 'tipo', 'status'], 'os_comments_lookup');
        });

        Schema::create('ordem_servico_comentario_mencoes', function (Blueprint $table): void {
            $table->foreignId('comentario_id')->constrained('ordem_servico_comentarios')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['comentario_id', 'user_id'], 'os_comment_mentions_primary');
        });

        Schema::table('ordem_servico_documentos', function (Blueprint $table): void {
            $table->string('categoria', 20)->default('execucao')->after('uploaded_by_id');
            $table->foreignId('comentario_id')->nullable()->after('categoria')->constrained('ordem_servico_comentarios')->cascadeOnDelete();
            $table->index(['ordem_servico_id', 'categoria'], 'os_documents_category_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('ordem_servico_documentos', function (Blueprint $table): void {
            $table->dropIndex('os_documents_category_lookup');
            $table->dropConstrainedForeignId('comentario_id');
            $table->dropColumn('categoria');
        });

        Schema::dropIfExists('ordem_servico_comentario_mencoes');
        Schema::dropIfExists('ordem_servico_comentarios');

        Schema::table('ordem_servicos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropConstrainedForeignId('completed_by_id');
            $table->dropConstrainedForeignId('execution_started_by_id');
            $table->dropColumn([
                'execution_started_at',
                'completed_at',
                'completion_summary',
                'cancelled_at',
                'cancellation_reason',
            ]);
        });

        Schema::dropIfExists('ordem_servico_contract_settings');
    }
};

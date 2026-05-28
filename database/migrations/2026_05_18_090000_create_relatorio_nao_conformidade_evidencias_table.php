<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table) {
            $table->timestamp('finalized_at')->nullable()->after('notified_at');
            $table->foreignId('finalized_by_id')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('relatorio_nao_conformidade_evidencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('relatorio_nao_conformidade_id');
            $table->foreignId('relatorio_nao_conformidade_acao_corretiva_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('attachment_path');
            $table->string('attachment_original_name');
            $table->string('attachment_mime_type', 120)->nullable();
            $table->unsignedBigInteger('attachment_size')->default(0);
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->foreign('relatorio_nao_conformidade_id', 'rnc_evidencias_rnc_id_fk')
                ->references('id')
                ->on('relatorio_nao_conformidades')
                ->cascadeOnDelete();
            $table->foreign('relatorio_nao_conformidade_acao_corretiva_id', 'rnc_evidencias_acao_id_fk')
                ->references('id')
                ->on('relatorio_nao_conformidade_acoes_corretivas')
                ->cascadeOnDelete();
            $table->index(['tenant_id', 'relatorio_nao_conformidade_id']);
        });

        Schema::create('relatorio_nao_conformidade_evidencia_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('relatorio_nao_conformidade_evidencia_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();

            $table->foreign('relatorio_nao_conformidade_evidencia_id', 'rnc_evidencia_photos_evidencia_id_fk')
                ->references('id')
                ->on('relatorio_nao_conformidade_evidencias')
                ->cascadeOnDelete();
            $table->index(['tenant_id', 'relatorio_nao_conformidade_evidencia_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relatorio_nao_conformidade_evidencia_photos');
        Schema::dropIfExists('relatorio_nao_conformidade_evidencias');

        Schema::table('relatorio_nao_conformidades', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finalized_by_id');
            $table->dropColumn('finalized_at');
        });
    }
};

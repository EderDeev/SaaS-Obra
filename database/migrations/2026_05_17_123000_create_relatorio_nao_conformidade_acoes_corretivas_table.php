<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relatorio_nao_conformidade_acoes_corretivas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('relatorio_nao_conformidade_id')->constrained('relatorio_nao_conformidades')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('descricao_proposta');
            $table->string('attachment_path');
            $table->string('attachment_original_name');
            $table->string('attachment_mime_type', 120)->nullable();
            $table->unsignedBigInteger('attachment_size')->default(0);
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['tenant_id', 'relatorio_nao_conformidade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relatorio_nao_conformidade_acoes_corretivas');
    }
};

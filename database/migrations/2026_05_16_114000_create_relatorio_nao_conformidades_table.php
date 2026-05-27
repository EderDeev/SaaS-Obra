<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('relatorio_nao_conformidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('obra_id')->constrained('obras')->restrictOnDelete();
            $table->foreignId('contratante_empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('contratada_empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('opened_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('natureza', 30);
            $table->string('gravidade', 20);
            $table->text('descricao_problema');
            $table->text('observacao')->nullable();
            $table->text('acoes_corretivas_recomendadas');
            $table->date('prazo_acao_corretiva');
            $table->string('status', 20)->default('aberta');
            $table->timestamps();

            $table->index(['tenant_id', 'contract_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'opened_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('relatorio_nao_conformidades');
    }
};

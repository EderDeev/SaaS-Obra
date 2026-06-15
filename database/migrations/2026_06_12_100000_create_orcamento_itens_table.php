<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orcamento_itens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('orcamento_id')->constrained('orcamentos')->cascadeOnDelete();
            $table->foreignId('orcamento_etapa_id')->constrained('orcamento_etapas')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('item_type', 30)->default('composicao');
            $table->foreignId('orcamento_composicao_id')->nullable()->constrained('orcamento_composicoes')->nullOnDelete();
            $table->foreignId('orcamento_insumo_id')->nullable()->constrained('orcamento_insumos')->nullOnDelete();
            $table->unsignedInteger('ordem')->default(1);
            $table->string('codigo', 80);
            $table->string('banco', 40)->nullable();
            $table->text('descricao');
            $table->string('unidade', 30)->nullable();
            $table->decimal('quantidade', 18, 6)->default(1);
            $table->decimal('valor_unitario_nao_desonerado', 18, 6)->default(0);
            $table->decimal('valor_unitario_desonerado', 18, 6)->default(0);
            $table->decimal('valor_com_bdi_nao_desonerado', 18, 6)->default(0);
            $table->decimal('valor_com_bdi_desonerado', 18, 6)->default(0);
            $table->decimal('valor_total_nao_desonerado', 18, 6)->default(0);
            $table->decimal('valor_total_desonerado', 18, 6)->default(0);
            $table->boolean('aplicar_bdi')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'orcamento_id', 'orcamento_etapa_id', 'ordem'], 'orcamento_itens_context_order_idx');
            $table->index(['orcamento_composicao_id', 'item_type'], 'orcamento_itens_composicao_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamento_itens');
    }
};

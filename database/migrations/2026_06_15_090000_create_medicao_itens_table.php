<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicao_itens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type', 30)->default('manual');
            $table->foreignId('source_orcamento_id')->nullable()->constrained('orcamentos')->nullOnDelete();
            $table->foreignId('source_orcamento_etapa_id')->nullable()->constrained('orcamento_etapas')->nullOnDelete();
            $table->foreignId('source_orcamento_item_id')->nullable()->constrained('orcamento_itens')->nullOnDelete();
            $table->string('item', 40)->nullable();
            $table->unsignedSmallInteger('nivel')->default(1);
            $table->string('item_type', 30)->default('manual');
            $table->string('codigo', 80)->nullable();
            $table->string('banco', 40)->nullable();
            $table->text('descricao');
            $table->string('unidade', 30)->nullable();
            $table->decimal('quantidade_prevista', 18, 6)->default(0);
            $table->decimal('valor_unitario', 18, 6)->default(0);
            $table->decimal('valor_com_bdi', 18, 6)->default(0);
            $table->decimal('valor_total', 18, 6)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contract_id', 'item']);
            $table->index(['tenant_id', 'contract_id', 'source_type']);
            $table->index(['source_orcamento_id', 'source_orcamento_item_id'], 'medicao_itens_orcamento_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicao_itens');
    }
};

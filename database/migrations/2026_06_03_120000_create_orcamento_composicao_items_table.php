<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orcamento_composicao_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('orcamento_composicao_id')->constrained('orcamento_composicoes')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('item_type', 20);
            $table->string('sicro3_section', 40)->nullable();
            $table->foreignId('orcamento_insumo_id')->nullable()->constrained('orcamento_insumos')->nullOnDelete();
            $table->foreignId('child_composicao_id')->nullable()->constrained('orcamento_composicoes')->nullOnDelete();
            $table->string('base', 80)->nullable();
            $table->string('codigo', 50);
            $table->text('descricao');
            $table->string('tipo', 80)->nullable();
            $table->string('unidade', 20);
            $table->decimal('preco_unitario_onerado', 18, 6)->default(0);
            $table->decimal('preco_unitario_desonerado', 18, 6)->default(0);
            $table->decimal('coeficiente', 15, 6)->default(1);
            $table->decimal('preco_onerado', 18, 6)->default(0);
            $table->decimal('preco_desonerado', 18, 6)->default(0);
            $table->text('observacao')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'orcamento_composicao_id']);
            $table->index(['item_type', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamento_composicao_items');
    }
};

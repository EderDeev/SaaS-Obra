<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orcamento_insumos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('banco', 30)->default('SINAPI');
            $table->string('tipo', 30)->nullable();
            $table->string('codigo_insumo', 50);
            $table->text('descricao');
            $table->string('unidade', 20);
            $table->string('uf', 2);
            $table->string('origem_preco', 30)->nullable();
            $table->decimal('preco_nao_desonerado', 15, 2)->nullable();
            $table->decimal('preco_desonerado', 15, 2)->nullable();
            $table->date('data_referencia');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'banco', 'uf', 'data_referencia']);
            $table->index(['codigo_insumo', 'uf', 'data_referencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamento_insumos');
    }
};

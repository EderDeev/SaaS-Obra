<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folhas_rosto', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('obra_id')->constrained('obras')->cascadeOnDelete();
            $table->foreignId('ordem_servico_id')->constrained('ordem_servicos')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('codigo', 100);
            $table->unsignedInteger('sequencial');
            $table->text('comentario');
            $table->string('status', 30)->default('aberta');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'codigo']);
            $table->unique(['ordem_servico_id', 'sequencial']);
            $table->index(['tenant_id', 'contract_id', 'obra_id', 'status'], 'folhas_rosto_scope_idx');
        });

        Schema::create('folha_rosto_itens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('folha_rosto_id')->constrained('folhas_rosto')->cascadeOnDelete();
            $table->foreignId('ordem_servico_item_id')->constrained('ordem_servico_itens')->cascadeOnDelete();
            $table->decimal('quantidade_pleiteada', 18, 6);
            $table->decimal('valor_pleiteado', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['folha_rosto_id', 'ordem_servico_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folha_rosto_itens');
        Schema::dropIfExists('folhas_rosto');
    }
};

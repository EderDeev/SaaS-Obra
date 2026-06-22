<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordem_servicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('obra_id')->nullable()->constrained('obras')->nullOnDelete();
            $table->foreignId('project_document_id')->nullable()->constrained('project_documents')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('codigo', 50);
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->date('prazo_execucao')->nullable();
            $table->decimal('custo_previsto', 15, 2)->default(0);
            $table->text('custo_observacao')->nullable();
            $table->string('status', 30)->default('rascunho');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'codigo']);
            $table->index(['tenant_id', 'contract_id', 'status']);
        });

        Schema::create('ordem_servico_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_servico_id')->constrained('ordem_servicos')->cascadeOnDelete();
            $table->foreignId('medicao_item_id')->constrained('medicao_itens')->cascadeOnDelete();
            $table->decimal('quantidade_solicitada', 15, 6)->nullable();
            $table->decimal('valor_previsto', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['ordem_servico_id', 'medicao_item_id']);
        });

        Schema::create('ordem_servico_responsaveis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_servico_id')->constrained('ordem_servicos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('papel', 50)->default('responsavel');
            $table->timestamps();

            $table->unique(['ordem_servico_id', 'user_id']);
        });

        Schema::create('ordem_servico_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_servico_id')->constrained('ordem_servicos')->cascadeOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nome_original');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordem_servico_documentos');
        Schema::dropIfExists('ordem_servico_responsaveis');
        Schema::dropIfExists('ordem_servico_itens');
        Schema::dropIfExists('ordem_servicos');
    }
};

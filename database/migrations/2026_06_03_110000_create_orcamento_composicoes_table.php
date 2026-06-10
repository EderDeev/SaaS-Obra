<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orcamento_composicoes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('codigo', 50);
            $table->text('descricao');
            $table->string('tipo_composicao', 120);
            $table->string('unidade', 20);
            $table->string('uf', 2);
            $table->string('modelo', 30)->default('SINAPI');
            $table->string('metodo_calculo', 50);
            $table->text('observacao')->nullable();
            $table->json('base_references')->nullable();
            $table->decimal('preco_onerado', 18, 6)->default(0);
            $table->decimal('preco_desonerado', 18, 6)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'codigo']);
            $table->index(['tenant_id', 'uf', 'modelo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamento_composicoes');
    }
};

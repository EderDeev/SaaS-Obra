<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orcamento_composicao_analitico_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_global')->default(false);
            $table->string('modelo', 30)->default('SINAPI');
            $table->string('grupo', 120)->nullable();
            $table->string('codigo_composicao', 50);
            $table->string('tipo_item', 20);
            $table->string('codigo_item', 50);
            $table->text('descricao_item')->nullable();
            $table->string('unidade', 20)->nullable();
            $table->string('uf', 2)->nullable();
            $table->date('data_referencia')->nullable();
            $table->decimal('coeficiente', 15, 6)->default(1);
            $table->json('raw_payload')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'modelo', 'codigo_composicao'], 'orc_comp_analitico_tenant_comp_idx');
            $table->index(['is_global', 'modelo', 'codigo_composicao'], 'orc_comp_analitico_global_comp_idx');
            $table->index(['modelo', 'tipo_item', 'codigo_item'], 'orc_comp_analitico_item_idx');
            $table->index(['modelo', 'uf', 'data_referencia'], 'orc_comp_analitico_base_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamento_composicao_analitico_items');
    }
};

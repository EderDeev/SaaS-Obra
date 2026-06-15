<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orcamentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cliente_empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->string('codigo', 50);
            $table->string('descricao');
            $table->string('categoria', 160);
            $table->timestamp('prazo_entrega_at')->nullable();
            $table->boolean('permitir_insumos_preco_zerado')->default(false);
            $table->boolean('is_licitacao')->default(false);
            $table->string('licitacao_tipo', 120)->nullable();
            $table->timestamp('licitacao_abertura_at')->nullable();
            $table->string('licitacao_processo', 120)->nullable();
            $table->string('arredondamento', 60)->default('truncate_all_2');
            $table->string('encargos_sociais', 30)->default('desonerado');
            $table->string('bdi_tipo', 60)->default('unit_price');
            $table->decimal('bdi_percentual', 10, 6)->default(0);
            $table->json('base_references')->nullable();
            $table->string('status', 40)->default('draft');
            $table->decimal('valor_nao_desonerado', 18, 6)->default(0);
            $table->decimal('valor_desonerado', 18, 6)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'codigo']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'categoria']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamentos');
    }
};

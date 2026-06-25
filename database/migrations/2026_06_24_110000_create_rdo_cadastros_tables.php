<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rdo_mao_obra_cadastros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('descricao');
            $table->string('tipo', 20);
            $table->string('unidade', 30)->default('pessoa');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'descricao', 'tipo'], 'rdo_mao_obra_tenant_descricao_tipo_unique');
            $table->index(['tenant_id', 'active', 'tipo']);
        });

        Schema::create('rdo_equipamento_cadastros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('codigo', 50)->nullable();
            $table->string('descricao');
            $table->string('unidade', 30)->default('unidade');
            $table->string('propriedade', 30)->default('proprio');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'codigo'], 'rdo_equipamento_tenant_codigo_unique');
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('rdo_subcontratada_cadastros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('razao_social');
            $table->string('nome_fantasia')->nullable();
            $table->string('cnpj', 18)->nullable();
            $table->string('responsavel')->nullable();
            $table->string('telefone', 30)->nullable();
            $table->string('email')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'cnpj'], 'rdo_subcontratada_tenant_cnpj_unique');
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rdo_subcontratada_cadastros');
        Schema::dropIfExists('rdo_equipamento_cadastros');
        Schema::dropIfExists('rdo_mao_obra_cadastros');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicao_indice_reajuste_competencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('medicao_indice_reajuste_id')->constrained('medicao_indices_reajuste')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('competencia');
            $table->decimal('valor_indice', 18, 6);
            $table->date('data_publicacao')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['medicao_indice_reajuste_id', 'competencia'], 'medicao_indice_competencia_unique');
            $table->index(['tenant_id', 'contract_id', 'competencia'], 'medicao_indice_competencia_contract_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicao_indice_reajuste_competencias');
    }
};

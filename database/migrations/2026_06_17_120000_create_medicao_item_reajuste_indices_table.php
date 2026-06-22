<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicao_item_reajuste_indices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('medicao_item_id')->constrained('medicao_itens')->cascadeOnDelete();
            $table->foreignId('medicao_indice_reajuste_id')->constrained('medicao_indices_reajuste')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('item_codigo', 80)->nullable();
            $table->string('indice_codigo', 60)->nullable();
            $table->string('source_type', 30)->default('manual');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contract_id']);
            $table->index('medicao_item_id');
            $table->index('medicao_indice_reajuste_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicao_item_reajuste_indices');
    }
};

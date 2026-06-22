<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicao_indices_reajuste', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nome');
            $table->string('codigo', 60)->nullable();
            $table->decimal('indice_base', 18, 6);
            $table->date('data_base');
            $table->decimal('indice_atual', 18, 6);
            $table->date('data_atual');
            $table->text('observacao')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contract_id']);
            $table->index(['tenant_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicao_indices_reajuste');
    }
};

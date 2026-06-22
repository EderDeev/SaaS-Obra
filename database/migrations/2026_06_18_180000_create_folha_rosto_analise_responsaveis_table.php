<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folha_rosto_analise_responsaveis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('obra_id')->constrained('obras')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('etapa', 30);
            $table->string('status', 30)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'contract_id', 'obra_id', 'user_id', 'etapa'], 'fr_analise_resp_unique');
            $table->index(['tenant_id', 'contract_id', 'obra_id', 'etapa', 'status'], 'fr_analise_resp_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folha_rosto_analise_responsaveis');
    }
};

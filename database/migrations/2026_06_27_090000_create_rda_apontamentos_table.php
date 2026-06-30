<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rda_apontamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rdo_configuracao_id')->constrained('rdo_configuracoes')->cascadeOnDelete();
            $table->foreignId('rdo_diario_id')->nullable()->constrained('rdo_diarios')->nullOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('obra_id')->constrained('obras')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('reference_date');
            $table->string('status', 30)->default('rascunho');
            $table->json('dados')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'contract_id', 'obra_id', 'reference_date'], 'rda_tenant_contract_obra_date_unique');
            $table->index(['tenant_id', 'reference_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rda_apontamentos');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordem_servico_obra_responsaveis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('obra_id')->constrained('obras')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo', 30);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'obra_id', 'user_id', 'tipo'], 'os_obra_resp_unique');
            $table->index(['tenant_id', 'contract_id', 'obra_id', 'tipo'], 'os_obra_resp_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordem_servico_obra_responsaveis');
    }
};

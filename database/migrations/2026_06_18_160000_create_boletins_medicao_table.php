<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boletins_medicao', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('codigo', 30);
            $table->unsignedInteger('sequencial');
            $table->date('periodo');
            $table->string('tipo', 30);
            $table->string('status', 40)->default('aberto_lancamento');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'codigo']);
            $table->unique(['tenant_id', 'sequencial']);
            $table->index(['tenant_id', 'contract_id', 'periodo', 'status'], 'boletins_medicao_scope_idx');
        });

        Schema::table('folhas_rosto', function (Blueprint $table): void {
            $table->foreignId('boletim_medicao_id')
                ->nullable()
                ->after('ordem_servico_id')
                ->constrained('boletins_medicao')
                ->nullOnDelete();

            $table->index(['boletim_medicao_id', 'status'], 'folhas_rosto_boletim_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('folhas_rosto', function (Blueprint $table): void {
            $table->dropIndex('folhas_rosto_boletim_status_idx');
            $table->dropConstrainedForeignId('boletim_medicao_id');
        });

        Schema::dropIfExists('boletins_medicao');
    }
};

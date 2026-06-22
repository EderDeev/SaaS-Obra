<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folha_rosto_analise_responsaveis', function (Blueprint $table): void {
            $table->dropUnique('fr_analise_resp_unique');
            $table->dropIndex('fr_analise_resp_scope_idx');
        });

        Schema::table('folha_rosto_analise_responsaveis', function (Blueprint $table): void {
            $table->foreignId('contract_id')->nullable()->change();
            $table->foreignId('obra_id')->nullable()->change();

            $table->unique(['tenant_id', 'user_id', 'etapa'], 'fr_analise_resp_tenant_user_etapa_unique');
            $table->index(['tenant_id', 'etapa', 'status'], 'fr_analise_resp_tenant_etapa_idx');
        });
    }

    public function down(): void
    {
        Schema::table('folha_rosto_analise_responsaveis', function (Blueprint $table): void {
            $table->dropUnique('fr_analise_resp_tenant_user_etapa_unique');
            $table->dropIndex('fr_analise_resp_tenant_etapa_idx');
        });

        Schema::table('folha_rosto_analise_responsaveis', function (Blueprint $table): void {
            $table->foreignId('contract_id')->nullable(false)->change();
            $table->foreignId('obra_id')->nullable(false)->change();

            $table->unique(['tenant_id', 'contract_id', 'obra_id', 'user_id', 'etapa'], 'fr_analise_resp_unique');
            $table->index(['tenant_id', 'contract_id', 'obra_id', 'etapa', 'status'], 'fr_analise_resp_scope_idx');
        });
    }
};

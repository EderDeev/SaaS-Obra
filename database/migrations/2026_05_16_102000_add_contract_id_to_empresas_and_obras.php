<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropUnique('empresas_tenant_id_cnpj_unique');
            $table->foreignId('contract_id')->nullable()->after('tenant_id')->constrained()->cascadeOnDelete();
            $table->unique(['tenant_id', 'contract_id', 'cnpj']);
            $table->index(['tenant_id', 'contract_id']);
        });

        Schema::table('obras', function (Blueprint $table) {
            $table->dropUnique('obras_tenant_id_codigo_unique');
            $table->foreignId('contract_id')->nullable()->after('tenant_id')->constrained()->cascadeOnDelete();
            $table->unique(['tenant_id', 'contract_id', 'codigo']);
            $table->index(['tenant_id', 'contract_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('obras', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'contract_id', 'codigo']);
            $table->dropIndex(['tenant_id', 'contract_id']);
            $table->dropConstrainedForeignId('contract_id');
            $table->unique(['tenant_id', 'codigo']);
        });

        Schema::table('empresas', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'contract_id', 'cnpj']);
            $table->dropIndex(['tenant_id', 'contract_id']);
            $table->dropConstrainedForeignId('contract_id');
            $table->unique(['tenant_id', 'cnpj']);
        });
    }
};

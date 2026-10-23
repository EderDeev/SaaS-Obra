<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rnc_signature_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('contract_id')->unique()->constrained();
            $table->json('signers')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users');
            $table->timestamps();
        });
        Schema::table('rdo_signature_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('rdo_diario_id')->nullable()->change();
            $table->foreignId('relatorio_nao_conformidade_id')->nullable()->constrained('relatorio_nao_conformidades');
            $table->index(['tenant_id', 'relatorio_nao_conformidade_id'], 'signature_requests_tenant_rnc_index');
        });
    }

    public function down(): void
    {
        Schema::table('rdo_signature_requests', function (Blueprint $table) {
            $table->dropIndex('signature_requests_tenant_rnc_index');
            $table->dropConstrainedForeignId('relatorio_nao_conformidade_id');
        });
        Schema::dropIfExists('rnc_signature_settings');
    }
};

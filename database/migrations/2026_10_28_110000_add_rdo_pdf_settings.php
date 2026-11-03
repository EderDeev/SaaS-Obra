<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rdo_pdf_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->json('configuration');
            $table->timestamps();
            $table->unique(['tenant_id', 'contract_id']);
        });
        Schema::table('rdo_diarios', function (Blueprint $table) {
            $table->json('pdf_configuration_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migração somente de avanço; preserve os modelos dos documentos.');
    }
};

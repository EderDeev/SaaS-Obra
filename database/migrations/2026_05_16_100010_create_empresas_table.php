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
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tipo_empresa_id')->constrained('tipos_empresa')->restrictOnDelete();
            $table->string('nome');
            $table->string('cnpj', 20);
            $table->string('sigla', 20);
            $table->timestamps();

            $table->unique(['tenant_id', 'cnpj']);
            $table->index(['tenant_id', 'tipo_empresa_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};

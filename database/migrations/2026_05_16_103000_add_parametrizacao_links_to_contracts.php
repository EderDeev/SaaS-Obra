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
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('obra_id')->nullable()->after('tenant_id')->constrained('obras')->nullOnDelete();
            $table->foreignId('cliente_empresa_id')->nullable()->after('obra_id')->constrained('empresas')->nullOnDelete();
            $table->foreignId('construtora_empresa_id')->nullable()->after('cliente_empresa_id')->constrained('empresas')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('construtora_empresa_id');
            $table->dropConstrainedForeignId('cliente_empresa_id');
            $table->dropConstrainedForeignId('obra_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folhas_rosto', function (Blueprint $table): void {
            $table->foreignId('construtora_empresa_id')
                ->nullable()
                ->after('boletim_medicao_id')
                ->constrained('empresas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('folhas_rosto', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('construtora_empresa_id');
        });
    }
};

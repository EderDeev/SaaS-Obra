<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordem_servicos', function (Blueprint $table) {
            $table->foreignId('gerenciadora_empresa_id')
                ->nullable()
                ->after('project_document_id')
                ->constrained('empresas')
                ->nullOnDelete();

            $table->foreignId('construtora_empresa_id')
                ->nullable()
                ->after('gerenciadora_empresa_id')
                ->constrained('empresas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ordem_servicos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('construtora_empresa_id');
            $table->dropConstrainedForeignId('gerenciadora_empresa_id');
        });
    }
};

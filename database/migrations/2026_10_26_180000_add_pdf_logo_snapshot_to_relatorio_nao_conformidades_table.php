<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table) {
            $table->json('pdf_logo_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        // Logo history must survive rollback of application code.
    }
};

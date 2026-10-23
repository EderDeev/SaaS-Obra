<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorio_nao_conformidade_evidencias', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->change();
            $table->string('attachment_original_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Preserve evidence records submitted without a ZIP attachment.
    }
};

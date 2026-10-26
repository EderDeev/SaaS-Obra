<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rnc_pdf_settings', fn (Blueprint $table) => $table->json('field_definitions')->nullable());
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table) {
            $table->json('pdf_template_snapshot')->nullable();
            $table->json('model_field_values')->nullable();
        });
    }

    public function down(): void
    {
        // Historical document definitions and answers must not be discarded.
    }
};

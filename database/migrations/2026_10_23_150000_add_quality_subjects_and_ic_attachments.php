<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instrucoes_campo', fn (Blueprint $table) => $table->string('assunto')->nullable());
        Schema::table('relatorio_nao_conformidades', fn (Blueprint $table) => $table->string('assunto')->nullable());
        Schema::create('instrucao_campo_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('instrucao_campo_id')->constrained('instrucoes_campo');
            $table->foreignId('event_id')->constrained('instrucao_campo_events');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instrucao_campo_attachments');
        Schema::table('instrucoes_campo', fn (Blueprint $table) => $table->dropColumn('assunto'));
        Schema::table('relatorio_nao_conformidades', fn (Blueprint $table) => $table->dropColumn('assunto'));
    }
};

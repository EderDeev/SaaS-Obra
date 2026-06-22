<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folha_rosto_fluxo_historicos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('folha_rosto_id')->constrained('folhas_rosto')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status_origem', 40)->nullable();
            $table->string('status_destino', 40);
            $table->string('acao', 40);
            $table->text('motivo')->nullable();
            $table->timestamps();

            $table->index(['folha_rosto_id', 'created_at'], 'fr_fluxo_historico_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folha_rosto_fluxo_historicos');
    }
};

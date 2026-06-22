<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folha_rosto_analises', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('folha_rosto_id')->constrained('folhas_rosto')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('setor', 30);
            $table->text('comentario_geral')->nullable();
            $table->timestamps();

            $table->unique(['folha_rosto_id', 'setor']);
        });

        Schema::create('folha_rosto_item_analises', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('folha_rosto_item_id')->constrained('folha_rosto_itens')->cascadeOnDelete();
            $table->foreignId('folha_rosto_analise_id')->constrained('folha_rosto_analises')->cascadeOnDelete();
            $table->string('setor', 30);
            $table->decimal('quantidade_aprovada', 18, 6)->nullable();
            $table->text('comentario')->nullable();
            $table->timestamps();

            $table->unique(['folha_rosto_item_id', 'setor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folha_rosto_item_analises');
        Schema::dropIfExists('folha_rosto_analises');
    }
};

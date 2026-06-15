<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orcamento_etapas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('orcamento_id')->constrained('orcamentos')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('ordem');
            $table->string('descricao');
            $table->decimal('quantidade', 18, 6)->default(1);
            $table->decimal('valor_nao_desonerado', 18, 6)->default(0);
            $table->decimal('valor_desonerado', 18, 6)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'orcamento_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamento_etapas');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rdo_analises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rdo_diario_id')->constrained('rdo_diarios')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->string('etapa', 30);
            $table->string('decisao', 40);
            $table->text('comentario')->nullable();
            $table->string('status_anterior', 40);
            $table->string('status_novo', 40);
            $table->timestamps();

            $table->index(['tenant_id', 'rdo_diario_id', 'created_at']);
            $table->index(['rdo_diario_id', 'etapa', 'decisao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rdo_analises');
    }
};

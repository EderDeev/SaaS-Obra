<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orcamento_insumo_grupos')) {
            return;
        }

        Schema::create('orcamento_insumo_grupos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nome', 120);
            $table->text('descricao')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamento_insumo_grupos');
    }
};

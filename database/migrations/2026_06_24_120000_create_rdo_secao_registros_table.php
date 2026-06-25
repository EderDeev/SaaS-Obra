<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rdo_secao_registros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rdo_diario_id')->constrained('rdo_diarios')->cascadeOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('secao', 40);
            $table->json('dados');
            $table->timestamps();

            $table->unique(['rdo_diario_id', 'secao'], 'rdo_diario_secao_unique');
            $table->index(['tenant_id', 'secao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rdo_secao_registros');
    }
};

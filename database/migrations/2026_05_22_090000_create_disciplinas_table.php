<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('nome');
            $table->string('sigla', 20);
            $table->text('descricao')->nullable();
            $table->string('cor', 7)->default('#2563eb');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contract_id']);
            $table->index(['tenant_id', 'sigla']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinas');
    }
};

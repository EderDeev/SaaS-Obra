<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_participants', fn (Blueprint $table) => $table->json('os_dnit_permissions')->nullable());
        Schema::table('os_dnit_orders', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve as permissões e as OSs excluídas com uma migração corretiva para frente.');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', fn (Blueprint $table) => $table->json('navigation_permissions')->nullable());
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve as permissões de navegação com uma migração corretiva para frente.');
    }
};

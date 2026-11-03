<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rdo_configuracoes', fn (Blueprint $table) => $table->json('workflow')->nullable());
        Schema::table('rdo_diarios', fn (Blueprint $table) => $table->json('workflow_snapshot')->nullable());
    }

    public function down(): void
    {
        Schema::table('rdo_diarios', fn (Blueprint $table) => $table->dropColumn('workflow_snapshot'));
        Schema::table('rdo_configuracoes', fn (Blueprint $table) => $table->dropColumn('workflow'));
    }
};

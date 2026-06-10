<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_insumos', function (Blueprint $table): void {
            $table->string('uf', 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orcamento_insumos', function (Blueprint $table): void {
            $table->string('uf', 2)->nullable(false)->change();
        });
    }
};

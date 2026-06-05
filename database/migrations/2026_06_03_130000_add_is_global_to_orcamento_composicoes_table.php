<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_composicoes', function (Blueprint $table): void {
            $table->boolean('is_global')->default(false)->after('created_by_id');
            $table->index(['is_global', 'codigo', 'uf', 'modelo']);
        });
    }

    public function down(): void
    {
        Schema::table('orcamento_composicoes', function (Blueprint $table): void {
            $table->dropIndex(['is_global', 'codigo', 'uf', 'modelo']);
            $table->dropColumn('is_global');
        });
    }
};

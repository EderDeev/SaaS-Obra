<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_insumos', function (Blueprint $table): void {
            $table->string('classificacao', 80)->nullable()->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('orcamento_insumos', function (Blueprint $table): void {
            $table->dropColumn('classificacao');
        });
    }
};

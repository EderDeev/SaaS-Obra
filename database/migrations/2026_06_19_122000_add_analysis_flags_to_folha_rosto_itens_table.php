<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folha_rosto_itens', function (Blueprint $table): void {
            $table->boolean('precisa_analise_topografica')->default(false)->after('valor_pleiteado');
            $table->boolean('precisa_analise_qualidade')->default(false)->after('precisa_analise_topografica');
        });
    }

    public function down(): void
    {
        Schema::table('folha_rosto_itens', function (Blueprint $table): void {
            $table->dropColumn([
                'precisa_analise_topografica',
                'precisa_analise_qualidade',
            ]);
        });
    }
};

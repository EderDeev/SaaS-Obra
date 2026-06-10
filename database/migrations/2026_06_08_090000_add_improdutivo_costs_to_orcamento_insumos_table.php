<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_insumos', function (Blueprint $table): void {
            if (! Schema::hasColumn('orcamento_insumos', 'custo_improdutivo_nao_desonerado')) {
                $table->decimal('custo_improdutivo_nao_desonerado', 18, 6)->nullable();
            }

            if (! Schema::hasColumn('orcamento_insumos', 'custo_improdutivo_desonerado')) {
                $table->decimal('custo_improdutivo_desonerado', 18, 6)->nullable();
            }
        });
    }

    public function down(): void
    {
    }
};

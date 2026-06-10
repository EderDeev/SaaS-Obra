<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_insumos', function (Blueprint $table): void {
            if (! Schema::hasColumn('orcamento_insumos', 'observacao')) {
                $table->text('observacao')->nullable()->after('data_referencia');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orcamento_insumos', function (Blueprint $table): void {
            if (Schema::hasColumn('orcamento_insumos', 'observacao')) {
                $table->dropColumn('observacao');
            }
        });
    }
};

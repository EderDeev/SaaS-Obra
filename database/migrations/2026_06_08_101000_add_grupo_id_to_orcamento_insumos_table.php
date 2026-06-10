<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_insumos', function (Blueprint $table): void {
            if (! Schema::hasColumn('orcamento_insumos', 'grupo_id')) {
                $table->foreignId('grupo_id')->nullable()->constrained('orcamento_insumo_grupos')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orcamento_insumos', function (Blueprint $table): void {
            if (Schema::hasColumn('orcamento_insumos', 'grupo_id')) {
                $table->dropConstrainedForeignId('grupo_id');
            }
        });
    }
};

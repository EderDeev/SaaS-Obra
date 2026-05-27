<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        DB::table('tipos_empresa')->insertOrIgnore([
            'nome' => 'cliente',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('fiscalizadora_empresa_id')
                ->nullable()
                ->after('construtora_empresa_id')
                ->constrained('empresas')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fiscalizadora_empresa_id');
        });
    }
};

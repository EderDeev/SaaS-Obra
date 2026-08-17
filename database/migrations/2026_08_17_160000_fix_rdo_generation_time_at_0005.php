<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rdo_configuracoes')->update([
            'generation_time' => '00:05:00',
        ]);

        Schema::table('rdo_configuracoes', function (Blueprint $table): void {
            $table->time('generation_time')->default('00:05')->change();
        });
    }

    public function down(): void
    {
        Schema::table('rdo_configuracoes', function (Blueprint $table): void {
            $table->time('generation_time')->default('00:00')->change();
        });
    }
};

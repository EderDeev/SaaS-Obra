<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rdo_configuracoes', function (Blueprint $table) {
            $table->unsignedSmallInteger('submission_deadline_days')
                ->default(7)
                ->after('require_photos');
            $table->dropColumn('submission_deadline_time');
        });
    }

    public function down(): void
    {
        Schema::table('rdo_configuracoes', function (Blueprint $table) {
            $table->time('submission_deadline_time')
                ->nullable()
                ->after('require_photos');
            $table->dropColumn('submission_deadline_days');
        });
    }
};

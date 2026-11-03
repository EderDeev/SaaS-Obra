<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('rdo_diarios', function (Blueprint $table) {
            $table->boolean('is_holiday')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration: preserve the manual holiday classification of RDOs.');
    }
};

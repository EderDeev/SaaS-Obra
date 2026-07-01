<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rdo_configuracoes', function (Blueprint $table): void {
            $table->boolean('digital_signature_enabled')
                ->default(true)
                ->after('require_photos');
        });
    }

    public function down(): void
    {
        Schema::table('rdo_configuracoes', function (Blueprint $table): void {
            $table->dropColumn('digital_signature_enabled');
        });
    }
};

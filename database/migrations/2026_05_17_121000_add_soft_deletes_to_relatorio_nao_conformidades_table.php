<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table) {
            $table->foreignId('deleted_by_id')->nullable()->after('created_by_id')->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by_id');
            $table->dropSoftDeletes();
        });
    }
};

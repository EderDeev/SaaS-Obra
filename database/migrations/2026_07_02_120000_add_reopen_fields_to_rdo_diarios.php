<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rdo_diarios', function (Blueprint $table) {
            $table->timestamp('reopened_at')->nullable()->after('approved_at');
            $table->timestamp('reopened_until')->nullable()->after('reopened_at');
            $table->foreignId('reopened_by_id')->nullable()->after('reopened_until')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rdo_diarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reopened_by_id');
            $table->dropColumn(['reopened_at', 'reopened_until']);
        });
    }
};

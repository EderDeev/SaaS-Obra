<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->string('visibility', 20)->default('public')->after('category');
            $table->index(['tenant_id', 'contract_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'contract_id', 'visibility']);
            $table->dropColumn('visibility');
        });
    }
};

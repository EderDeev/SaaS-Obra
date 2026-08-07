<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_submission_batches', function (Blueprint $table): void {
            $table->string('cap_number', 120)->nullable()->change();
            $table->unsignedInteger('cap_sequence')->nullable()->change();
            $table->unsignedSmallInteger('cap_year')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_submission_batches', function (Blueprint $table): void {
            $table->string('cap_number', 120)->nullable(false)->change();
            $table->unsignedInteger('cap_sequence')->nullable(false)->change();
            $table->unsignedSmallInteger('cap_year')->nullable(false)->change();
        });
    }
};

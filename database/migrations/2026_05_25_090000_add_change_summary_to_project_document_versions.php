<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_document_versions', function (Blueprint $table): void {
            $table->text('revision_change_summary')->nullable()->after('revision');
        });
    }

    public function down(): void
    {
        Schema::table('project_document_versions', function (Blueprint $table): void {
            $table->dropColumn('revision_change_summary');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_document_versions', function (Blueprint $table): void {
            $table->string('stored_name')->nullable()->after('original_name');
        });

        DB::table('project_document_versions')
            ->whereNull('stored_name')
            ->update(['stored_name' => DB::raw('original_name')]);
    }

    public function down(): void
    {
        Schema::table('project_document_versions', function (Blueprint $table): void {
            $table->dropColumn('stored_name');
        });
    }
};

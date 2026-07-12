<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->string('base_document_path')->nullable()->after('status');
            $table->string('base_document_original_name')->nullable()->after('base_document_path');
            $table->string('base_document_mime_type', 120)->nullable()->after('base_document_original_name');
            $table->unsignedBigInteger('base_document_size')->default(0)->after('base_document_mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn([
                'base_document_path',
                'base_document_original_name',
                'base_document_mime_type',
                'base_document_size',
            ]);
        });
    }
};

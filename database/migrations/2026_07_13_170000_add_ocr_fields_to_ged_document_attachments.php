<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ged_document_attachments', function (Blueprint $table) {
            if (! Schema::hasColumn('ged_document_attachments', 'ocr_status')) {
                $table->string('ocr_status')->nullable()->after('notes');
            }

            if (! Schema::hasColumn('ged_document_attachments', 'extracted_text')) {
                $table->longText('extracted_text')->nullable()->after('ocr_status');
            }

            if (! Schema::hasColumn('ged_document_attachments', 'archive_path')) {
                $table->string('archive_path')->nullable()->after('extracted_text');
            }

            if (! Schema::hasColumn('ged_document_attachments', 'page_count')) {
                $table->unsignedInteger('page_count')->nullable()->after('archive_path');
            }

            if (! Schema::hasColumn('ged_document_attachments', 'processed_at')) {
                $table->timestamp('processed_at')->nullable()->after('page_count');
            }

            if (! Schema::hasColumn('ged_document_attachments', 'ocr_metadata')) {
                $table->json('ocr_metadata')->nullable()->after('processed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ged_document_attachments', function (Blueprint $table) {
            foreach (['ocr_metadata', 'processed_at', 'page_count', 'archive_path', 'extracted_text', 'ocr_status'] as $column) {
                if (Schema::hasColumn('ged_document_attachments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

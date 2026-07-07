<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ged_document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('matching_rules')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('ged_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('ged_tags')->nullOnDelete();
            $table->string('name');
            $table->string('color', 7)->default('#2563eb');
            $table->boolean('is_inbox')->default(false);
            $table->json('matching_rules')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('ged_correspondents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('document')->nullable();
            $table->json('matching_rules')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contract_id']);
        });

        Schema::create('ged_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('obra_id')->nullable()->constrained('obras')->nullOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('ged_document_types')->nullOnDelete();
            $table->foreignId('correspondent_id')->nullable()->constrained('ged_correspondents')->nullOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('document_number')->nullable();
            $table->date('document_date')->nullable();
            $table->string('status')->default('uploaded')->index();
            $table->text('description')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->string('original_filename');
            $table->string('mime_type', 180)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 64)->index();
            $table->string('storage_disk')->default('public');
            $table->string('original_path');
            $table->string('archive_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contract_id', 'obra_id']);
            $table->index(['tenant_id', 'document_type_id']);
            $table->index(['tenant_id', 'correspondent_id']);
        });

        Schema::create('ged_document_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('ged_documents')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('ged_tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'tag_id']);
        });

        Schema::create('ged_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('ged_documents')->cascadeOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->string('original_filename');
            $table->string('mime_type', 180)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 64);
            $table->string('storage_disk')->default('public');
            $table->string('path');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ged_document_versions');
        Schema::dropIfExists('ged_document_tag');
        Schema::dropIfExists('ged_documents');
        Schema::dropIfExists('ged_correspondents');
        Schema::dropIfExists('ged_tags');
        Schema::dropIfExists('ged_document_types');
    }
};

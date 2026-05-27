<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('obra_id')->nullable()->constrained('obras')->nullOnDelete();
            $table->foreignId('disciplina_id')->nullable()->constrained('disciplinas')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('code', 80)->nullable();
            $table->string('document_type', 40)->default('projeto');
            $table->string('status', 30)->default('ativo');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contract_id']);
            $table->index(['tenant_id', 'disciplina_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('project_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revision', 30)->default('R00');
            $table->string('original_name');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('aps_object_id')->nullable();
            $table->text('aps_urn')->nullable();
            $table->string('derivative_status', 30)->default('not_submitted');
            $table->timestamp('submitted_to_aps_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_document_id', 'revision']);
            $table->index(['tenant_id', 'derivative_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_document_versions');
        Schema::dropIfExists('project_documents');
    }
};

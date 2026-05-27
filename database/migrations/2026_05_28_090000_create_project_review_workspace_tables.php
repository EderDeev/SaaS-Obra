<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_review_markups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('markup_type', 30)->default('pin');
            $table->json('markup_payload')->nullable();
            $table->json('viewer_state')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('open');
            $table->date('due_date')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contract_id', 'status']);
            $table->index(['project_document_version_id', 'status'], 'project_review_markups_version_status_index');
        });

        Schema::create('project_review_checklists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('open');
            $table->timestamps();

            $table->unique('project_document_version_id', 'project_review_checklists_version_unique');
            $table->index(['tenant_id', 'contract_id', 'status']);
        });

        Schema::create('project_review_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_review_checklist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label');
            $table->boolean('required')->default(true);
            $table->boolean('checked')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'checked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_review_checklist_items');
        Schema::dropIfExists('project_review_checklists');
        Schema::dropIfExists('project_review_markups');
    }
};

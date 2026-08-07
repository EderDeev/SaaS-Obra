<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_submission_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('obra_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trecho_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_phase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('package_number', 30);
            $table->unsignedInteger('package_sequence');
            $table->unsignedSmallInteger('sequence_year');
            $table->string('title');
            $table->string('document_type', 40);
            $table->string('status', 30)->default('em_analise');
            $table->boolean('has_revisions')->default(false);
            $table->string('cap_number', 120);
            $table->unsignedInteger('cap_sequence');
            $table->unsignedSmallInteger('cap_year');
            $table->timestamp('cap_requested_at')->nullable();
            $table->text('cap_reason')->nullable();
            $table->text('cap_description')->nullable();
            $table->json('cap_impacts')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'sequence_year', 'package_sequence'], 'project_batches_tenant_sequence_unique');
            $table->unique(['tenant_id', 'cap_year', 'cap_sequence'], 'project_batches_tenant_cap_unique');
            $table->index(['tenant_id', 'contract_id', 'status'], 'project_batches_scope_index');
        });

        Schema::table('project_document_versions', function (Blueprint $table): void {
            $table->foreignId('project_submission_batch_id')
                ->nullable()
                ->after('project_document_id')
                ->constrained('project_submission_batches')
                ->nullOnDelete();
            $table->index(['tenant_id', 'project_submission_batch_id'], 'project_versions_batch_index');
        });
    }

    public function down(): void
    {
        Schema::table('project_document_versions', function (Blueprint $table): void {
            $table->dropIndex('project_versions_batch_index');
            $table->dropForeign(['project_submission_batch_id']);
            $table->dropColumn('project_submission_batch_id');
        });

        Schema::dropIfExists('project_submission_batches');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_documents', function (Blueprint $table): void {
            $table->string('document_number', 30)->nullable()->after('code');
            $table->index(['tenant_id', 'contract_id', 'code'], 'project_documents_tenant_contract_code_index');
        });

        DB::table('project_documents')
            ->whereNull('document_number')
            ->update(['document_number' => '001']);

        Schema::table('project_document_versions', function (Blueprint $table): void {
            $table->foreignId('reviewed_by_id')->nullable()->after('uploaded_by_id')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->after('reviewed_by_id')->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('em_analise')->after('revision');
            $table->timestamp('reviewed_at')->nullable()->after('status');
            $table->text('review_notes')->nullable()->after('reviewed_at');
            $table->timestamp('approved_at')->nullable()->after('review_notes');
            $table->text('approval_notes')->nullable()->after('approved_at');
            $table->index(['tenant_id', 'status', 'reviewed_at'], 'project_doc_versions_tenant_status_reviewed_index');
            $table->index(['tenant_id', 'status', 'approved_at'], 'project_doc_versions_tenant_status_approved_index');
        });

        DB::table('project_document_versions')
            ->join('project_documents', 'project_documents.id', '=', 'project_document_versions.project_document_id')
            ->select([
                'project_document_versions.id as version_id',
                'project_documents.status',
                'project_documents.reviewed_by_id',
                'project_documents.approved_by_id',
                'project_documents.reviewed_at',
                'project_documents.review_notes',
                'project_documents.approved_at',
                'project_documents.approval_notes',
            ])
            ->orderBy('project_document_versions.id')
            ->get()
            ->each(function (object $row): void {
                DB::table('project_document_versions')
                    ->where('id', $row->version_id)
                    ->update([
                        'status' => $row->status ?: 'em_analise',
                        'reviewed_by_id' => $row->reviewed_by_id,
                        'approved_by_id' => $row->approved_by_id,
                        'reviewed_at' => $row->reviewed_at,
                        'review_notes' => $row->review_notes,
                        'approved_at' => $row->approved_at,
                        'approval_notes' => $row->approval_notes,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('project_document_versions', function (Blueprint $table): void {
            $table->dropIndex('project_doc_versions_tenant_status_approved_index');
            $table->dropIndex('project_doc_versions_tenant_status_reviewed_index');
            $table->dropForeign(['approved_by_id']);
            $table->dropForeign(['reviewed_by_id']);
            $table->dropColumn([
                'approved_by_id',
                'reviewed_by_id',
                'status',
                'reviewed_at',
                'review_notes',
                'approved_at',
                'approval_notes',
            ]);
        });

        Schema::table('project_documents', function (Blueprint $table): void {
            $table->dropIndex('project_documents_tenant_contract_code_index');
            $table->dropColumn('document_number');
        });
    }
};

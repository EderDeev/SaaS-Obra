<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_document_versions', function (Blueprint $table): void {
            $table->string('cap_number', 30)->nullable()->after('revision_change_summary');
            $table->unsignedInteger('cap_sequence')->nullable()->after('cap_number');
            $table->unsignedSmallInteger('cap_year')->nullable()->after('cap_sequence');
            $table->foreignId('cap_requested_by_id')->nullable()->after('cap_year')->constrained('users')->nullOnDelete();
            $table->timestamp('cap_requested_at')->nullable()->after('cap_requested_by_id');
            $table->text('cap_reason')->nullable()->after('cap_requested_at');
            $table->text('cap_description')->nullable()->after('cap_reason');
            $table->json('cap_impacts')->nullable()->after('cap_description');
            $table->unique(['tenant_id', 'cap_year', 'cap_sequence'], 'project_doc_versions_tenant_cap_unique');
            $table->index(['tenant_id', 'cap_number'], 'project_doc_versions_tenant_cap_number_index');
        });
    }

    public function down(): void
    {
        Schema::table('project_document_versions', function (Blueprint $table): void {
            $table->dropIndex('project_doc_versions_tenant_cap_number_index');
            $table->dropUnique('project_doc_versions_tenant_cap_unique');
            $table->dropForeign(['cap_requested_by_id']);
            $table->dropColumn([
                'cap_number',
                'cap_sequence',
                'cap_year',
                'cap_requested_by_id',
                'cap_requested_at',
                'cap_reason',
                'cap_description',
                'cap_impacts',
            ]);
        });
    }
};

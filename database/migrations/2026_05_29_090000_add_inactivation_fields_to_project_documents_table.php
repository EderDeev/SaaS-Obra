<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_documents', function (Blueprint $table): void {
            $table->timestamp('inactive_at')->nullable()->after('approval_notes');
            $table->foreignId('inactive_by_id')->nullable()->after('inactive_at')->constrained('users')->nullOnDelete();
            $table->text('inactive_reason')->nullable()->after('inactive_by_id');
            $table->index(['tenant_id', 'inactive_at'], 'project_documents_tenant_inactive_index');
        });
    }

    public function down(): void
    {
        Schema::table('project_documents', function (Blueprint $table): void {
            $table->dropIndex('project_documents_tenant_inactive_index');
            $table->dropForeign(['inactive_by_id']);
            $table->dropColumn(['inactive_at', 'inactive_by_id', 'inactive_reason']);
        });
    }
};

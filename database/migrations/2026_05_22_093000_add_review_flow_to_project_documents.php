<?php

use App\Support\ProjectPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_documents', function (Blueprint $table): void {
            $table->foreignId('reviewed_by_id')->nullable()->after('created_by_id')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('status');
            $table->text('review_notes')->nullable()->after('reviewed_at');
            $table->index(['tenant_id', 'status', 'reviewed_at'], 'project_documents_tenant_status_reviewed_index');
        });

        DB::table('tenant_users')
            ->whereIn('role', ['tenant_owner', 'tenant_admin'])
            ->whereNotNull('project_permissions')
            ->orderBy('id')
            ->get()
            ->each(function (object $membership): void {
                $permissions = json_decode((string) $membership->project_permissions, true) ?: [];

                if (! in_array(ProjectPermissions::REVIEW, $permissions, true)) {
                    $permissions[] = ProjectPermissions::REVIEW;

                    DB::table('tenant_users')
                        ->where('id', $membership->id)
                        ->update([
                            'project_permissions' => json_encode(ProjectPermissions::normalize($permissions)),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('project_documents', function (Blueprint $table): void {
            $table->dropIndex('project_documents_tenant_status_reviewed_index');
            $table->dropForeign(['reviewed_by_id']);
            $table->dropColumn(['reviewed_by_id', 'reviewed_at', 'review_notes']);
        });
    }
};

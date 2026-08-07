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
        Schema::create('project_document_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status', 40);
            $table->string('to_status', 40);
            $table->text('reason');
            $table->timestamps();

            $table->index(['tenant_id', 'project_document_id'], 'project_status_changes_tenant_document_index');
        });

        foreach (['tenant_users', 'contract_participants'] as $table) {
            DB::table($table)
                ->whereNotNull('project_permissions')
                ->orderBy('id')
                ->eachById(function (object $record) use ($table): void {
                    $permissions = json_decode((string) $record->project_permissions, true) ?: [];

                    if (
                        ! in_array(ProjectPermissions::STATUS, $permissions, true)
                        && (
                            in_array(ProjectPermissions::REVIEW, $permissions, true)
                            || in_array(ProjectPermissions::DELETE, $permissions, true)
                        )
                    ) {
                        $permissions[] = ProjectPermissions::STATUS;

                        DB::table($table)->where('id', $record->id)->update([
                            'project_permissions' => json_encode(ProjectPermissions::normalize($permissions)),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_document_status_changes');
    }
};

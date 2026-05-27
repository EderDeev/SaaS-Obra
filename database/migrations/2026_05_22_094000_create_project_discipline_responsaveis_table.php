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
        Schema::create('project_discipline_responsaveis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('disciplina_id')->constrained('disciplinas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['contract_id', 'disciplina_id', 'user_id'], 'project_discipline_responsaveis_unique');
            $table->index(['tenant_id', 'status'], 'project_discipline_responsaveis_tenant_status_index');
            $table->index(['tenant_id', 'contract_id', 'disciplina_id'], 'project_discipline_responsaveis_scope_index');
        });

        DB::table('tenant_users')
            ->whereIn('role', ['tenant_owner', 'tenant_admin'])
            ->whereNotNull('project_permissions')
            ->orderBy('id')
            ->get()
            ->each(function (object $membership): void {
                $permissions = json_decode((string) $membership->project_permissions, true) ?: [];

                if (! in_array(ProjectPermissions::RESPONSIBLES, $permissions, true)) {
                    $permissions[] = ProjectPermissions::RESPONSIBLES;

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
        Schema::dropIfExists('project_discipline_responsaveis');
    }
};

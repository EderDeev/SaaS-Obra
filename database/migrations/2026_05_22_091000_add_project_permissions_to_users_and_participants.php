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
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->json('project_permissions')->nullable()->after('parametrizacao_permissions');
        });

        Schema::table('contract_participants', function (Blueprint $table): void {
            $table->json('project_permissions')->nullable()->after('activity_permissions');
        });

        DB::table('tenant_users')
            ->orderBy('id')
            ->get(['id', 'role'])
            ->each(function ($membership): void {
                DB::table('tenant_users')
                    ->where('id', $membership->id)
                    ->update([
                        'project_permissions' => json_encode(ProjectPermissions::defaultForRole($membership->role)),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('contract_participants', function (Blueprint $table): void {
            $table->dropColumn('project_permissions');
        });

        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropColumn('project_permissions');
        });
    }
};

<?php

use App\Support\DocumentationPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->json('documentation_permissions')->nullable()->after('budget_permissions');
        });

        Schema::table('contract_participants', function (Blueprint $table): void {
            $table->json('documentation_permissions')->nullable()->after('project_permissions');
        });

        DB::table('tenant_users')
            ->select(['id', 'role'])
            ->orderBy('id')
            ->eachById(function (object $membership): void {
                DB::table('tenant_users')
                    ->where('id', $membership->id)
                    ->update([
                        'documentation_permissions' => json_encode(DocumentationPermissions::defaultForRole($membership->role)),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('contract_participants', function (Blueprint $table): void {
            $table->dropColumn('documentation_permissions');
        });

        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropColumn('documentation_permissions');
        });
    }
};

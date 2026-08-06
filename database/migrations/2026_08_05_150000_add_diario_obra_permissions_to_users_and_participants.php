<?php

use App\Support\DiarioObraPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->json('diario_obra_permissions')->nullable()->after('documentation_permissions');
        });

        Schema::table('contract_participants', function (Blueprint $table): void {
            $table->json('diario_obra_permissions')->nullable()->after('documentation_permissions');
        });

        DB::table('tenant_users')
            ->select(['id', 'role'])
            ->orderBy('id')
            ->eachById(function (object $membership): void {
                DB::table('tenant_users')
                    ->where('id', $membership->id)
                    ->update([
                        'diario_obra_permissions' => json_encode(DiarioObraPermissions::defaultForRole($membership->role)),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('contract_participants', function (Blueprint $table): void {
            $table->dropColumn('diario_obra_permissions');
        });

        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropColumn('diario_obra_permissions');
        });
    }
};

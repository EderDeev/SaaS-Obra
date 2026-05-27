<?php

use App\Support\ActivityPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_participants', function (Blueprint $table): void {
            $table->json('activity_permissions')->nullable()->after('status');
        });

        DB::table('contract_participants')
            ->join('tenant_users', function ($join): void {
                $join->on('tenant_users.tenant_id', '=', 'contract_participants.tenant_id')
                    ->on('tenant_users.user_id', '=', 'contract_participants.user_id');
            })
            ->whereNull('contract_participants.activity_permissions')
            ->select([
                'contract_participants.id',
                'contract_participants.role as contract_role',
                'tenant_users.role as tenant_role',
                'tenant_users.activity_permissions as tenant_activity_permissions',
            ])
            ->orderBy('contract_participants.id')
            ->get()
            ->each(function ($participant): void {
                $permissions = $participant->tenant_activity_permissions
                    ? json_decode($participant->tenant_activity_permissions, true)
                    : ActivityPermissions::defaultForRole($participant->tenant_role);

                DB::table('contract_participants')
                    ->where('id', $participant->id)
                    ->update([
                        'activity_permissions' => json_encode(ActivityPermissions::normalize($permissions ?: [])),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('contract_participants', function (Blueprint $table): void {
            $table->dropColumn('activity_permissions');
        });
    }
};

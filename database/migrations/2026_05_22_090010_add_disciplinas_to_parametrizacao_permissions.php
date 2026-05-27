<?php

use App\Support\ParametrizacaoPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenant_users')
            ->whereIn('role', ['tenant_owner', 'tenant_admin'])
            ->orderBy('id')
            ->get(['id', 'role', 'parametrizacao_permissions'])
            ->each(function ($membership): void {
                DB::table('tenant_users')
                    ->where('id', $membership->id)
                    ->update([
                        'parametrizacao_permissions' => json_encode(ParametrizacaoPermissions::defaultForRole($membership->role)),
                    ]);
            });
    }

    public function down(): void
    {
        DB::table('tenant_users')
            ->orderBy('id')
            ->get(['id', 'parametrizacao_permissions'])
            ->each(function ($membership): void {
                $permissions = collect(json_decode($membership->parametrizacao_permissions ?: '[]', true) ?: [])
                    ->reject(fn ($permission): bool => $permission === 'view_parametrizacao_disciplinas')
                    ->values()
                    ->all();

                DB::table('tenant_users')
                    ->where('id', $membership->id)
                    ->update([
                        'parametrizacao_permissions' => json_encode($permissions),
                    ]);
            });
    }
};

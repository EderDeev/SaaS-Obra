<?php

use App\Support\ParametrizacaoPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenant_users')->select(['id', 'parametrizacao_permissions'])->orderBy('id')->eachById(function (object $membership): void {
            $current = collect(json_decode($membership->parametrizacao_permissions ?: '[]', true) ?: []);
            $expanded = $current
                ->reject(fn ($permission): bool => in_array($permission, ['view_parametrizacao_contrato', 'view_parametrizacao_usuarios_contratos'], true))
                ->when($current->contains(ParametrizacaoPermissions::EMPRESAS), fn ($permissions) => $permissions->push(ParametrizacaoPermissions::MANAGE_EMPRESAS))
                ->when($current->contains(ParametrizacaoPermissions::OBRAS), fn ($permissions) => $permissions->push(ParametrizacaoPermissions::MANAGE_OBRAS))
                ->when($current->contains(ParametrizacaoPermissions::DISCIPLINAS), fn ($permissions) => $permissions->push(ParametrizacaoPermissions::MANAGE_DISCIPLINAS))
                ->all();

            DB::table('tenant_users')->where('id', $membership->id)->update([
                'parametrizacao_permissions' => json_encode(ParametrizacaoPermissions::normalize($expanded)),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('tenant_users')->select(['id', 'parametrizacao_permissions'])->orderBy('id')->eachById(function (object $membership): void {
            $permissions = collect(json_decode($membership->parametrizacao_permissions ?: '[]', true) ?: [])
                ->reject(fn ($permission): bool => str_starts_with((string) $permission, 'manage_parametrizacao_'))
                ->values()
                ->all();
            DB::table('tenant_users')->where('id', $membership->id)->update([
                'parametrizacao_permissions' => json_encode($permissions),
            ]);
        });
    }
};

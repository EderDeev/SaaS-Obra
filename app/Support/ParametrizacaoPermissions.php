<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;

class ParametrizacaoPermissions
{
    public const VIEW = 'view_parametrizacao';
    public const EMPRESAS = 'view_parametrizacao_empresas';
    public const OBRAS = 'view_parametrizacao_obras';
    public const CONTRATO = 'view_parametrizacao_contrato';
    public const USUARIOS_CONTRATOS = 'view_parametrizacao_usuarios_contratos';
    public const DISCIPLINAS = 'view_parametrizacao_disciplinas';

    public const LABELS = [
        self::VIEW => 'Visualizar Parametrizacao',
        self::EMPRESAS => 'Empresas',
        self::OBRAS => 'Obras',
        self::CONTRATO => 'Contrato',
        self::USUARIOS_CONTRATOS => 'Usuarios x Contratos',
        self::DISCIPLINAS => 'Disciplinas',
    ];

    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function labels(): array
    {
        return self::LABELS;
    }

    public static function normalize(array $permissions): array
    {
        $normalized = collect($permissions)
            ->filter(fn ($permission): bool => in_array($permission, self::all(), true))
            ->unique()
            ->values();

        if ($normalized->contains(fn ($permission): bool => $permission !== self::VIEW)) {
            $normalized->prepend(self::VIEW);
        }

        return $normalized
            ->unique()
            ->values()
            ->all();
    }

    public static function can(User $user, Tenant $tenant, string $permission): bool
    {
        if (! in_array($permission, self::all(), true)) {
            return false;
        }

        return in_array($permission, self::permissionsFor($user, $tenant), true);
    }

    public static function canAny(User $user, Tenant $tenant, string $permission): bool
    {
        return self::can($user, $tenant, $permission);
    }

    public static function permissionsFor(User $user, Tenant $tenant): array
    {
        $role = $user->tenantRole($tenant);

        if ($role === 'tenant_owner') {
            return self::all();
        }

        $membership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first(['role', 'parametrizacao_permissions']);

        if (! $membership) {
            return [];
        }

        if ($membership->parametrizacao_permissions === null) {
            return self::defaultForRole($membership->role);
        }

        return self::normalize($membership->parametrizacao_permissions);
    }

    public static function defaultForRole(?string $role): array
    {
        return match ($role) {
            'tenant_owner', 'tenant_admin' => self::all(),
            default => [],
        };
    }
}

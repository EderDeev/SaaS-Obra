<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;

class UserPermissions
{
    public const VIEW = 'view_users';
    public const CREATE = 'create_user';
    public const EDIT = 'edit_user';
    public const DEACTIVATE = 'deactivate_user';

    public const LABELS = [
        self::VIEW => 'Visualizar Usuarios',
        self::CREATE => 'Criar usuario',
        self::EDIT => 'Editar usuario',
        self::DEACTIVATE => 'Desativar usuario',
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
        return collect($permissions)
            ->filter(fn ($permission): bool => in_array($permission, self::all(), true))
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
            ->first(['role', 'user_permissions']);

        if (! $membership) {
            return [];
        }

        if ($membership->user_permissions === null) {
            return self::defaultForRole($membership->role);
        }

        return self::normalize($membership->user_permissions);
    }

    public static function defaultForRole(?string $role): array
    {
        return match ($role) {
            'tenant_owner', 'tenant_admin' => self::all(),
            default => [],
        };
    }
}

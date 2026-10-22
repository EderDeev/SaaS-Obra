<?php

namespace App\Support;

use App\Models\{Tenant, TenantUser, User};

class NavigationPermissions
{
    public const OVERVIEW = 'view_overview';
    public const TUTORIALS = 'view_tutorials';
    public const LABELS = [self::OVERVIEW => 'Visualizar Visão geral', self::TUTORIALS => 'Visualizar Tutoriais'];

    public static function all(): array { return array_keys(self::LABELS); }
    public static function labels(): array { return self::LABELS; }
    public static function normalize(array $permissions): array
    {
        return array_values(array_unique(array_intersect(self::all(), $permissions)));
    }

    public static function permissionsFor(User $user, Tenant $tenant): array
    {
        if ($user->tenantRole($tenant) === TenantRoles::OWNER) return self::all();
        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'active')->first();
        // Preserve existing access until an administrator explicitly configures it.
        if ($membership) return self::normalize($membership->navigation_permissions ?? self::all());
        return $user->hasTenantAccess($tenant) ? self::all() : [];
    }

    public static function can(User $user, Tenant $tenant, string $permission): bool
    {
        return in_array($permission, self::permissionsFor($user, $tenant), true);
    }
}

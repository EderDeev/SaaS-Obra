<?php

namespace App\Support;

use App\Models\ContractParticipant;
use App\Models\Tenant;
use App\Models\User;

class OsDnitPermissions
{
    public const VIEW = 'view_orders';

    public const CREATE = 'create_orders';

    public const EDIT = 'edit_orders';

    public const DELETE = 'delete_orders';

    public const VIEW_PRODUCTS = 'view_products';

    public const CREATE_PRODUCTS = 'create_products';

    public const EDIT_PRODUCTS = 'edit_products';

    public const DELETE_PRODUCTS = 'delete_products';

    public const DASHBOARD = 'view_dashboard';

    public const VIEW_SETTINGS = 'view_settings';

    public const CREATE_SETTINGS = 'create_settings';

    public const EDIT_SETTINGS = 'edit_settings';

    public const DELETE_SETTINGS = 'delete_settings';

    public const RESPONSIBLES = 'manage_responsibles';

    public const LABELS = [
        self::VIEW => 'Visualizar OS (exibir menu Ordem de Serviço)',
        self::CREATE => 'Criar OS', self::EDIT => 'Editar OS', self::DELETE => 'Excluir OS (somente demandas não enviados)',
        self::VIEW_PRODUCTS => 'Visualizar produtos', self::CREATE_PRODUCTS => 'Criar produtos',
        self::EDIT_PRODUCTS => 'Editar produtos', self::DELETE_PRODUCTS => 'Excluir produtos',
        self::DASHBOARD => 'Visualizar dashboard',
        self::VIEW_SETTINGS => 'Visualizar parametrização',
        self::CREATE_SETTINGS => 'Criar parametrização', self::EDIT_SETTINGS => 'Editar parametrização',
        self::DELETE_SETTINGS => 'Excluir parametrização', self::RESPONSIBLES => 'Gerenciar responsáveis',
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
        $permissions = array_values(array_unique(array_intersect(self::all(), $permissions)));
        if ($permissions !== [] && ! in_array(self::VIEW, $permissions, true)) {
            array_unshift($permissions, self::VIEW);
        }
        if (array_intersect($permissions, [self::CREATE_PRODUCTS, self::EDIT_PRODUCTS, self::DELETE_PRODUCTS])
            && ! in_array(self::VIEW_PRODUCTS, $permissions, true)) {
            $permissions[] = self::VIEW_PRODUCTS;
        }
        if (array_intersect($permissions, [self::CREATE_SETTINGS, self::EDIT_SETTINGS, self::DELETE_SETTINGS])
            && ! in_array(self::VIEW_SETTINGS, $permissions, true)) {
            $permissions[] = self::VIEW_SETTINGS;
        }

        return $permissions;
    }

    public static function defaultForRole(?string $role): array
    {
        if (TenantRoles::isTenantAdmin($role)) {
            return self::all();
        }

        // Preserve the module's pre-permission access. Workflow roles still gate
        // creation/editing; catalog/settings writes remain explicitly granted.
        return [self::VIEW, self::CREATE, self::EDIT, self::VIEW_PRODUCTS, self::DASHBOARD];
    }

    public static function matrix(User $user, Tenant $tenant): array
    {
        $key = 'os_dnit.permissions.'.$tenant->id.'.'.$user->id;
        if (! app()->runningInConsole() && request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }
        $role = $user->tenantRole($tenant);
        $matrix = ContractParticipant::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'active')
            ->whereHas('contract', fn ($query) => $query->where('tenant_id', $tenant->id))
            ->get(['contract_id', 'os_dnit_permissions'])->mapWithKeys(fn ($participant) => [
                $participant->contract_id => $role === TenantRoles::OWNER
                    ? self::all() : self::normalize($participant->os_dnit_permissions ?? self::defaultForRole($role)),
            ])->all();
        if (! app()->runningInConsole()) {
            request()->attributes->set($key, $matrix);
        }

        return $matrix;
    }

    public static function contractIds(User $user, Tenant $tenant, string $permission): array
    {
        return array_keys(array_filter(self::matrix($user, $tenant), fn ($permissions) => in_array($permission, $permissions, true)));
    }

    public static function can(User $user, Tenant $tenant, string $permission, int $contractId): bool
    {
        return in_array($permission, self::matrix($user, $tenant)[$contractId] ?? [], true);
    }

    public static function canAny(User $user, Tenant $tenant, string $permission): bool
    {
        if (self::contractIds($user, $tenant, $permission) !== []) {
            return true;
        }

        // Administrators can discover and prepare an empty module. This grants
        // no contract permissions or workflow actions, which still require links.
        return in_array($permission, [self::VIEW, self::VIEW_PRODUCTS, self::DASHBOARD, self::VIEW_SETTINGS, self::RESPONSIBLES], true)
            && self::canSetUpEmptyTenant($user, $tenant);
    }

    public static function canSetUpEmptyTenant(User $user, Tenant $tenant): bool
    {
        if (! TenantRoles::isTenantAdmin($user->tenantRole($tenant))) {
            return false;
        }

        $key = 'os_dnit.empty_tenant.'.$tenant->id;
        if (! app()->runningInConsole() && request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }
        $empty = ! $tenant->contracts()->exists();
        if (! app()->runningInConsole()) {
            request()->attributes->set($key, $empty);
        }

        return $empty;
    }
}

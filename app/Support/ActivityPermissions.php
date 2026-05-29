<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Collection;

class ActivityPermissions
{
    public const VIEW = 'view_activities';
    public const CREATE = 'create_activity';
    public const EDIT = 'edit_activity';
    public const DELETE = 'delete_activity';

    public const LABELS = [
        self::VIEW => 'Visualizar Atividades',
        self::CREATE => 'Criar atividade',
        self::EDIT => 'Editar atividade',
        self::DELETE => 'Excluir atividade',
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

    public static function can(User $user, Tenant $tenant, string $permission, ?Contract $contract = null): bool
    {
        if (! in_array($permission, self::all(), true)) {
            return false;
        }

        return in_array($permission, self::permissionsFor($user, $tenant, $contract), true);
    }

    public static function canAny(User $user, Tenant $tenant, string $permission): bool
    {
        $role = $user->tenantRole($tenant);

        if ($role === 'tenant_owner') {
            return true;
        }

        if ($role === 'tenant_admin') {
            return self::can($user, $tenant, $permission);
        }

        $contractIds = self::contractIdsFor($user, $tenant, $permission);

        return $contractIds === null || $contractIds->isNotEmpty();
    }

    public static function permissionsFor(User $user, Tenant $tenant, ?Contract $contract = null): array
    {
        $role = $user->tenantRole($tenant);

        if ($role === 'tenant_owner') {
            return self::all();
        }

        if ($contract && ! in_array($role, ['tenant_admin'], true)) {
            $participant = ContractParticipant::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $contract->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first(['role', 'activity_permissions']);

            if (! $participant) {
                return [];
            }

            if ($participant->activity_permissions !== null) {
                return self::normalize($participant->activity_permissions);
            }
        }

        $membership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first(['role', 'activity_permissions']);

        if (! $membership) {
            return [];
        }

        if ($membership->activity_permissions === null) {
            return self::defaultForRole($membership->role);
        }

        return self::normalize($membership->activity_permissions);
    }

    /**
     * @return Collection<int, int>|null
     */
    public static function contractIdsFor(User $user, Tenant $tenant, string $permission): ?Collection
    {
        if ($user->tenantRole($tenant) === 'tenant_owner') {
            return null;
        }

        if ($user->tenantRole($tenant) === 'tenant_admin' && self::can($user, $tenant, $permission)) {
            return null;
        }

        $membership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first(['role', 'activity_permissions']);

        if (! $membership) {
            return collect();
        }

        return ContractParticipant::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get(['contract_id', 'activity_permissions'])
            ->filter(function (ContractParticipant $participant) use ($membership, $permission): bool {
                $permissions = $participant->activity_permissions === null
                    ? ($membership->activity_permissions ?? self::defaultForRole($membership->role))
                    : $participant->activity_permissions;

                return in_array($permission, self::normalize($permissions ?: []), true);
            })
            ->pluck('contract_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    public static function defaultForRole(?string $role): array
    {
        if (TenantRoles::isTenantAdmin($role)) {
            return self::all();
        }

        if (in_array($role, [
            ...TenantRoles::managementRoles(),
            ...TenantRoles::coordinationRoles(),
            ...TenantRoles::engineeringRoles(),
            ...TenantRoles::supervisionRoles(),
        ], true)) {
            return [self::VIEW, self::CREATE, self::EDIT];
        }

        if (in_array($role, [
            ...TenantRoles::technicalRoles(),
            ...TenantRoles::administrativeRoles(),
        ], true)) {
            return [self::VIEW];
        }

        return [];
    }
}

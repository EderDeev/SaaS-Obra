<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Collection;

class ContractPermissions
{
    public const VIEW = 'view_contracts';
    public const CREATE = 'create_contracts';
    public const PARAMETRIZE = 'parametrize_contracts';
    public const ADDITIVES = 'manage_contract_additives';
    public const PARTICIPANTS = 'manage_contract_participants';

    public const LABELS = [
        self::VIEW => 'Visualizar contratos',
        self::CREATE => 'Criar contratos',
        self::PARAMETRIZE => 'Parametrizar contratos',
        self::ADDITIVES => 'Gerenciar aditivos contratuais',
        self::PARTICIPANTS => 'Gerenciar participantes do contrato',
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

        return $normalized->unique()->values()->all();
    }

    public static function can(User $user, Tenant $tenant, string $permission, ?Contract $contract = null): bool
    {
        return in_array($permission, self::all(), true)
            && in_array($permission, self::permissionsFor($user, $tenant, $contract), true);
    }

    public static function canAny(User $user, Tenant $tenant, string $permission): bool
    {
        if ($user->is_platform_admin || $user->tenantRole($tenant) === TenantRoles::OWNER) {
            return true;
        }

        if ($user->tenantRole($tenant) === TenantRoles::ADMIN) {
            return self::can($user, $tenant, $permission);
        }

        return self::contractIdsFor($user, $tenant, $permission)->isNotEmpty();
    }

    public static function permissionsFor(User $user, Tenant $tenant, ?Contract $contract = null): array
    {
        $role = $user->tenantRole($tenant);

        if ($user->is_platform_admin || $role === TenantRoles::OWNER) {
            return self::all();
        }

        $membership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first(['role', 'contract_permissions']);

        if ($contract && $role !== TenantRoles::ADMIN) {
            $participant = ContractParticipant::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $contract->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first(['role', 'contract_permissions']);

            if (! $participant) {
                return [];
            }

            if ($participant->contract_permissions !== null) {
                return self::normalize($participant->contract_permissions);
            }

            if (! $membership) {
                return self::defaultForParticipantRole($participant->role);
            }
        }

        if (! $membership) {
            return [];
        }

        return $membership->contract_permissions === null
            ? self::defaultForRole($membership->role)
            : self::normalize($membership->contract_permissions);
    }

    /** @return Collection<int, int>|null */
    public static function contractIdsFor(User $user, Tenant $tenant, string $permission): ?Collection
    {
        if ($user->is_platform_admin || $user->tenantRole($tenant) === TenantRoles::OWNER) {
            return null;
        }

        if ($user->tenantRole($tenant) === TenantRoles::ADMIN && self::can($user, $tenant, $permission)) {
            return null;
        }

        $membership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first(['role', 'contract_permissions']);

        return ContractParticipant::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get(['contract_id', 'role', 'contract_permissions'])
            ->filter(function (ContractParticipant $participant) use ($membership, $permission): bool {
                $permissions = $participant->contract_permissions
                    ?? $membership?->contract_permissions
                    ?? ($membership
                        ? self::defaultForRole($membership->role)
                        : self::defaultForParticipantRole($participant->role));

                return in_array($permission, self::normalize($permissions ?: []), true);
            })
            ->pluck('contract_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    public static function defaultForRole(?string $role): array
    {
        if (TenantRoles::isTenantAdmin($role) || TenantRoles::canManageContracts($role)) {
            return self::all();
        }

        return in_array($role, [
            ...TenantRoles::coordinationRoles(),
            ...TenantRoles::engineeringRoles(),
            ...TenantRoles::supervisionRoles(),
            ...TenantRoles::technicalRoles(),
            ...TenantRoles::administrativeRoles(),
            'engenheiro',
            'tenant_member',
            'member',
        ], true) ? [self::VIEW] : [];
    }

    public static function defaultForParticipantRole(?string $role): array
    {
        return $role === 'manager'
            ? self::all()
            : [self::VIEW];
    }
}

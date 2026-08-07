<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\RdoResponsavel;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Collection;

class DiarioObraPermissions
{
    public const VIEW = 'view_diario_obra';
    public const FILL_RDA = 'fill_rda';
    public const FILL_RDO = 'fill_rdo';
    public const REVIEW_RDO = 'review_rdo';
    public const SIGNATURES = 'manage_rdo_signatures';
    public const DASHBOARD = 'view_rdo_dashboard';
    public const RESPONSIBLES = 'manage_diario_responsibles';
    public const CATALOGS = 'manage_rdo_catalogs';
    public const SETTINGS = 'manage_rdo_settings';

    public const LABELS = [
        self::VIEW => 'Visualizar RDO e RDA',
        self::FILL_RDA => 'Preencher e publicar RDA',
        self::FILL_RDO => 'Preencher e consolidar RDO',
        self::REVIEW_RDO => 'Analisar e aprovar RDO',
        self::SIGNATURES => 'Gerenciar assinaturas do RDO',
        self::DASHBOARD => 'Visualizar dashboard do RDO',
        self::RESPONSIBLES => 'Gerenciar responsáveis',
        self::CATALOGS => 'Gerenciar cadastros do RDO',
        self::SETTINGS => 'Gerenciar parametrização e geração',
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
        if (! in_array($permission, self::all(), true)) {
            return false;
        }

        return in_array($permission, self::permissionsFor($user, $tenant, $contract), true);
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
            ->first(['role', 'diario_obra_permissions']);

        if (! $membership) {
            return [];
        }

        if ($contract && $role !== TenantRoles::ADMIN) {
            $participant = ContractParticipant::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $contract->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first(['role', 'diario_obra_permissions']);

            $isOperationalResponsible = RdoResponsavel::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $contract->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();

            if (! $participant && ! $isOperationalResponsible) {
                return [];
            }

            if ($participant?->diario_obra_permissions !== null) {
                return self::normalize($participant->diario_obra_permissions);
            }
        }

        return $membership->diario_obra_permissions === null
            ? self::defaultForRole($membership->role)
            : self::normalize($membership->diario_obra_permissions);
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
            ->first(['role', 'diario_obra_permissions']);

        if (! $membership) {
            return collect();
        }

        $participants = ContractParticipant::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get(['contract_id', 'diario_obra_permissions']);
        $responsibleContractIds = RdoResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('contract_id');

        return $participants
            ->pluck('contract_id')
            ->merge($responsibleContractIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->filter(function (int $contractId) use ($participants, $membership, $permission): bool {
                $participant = $participants->firstWhere('contract_id', $contractId);
                $permissions = $participant?->diario_obra_permissions === null
                    ? ($membership->diario_obra_permissions ?? self::defaultForRole($membership->role))
                    : $participant->diario_obra_permissions;

                return in_array($permission, self::normalize($permissions ?: []), true);
            })
            ->values();
    }

    public static function defaultForRole(?string $role): array
    {
        if (TenantRoles::isTenantAdmin($role) || in_array($role, TenantRoles::managementRoles(), true)) {
            return self::all();
        }

        if (in_array($role, TenantRoles::coordinationRoles(), true)) {
            return [self::VIEW, self::FILL_RDA, self::FILL_RDO, self::REVIEW_RDO, self::SIGNATURES, self::DASHBOARD, self::RESPONSIBLES, self::CATALOGS];
        }

        if (in_array($role, [
            ...TenantRoles::engineeringRoles(),
            ...TenantRoles::supervisionRoles(),
            ...TenantRoles::technicalRoles(),
            'engenheiro',
            'tenant_member',
            'member',
        ], true)) {
            return [self::VIEW, self::FILL_RDA, self::FILL_RDO, self::REVIEW_RDO, self::DASHBOARD];
        }

        return in_array($role, TenantRoles::administrativeRoles(), true)
            ? [self::VIEW, self::FILL_RDA, self::DASHBOARD]
            : [];
    }
}

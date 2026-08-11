<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Collection;

class MedicaoPermissions
{
    public const VIEW = 'view_measurements';

    public const ITEMS = 'manage_measurement_items';

    public const IMPORT_ITEMS = 'import_measurement_items';

    public const ADDITIVES = 'manage_measurement_item_additives';

    public const ADJUSTMENT_INDICES = 'manage_measurement_adjustment_indices';

    public const CLAIMS = 'manage_measurement_claims';

    public const ANALYZE = 'analyze_measurement_claims';

    public const RESPONSIBLES = 'manage_measurement_responsibles';

    public const BULLETINS = 'manage_measurement_bulletins';

    public const REPORTS = 'view_measurement_reports';

    public const BI = 'view_measurement_bi';

    public const LABELS = [
        self::VIEW => 'Visualizar medições',
        self::ITEMS => 'Criar itens de contrato manualmente',
        self::IMPORT_ITEMS => 'Importar orçamentos e bases de itens',
        self::ADDITIVES => 'Gerenciar aditivos de itens',
        self::ADJUSTMENT_INDICES => 'Gerenciar índices de reajuste',
        self::CLAIMS => 'Criar, editar e enviar pleitos',
        self::ANALYZE => 'Analisar pleitos de medição',
        self::RESPONSIBLES => 'Gerenciar responsáveis da análise',
        self::BULLETINS => 'Gerenciar boletins de medição',
        self::REPORTS => 'Visualizar relatórios de medição',
        self::BI => 'Visualizar B.I. de medição',
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
            ->first(['role', 'medicao_permissions']);

        if (! $membership) {
            return [];
        }

        if ($contract && $role !== TenantRoles::ADMIN) {
            $participant = ContractParticipant::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $contract->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first(['medicao_permissions']);

            if (! $participant) {
                return [];
            }

            if ($participant->medicao_permissions !== null) {
                return self::normalize($participant->medicao_permissions);
            }
        }

        return $membership->medicao_permissions === null
            ? self::defaultForRole($membership->role)
            : self::normalize($membership->medicao_permissions);
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
            ->first(['role', 'medicao_permissions']);

        if (! $membership) {
            return collect();
        }

        return ContractParticipant::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get(['contract_id', 'medicao_permissions'])
            ->filter(function (ContractParticipant $participant) use ($membership, $permission): bool {
                $permissions = $participant->medicao_permissions
                    ?? $membership->medicao_permissions
                    ?? self::defaultForRole($membership->role);

                return in_array($permission, self::normalize($permissions ?: []), true);
            })
            ->pluck('contract_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    public static function defaultForRole(?string $role): array
    {
        if (TenantRoles::isTenantAdmin($role) || in_array($role, [
            ...TenantRoles::managementRoles(),
            ...TenantRoles::coordinationRoles(),
        ], true)) {
            return self::all();
        }

        if (in_array($role, [
            ...TenantRoles::engineeringRoles(),
            ...TenantRoles::supervisionRoles(),
            ...TenantRoles::technicalRoles(),
            'engenheiro',
            'tenant_member',
            'member',
        ], true)) {
            return [self::VIEW, self::CLAIMS, self::ANALYZE, self::REPORTS, self::BI];
        }

        return in_array($role, TenantRoles::administrativeRoles(), true)
            ? [self::VIEW, self::CLAIMS, self::REPORTS, self::BI]
            : [];
    }
}

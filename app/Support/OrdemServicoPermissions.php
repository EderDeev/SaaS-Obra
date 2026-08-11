<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\OrdemServicoObraResponsavel;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Collection;

class OrdemServicoPermissions
{
    public const VIEW = 'view_service_orders';
    public const MANAGE_DRAFTS = 'manage_service_order_drafts';
    public const ANALYZE = 'analyze_service_orders';
    public const APPROVE = 'approve_service_orders';
    public const RESPONSIBLES = 'manage_service_order_responsibles';
    public const SETTINGS = 'manage_service_order_settings';
    public const METRICS = 'view_service_order_metrics';
    public const EXECUTE = 'manage_service_order_execution';
    public const COMPLETE = 'complete_service_orders';

    public const LABELS = [
        self::METRICS => 'Visualizar métricas da OS',
        self::VIEW => 'Visualizar ordens de serviço',
        self::MANAGE_DRAFTS => 'Criar, editar e enviar OS',
        self::ANALYZE => 'Analisar ordens de serviço',
        self::APPROVE => 'Aprovar ordens de serviço',
        self::RESPONSIBLES => 'Gerenciar responsáveis da OS',
        self::SETTINGS => 'Parametrizar requisitos da OS',
        self::EXECUTE => 'Registrar execução da OS',
        self::COMPLETE => 'Concluir ou cancelar ordens de serviço',
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
            ->first(['role', 'ordem_servico_permissions']);

        if (! $membership) {
            return [];
        }

        if ($contract && $role !== TenantRoles::ADMIN) {
            $participant = ContractParticipant::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $contract->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first(['role', 'ordem_servico_permissions']);

            $isOperationalResponsible = OrdemServicoObraResponsavel::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $contract->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();

            if (! $participant && ! $isOperationalResponsible) {
                return [];
            }

            if ($participant?->ordem_servico_permissions !== null) {
                return self::normalize($participant->ordem_servico_permissions);
            }
        }

        return $membership->ordem_servico_permissions === null
            ? self::defaultForRole($membership->role)
            : self::normalize($membership->ordem_servico_permissions);
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
            ->first(['role', 'ordem_servico_permissions']);

        if (! $membership) {
            return collect();
        }

        $participants = ContractParticipant::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get(['contract_id', 'ordem_servico_permissions']);
        $responsibleContractIds = OrdemServicoObraResponsavel::query()
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
                $permissions = $participant?->ordem_servico_permissions === null
                    ? ($membership->ordem_servico_permissions ?? self::defaultForRole($membership->role))
                    : $participant->ordem_servico_permissions;

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
            return [self::VIEW, self::MANAGE_DRAFTS, self::ANALYZE, self::APPROVE, self::EXECUTE];
        }

        return in_array($role, TenantRoles::administrativeRoles(), true)
            ? [self::VIEW, self::MANAGE_DRAFTS]
            : [];
    }
}

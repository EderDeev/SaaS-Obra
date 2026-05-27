<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

class RncPermissions
{
    public const CREATE = 'create_rnc';
    public const NOTIFY = 'notify_rnc';
    public const CORRECTIVE_ACTION = 'corrective_action_rnc';
    public const EDIT = 'edit_rnc';
    public const DELETE = 'delete_rnc';
    public const REVIEW = 'review_rnc';
    public const EVIDENCE = 'evidence_rnc';
    public const VIEW = 'view_rnc';
    public const DASHBOARD = 'dashboard_rnc';
    public const RESPONSIBLES = 'responsibles_rnc';

    public const LABELS = [
        self::CREATE => 'Criar RNC',
        self::NOTIFY => 'Notificar RNC',
        self::CORRECTIVE_ACTION => 'Ação corretiva RNC',
        self::EDIT => 'Editar RNC',
        self::DELETE => 'Excluir RNC',
        self::REVIEW => 'Analisar RNC',
        self::EVIDENCE => 'Evidenciar RNC',
        self::VIEW => 'Visualizar RNC',
        self::DASHBOARD => 'Dashboard RNC',
        self::RESPONSIBLES => 'Alertas RNC',
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

    public static function ownerHasAll(User $user, Tenant $tenant): bool
    {
        return $user->tenantRole($tenant) === 'tenant_owner';
    }

    public static function can(User $user, Tenant $tenant, string $permission, ?Contract $contract = null): bool
    {
        if (self::ownerHasAll($user, $tenant)) {
            return true;
        }

        if (! in_array($permission, self::all(), true)) {
            return false;
        }

        return in_array($permission, self::permissionsFor($user, $tenant, $contract), true);
    }

    public static function canAny(User $user, Tenant $tenant, string $permission): bool
    {
        return self::can($user, $tenant, $permission);
    }

    public static function permissionsFor(User $user, Tenant $tenant, ?Contract $contract = null): array
    {
        if (self::ownerHasAll($user, $tenant)) {
            return self::all();
        }

        $query = RelatorioNaoConformidadeResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active');

        if ($contract) {
            $query->where('contract_id', $contract->id);
        }

        return $query
            ->get(['permissions'])
            ->flatMap(fn (RelatorioNaoConformidadeResponsavel $responsavel): array => $responsavel->permissions ?: [])
            ->filter(fn ($permission): bool => in_array($permission, self::all(), true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, int>|null
     */
    public static function contractIdsFor(User $user, Tenant $tenant, string $permission): ?Collection
    {
        if (self::ownerHasAll($user, $tenant)) {
            return null;
        }

        return RelatorioNaoConformidadeResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get(['contract_id', 'permissions'])
            ->filter(fn (RelatorioNaoConformidadeResponsavel $responsavel): bool => in_array($permission, $responsavel->permissions ?: [], true))
            ->pluck('contract_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }
}

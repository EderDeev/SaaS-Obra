<?php

namespace App\Support;

use App\Models\Orcamento;
use App\Models\OrcamentoAcesso;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class BudgetPermissions
{
    public const VIEW = 'view_budgets';
    public const CREATE = 'create_budget';
    public const IMPORT = 'import_budget';
    public const EDIT = 'edit_budget';
    public const MANAGE_ACCESSES = 'manage_budget_accesses';
    public const FINALIZE = 'finalize_budget';
    public const MANAGE_CATALOGS = 'manage_budget_catalogs';
    public const IMPORT_CATALOGS = 'import_budget_catalogs';
    public const REPORTS = 'export_budget_reports';

    public const LABELS = [
        self::VIEW => 'Visualizar orçamentos',
        self::CREATE => 'Criar orçamento',
        self::IMPORT => 'Importar orçamento',
        self::EDIT => 'Editar orçamento',
        self::MANAGE_ACCESSES => 'Gerenciar acessos de orçamentos',
        self::FINALIZE => 'Finalizar orçamento',
        self::MANAGE_CATALOGS => 'Gerenciar bases próprias',
        self::IMPORT_CATALOGS => 'Importar bases de preço',
        self::REPORTS => 'Gerar relatórios',
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
        if ($user->is_platform_admin || $user->tenantRole($tenant) === TenantRoles::OWNER) {
            return self::all();
        }

        $membership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first(['role', 'budget_permissions']);

        if (! $membership) {
            return [];
        }

        if ($membership->budget_permissions === null) {
            return self::defaultForRole($membership->role);
        }

        return self::normalize($membership->budget_permissions);
    }

    public static function defaultForRole(?string $role): array
    {
        if (TenantRoles::isTenantAdmin($role)) {
            return self::all();
        }

        if (in_array($role, TenantRoles::managementRoles(), true)) {
            return [
                self::VIEW,
                self::CREATE,
                self::IMPORT,
                self::EDIT,
                self::FINALIZE,
                self::REPORTS,
            ];
        }

        if (in_array($role, ['coordenador_planejamento'], true)) {
            return [
                self::VIEW,
                self::CREATE,
                self::IMPORT,
                self::EDIT,
                self::FINALIZE,
                self::MANAGE_CATALOGS,
                self::IMPORT_CATALOGS,
                self::REPORTS,
            ];
        }

        if (in_array($role, ['engenheiro_planejamento', 'engenheiro_custos'], true)) {
            return [
                self::VIEW,
                self::CREATE,
                self::IMPORT,
                self::EDIT,
                self::MANAGE_CATALOGS,
                self::IMPORT_CATALOGS,
                self::REPORTS,
            ];
        }

        if (in_array($role, ['financial', 'analista_financeiro', 'analista_contratos', 'controladoria'], true)) {
            return [self::VIEW, self::REPORTS];
        }

        return [];
    }

    public static function isPrivileged(User $user, Tenant $tenant): bool
    {
        return $user->is_platform_admin
            || in_array($user->tenantRole($tenant), [TenantRoles::OWNER, TenantRoles::ADMIN], true);
    }

    public static function canViewBudget(User $user, Tenant $tenant, Orcamento $orcamento): bool
    {
        if ((int) $orcamento->tenant_id !== (int) $tenant->id) {
            return false;
        }

        if (self::isPrivileged($user, $tenant)) {
            return true;
        }

        if (! self::can($user, $tenant, self::VIEW)) {
            return false;
        }

        if ((int) $orcamento->created_by_id === (int) $user->id) {
            return true;
        }

        return OrcamentoAcesso::query()
            ->where('tenant_id', $tenant->id)
            ->where('orcamento_id', $orcamento->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    public static function canEditBudget(User $user, Tenant $tenant, Orcamento $orcamento): bool
    {
        if (! self::canViewBudget($user, $tenant, $orcamento)) {
            return false;
        }

        if (self::isPrivileged($user, $tenant)) {
            return true;
        }

        if ((int) $orcamento->created_by_id === (int) $user->id) {
            return self::can($user, $tenant, self::EDIT);
        }

        if (! self::can($user, $tenant, self::EDIT)) {
            return false;
        }

        return OrcamentoAcesso::query()
            ->where('tenant_id', $tenant->id)
            ->where('orcamento_id', $orcamento->id)
            ->where('user_id', $user->id)
            ->where('access_level', OrcamentoAcesso::LEVEL_EDIT)
            ->exists();
    }

    public static function canManageAccesses(User $user, Tenant $tenant, Orcamento $orcamento): bool
    {
        return self::isPrivileged($user, $tenant)
            || (
                (int) $orcamento->tenant_id === (int) $tenant->id
                && self::canViewBudget($user, $tenant, $orcamento)
                && (
                    (int) $orcamento->created_by_id === (int) $user->id
                    || self::can($user, $tenant, self::MANAGE_ACCESSES)
                )
            );
    }

    public static function scopeVisibleTo(Builder $query, User $user, Tenant $tenant): Builder
    {
        if (self::isPrivileged($user, $tenant)) {
            return $query;
        }

        if (! self::can($user, $tenant, self::VIEW)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query
                ->where('created_by_id', $user->id)
                ->orWhereHas('accesses', fn (Builder $accesses) => $accesses->where('user_id', $user->id));
        });
    }
}

<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\InstrucaoCampoResponsavel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

class IcPermissions
{
    public const RESPONSIBILITY_OPERATIONAL = 'operational';

    public const RESPONSIBILITY_CONTRACTOR = 'contractor';

    public const RESPONSIBILITY_MONITORING = 'monitoring';

    public const CREATE = 'create_ic';

    public const NOTIFY = 'notify_ic';

    public const CORRECTIVE_ACTION = 'correction_ic';

    public const EDIT = 'edit_ic';

    public const DELETE = 'delete_ic';

    public const REVIEW = 'close_ic';

    public const VIEW = 'view_ic';

    public const DASHBOARD = 'dashboard_ic';

    public const RESPONSIBLES = 'responsibles_ic';

    public const LABELS = [
        self::CREATE => 'Criar IC',
        self::NOTIFY => 'Notificar IC',
        self::CORRECTIVE_ACTION => 'Informar correção da IC',
        self::EDIT => 'Editar IC',
        self::DELETE => 'Excluir IC',
        self::REVIEW => 'Encerrar IC / indicar RNC',
        self::VIEW => 'Visualizar IC',
        self::DASHBOARD => 'Visualizar dashboard IC',
        self::RESPONSIBLES => 'Gerenciar responsáveis IC',
    ];

    public const RESPONSIBILITY_PROFILES = [
        self::RESPONSIBILITY_OPERATIONAL => [
            'label' => 'Engenheiro Concremat',
            'description' => 'Abre a IC, comunica os envolvidos e verifica a correção.',
            'permissions' => [
                self::CREATE,
                self::NOTIFY,
                self::REVIEW,
                self::VIEW,
            ],
        ],
        self::RESPONSIBILITY_CONTRACTOR => [
            'label' => 'Responsável da Construtora',
            'description' => 'Visualiza a instrução e registra a correção.',
            'permissions' => [
                self::CORRECTIVE_ACTION,
                self::VIEW,
            ],
        ],
        self::RESPONSIBILITY_MONITORING => [
            'label' => 'Cliente / acompanhamento',
            'description' => 'Acompanha e visualiza as ICs.',
            'permissions' => [
                self::VIEW,
            ],
        ],
    ];

    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function labels(): array
    {
        return self::LABELS;
    }

    public static function responsibilityProfiles(): array
    {
        return self::RESPONSIBILITY_PROFILES;
    }

    public static function responsibilityTypes(): array
    {
        return array_keys(self::RESPONSIBILITY_PROFILES);
    }

    public static function permissionsForResponsibility(string $type): array
    {
        return self::RESPONSIBILITY_PROFILES[$type]['permissions'] ?? [];
    }

    public static function mergeResponsibilityPermissions(string $type, array $currentPermissions): array
    {
        $profilePermissions = collect(self::RESPONSIBILITY_PROFILES)
            ->flatMap(fn (array $profile): array => $profile['permissions'])
            ->unique();

        $administrativePermissions = collect(self::normalize($currentPermissions))
            ->reject(fn (string $permission): bool => $profilePermissions->contains($permission));

        return self::normalize([
            ...self::permissionsForResponsibility($type),
            ...$administrativePermissions,
        ]);
    }

    public static function responsibilityTypeForPermissions(array $permissions): string
    {
        $permissions = self::normalize($permissions);

        if (array_intersect($permissions, [self::CREATE, self::NOTIFY, self::REVIEW])) {
            return self::RESPONSIBILITY_OPERATIONAL;
        }

        if (in_array(self::CORRECTIVE_ACTION, $permissions, true)) {
            return self::RESPONSIBILITY_CONTRACTOR;
        }

        return self::RESPONSIBILITY_MONITORING;
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

        if (! $tenant->memberships()->where('user_id', $user->id)->where('status', 'active')->exists()) {
            return [];
        }

        $query = InstrucaoCampoResponsavel::query()
            ->whereHas('contract.participants', fn ($q) => $q->where('user_id', $user->id)->where('tenant_id', $tenant->id)->where('status', 'active'))
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active');

        if ($contract) {
            $query->where('contract_id', $contract->id);
        }

        return $query
            ->get(['permissions'])
            ->flatMap(fn (InstrucaoCampoResponsavel $responsavel): array => $responsavel->permissions ?: [])
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

        if (! $tenant->memberships()->where('user_id', $user->id)->where('status', 'active')->exists()) {
            return collect();
        }

        return InstrucaoCampoResponsavel::query()
            ->whereHas('contract.participants', fn ($q) => $q->where('user_id', $user->id)->where('tenant_id', $tenant->id)->where('status', 'active'))
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get(['contract_id', 'permissions'])
            ->filter(fn (InstrucaoCampoResponsavel $responsavel): bool => in_array($permission, $responsavel->permissions ?: [], true))
            ->pluck('contract_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }
}

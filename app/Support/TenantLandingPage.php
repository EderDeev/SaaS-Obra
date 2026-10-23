<?php

namespace App\Support;

use App\Models\{Tenant, User};

class TenantLandingPage
{
    public static function url(User $user, Tenant $tenant): string
    {
        if (NavigationPermissions::can($user, $tenant, NavigationPermissions::OVERVIEW)) return route('tenant.dashboard', $tenant);
        foreach ([
            [ContractPermissions::class, ContractPermissions::VIEW, 'contracts.index'],
            [ActivityPermissions::class, ActivityPermissions::VIEW, 'activities.index'],
            [OsDnitPermissions::class, OsDnitPermissions::VIEW, 'os-dnit.orders.index'],
            [BudgetPermissions::class, BudgetPermissions::VIEW, 'orcamentos.index'],
            [MedicaoPermissions::class, MedicaoPermissions::VIEW, 'medicao.boletim-medicao.index'],
            [OrdemServicoPermissions::class, OrdemServicoPermissions::VIEW, 'ordem-servico.os.index'],
            [DiarioObraPermissions::class, DiarioObraPermissions::VIEW, 'diario-obra.rdo.calendar'],
            [DocumentationPermissions::class, DocumentationPermissions::VIEW, 'ged.index'],
            [ProjectPermissions::class, ProjectPermissions::VIEW, 'projects.index'],
            [RncPermissions::class, RncPermissions::VIEW, 'qualidade.rnc.index'],
            [IcPermissions::class, IcPermissions::VIEW, 'qualidade.ic.index'],
            [UserPermissions::class, UserPermissions::VIEW, 'users.index'],
        ] as [$permissions, $permission, $route]) {
            if ($permissions::canAny($user, $tenant, $permission)) return route('tenant.'.$route, $tenant);
        }
        if (NavigationPermissions::can($user, $tenant, NavigationPermissions::TUTORIALS)) return route('tenant.tutorials.index', $tenant);
        return route('profile.edit');
    }
}

<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class OsDnitContractAccess
{
    public static function contracts(User $user, Tenant $tenant, ?string $permission = null): Builder
    {
        // OS DNIT always requires an explicit active contract participation.
        // Tenant/platform administrator roles do not bypass this requirement.
        return Contract::query()->where('tenant_id', $tenant->id)
            ->when($permission, fn (Builder $query) => $query->whereIn('contracts.id', OsDnitPermissions::contractIds($user, $tenant, $permission)))
            ->whereHas('participants', fn (Builder $query) => $query
                ->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'active'));
    }
}

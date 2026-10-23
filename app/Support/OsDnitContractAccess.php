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
        // Only the individually authorized account can bypass participation.
        // Administrator roles alone continue to require an active link.
        return Contract::query()->where('tenant_id', $tenant->id)
            ->when($permission, fn (Builder $query) => $query->whereIn('contracts.id', OsDnitPermissions::contractIds($user, $tenant, $permission)))
            ->when(! OsDnitPermissions::hasContractExemption($user), fn (Builder $query) => $query
                ->whereHas('participants', fn (Builder $query) => $query
                    ->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'active')));
    }
}

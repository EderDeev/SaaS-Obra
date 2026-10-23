<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AccessibleTenants
{
    public static function query(User $user): Builder
    {
        return Tenant::query()->where('status', '!=', 'suspended')
            ->when(! $user->is_platform_admin, fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->whereIn('id', $user->tenantMemberships()->where('status', 'active')->select('tenant_id'))
                    ->orWhereIn('id', $user->contractParticipations()->where('status', 'active')->select('tenant_id'))));
    }

    public static function hasMultiple(User $user): bool
    {
        return self::query($user)->limit(2)->get(['id'])->count() > 1;
    }
}

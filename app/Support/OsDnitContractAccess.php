<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class OsDnitContractAccess
{
    public static function configuredContracts(User $user, Tenant $tenant, ?string $permission = null): Builder
    {
        return self::contracts($user, $tenant, $permission)->where(function (Builder $query) use ($tenant) {
            // Module-specific configuration or existing orders identify contracts using OS.
            foreach (['os_dnit_supervision_lots', 'os_dnit_highways', 'os_dnit_products', 'os_dnit_responsibles', 'os_dnit_orders'] as $table) {
                $query->orWhereExists(fn ($configured) => $configured->selectRaw('1')->from($table)
                    ->whereColumn($table.'.contract_id', 'contracts.id')->where($table.'.tenant_id', $tenant->id)
                    ->when($table === 'os_dnit_orders', fn ($orders) => $orders->whereNull('deleted_at')));
            }
        });
    }

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

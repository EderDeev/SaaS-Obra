<?php

namespace App\Services;

use App\Models\OsDnitOrderEvent;
use App\Models\OsDnitResponsible;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OsDnitPermissions;
use Illuminate\Database\Eloquent\Collection;

class OsDnitFlowRecipients
{
    public function forEvent(OsDnitOrderEvent $event): Collection
    {
        $roles = match ($event->action) {
            'submitted' => ['concremat'],
            'forwarded', 'returned', 'approved', 'completed' => ['concremat', 'contract_inspector'],
            default => [],
        };
        if ($roles === []) {
            return new Collection;
        }

        $order = $event->order;
        if (! $order) {
            return new Collection;
        }
        $tenant = Tenant::findOrFail($order->tenant_id);
        $ids = OsDnitResponsible::where('tenant_id', $order->tenant_id)->where('contract_id', $order->contract_id)
            ->where('active', true)->where(fn ($query) => $query->whereIn('role', $roles)
            ->orWhere(fn ($query) => $query->where('role', 'analyst')->where('user_id', $order->created_by_id)))
            ->pluck('user_id');

        // One recipient per user, even when they hold several workflow roles.
        return OsDnitPermissions::eligibleUsers($tenant, $order->contract_id)->whereIn('id', $ids)
            ->get()->filter(fn (User $user) => filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false
                && OsDnitPermissions::can($user, $tenant, OsDnitPermissions::VIEW, $order->contract_id))->values();
    }
}

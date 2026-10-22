<?php

namespace App\Support;

use App\Models\OsDnitOrder;
use App\Models\OsDnitResponsible;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class OsDnitWorkflowAccess
{
    public static function contracts(User $user, Tenant $tenant, string $role): Builder
    {
        $ids = OsDnitResponsible::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('role', $role)->where('active', true)->select('contract_id');
        return OsDnitContractAccess::contracts($user, $tenant, OsDnitPermissions::VIEW)->whereIn('id', $ids);
    }

    public static function hasRole(User $user, Tenant $tenant, int $contractId, string $role): bool
    {
        return self::contracts($user, $tenant, $role)->whereKey($contractId)->exists();
    }

    public static function pendingOrders(User $user, Tenant $tenant): Builder
    {
        return OsDnitOrder::where('tenant_id', $tenant->id)->where(function (Builder $query) use ($user, $tenant) {
            $query->where(fn (Builder $query) => $query->whereIn('status', ['awaiting_concremat', 'returned_concremat'])
                ->whereIn('contract_id', self::contracts($user, $tenant, 'concremat')->select('contracts.id')))
                ->orWhere(fn (Builder $query) => $query->where('status', 'awaiting_contract')
                    ->whereIn('contract_id', self::contracts($user, $tenant, 'contract_inspector')->select('contracts.id')));
        });
    }

    public static function canEdit(User $user, Tenant $tenant, OsDnitOrder $order): bool
    {
        return OsDnitPermissions::can($user, $tenant, OsDnitPermissions::EDIT, $order->contract_id)
            && $order->status === 'draft' && $order->submitted_at === null
            && self::canSubmit($user, $tenant, $order);
    }

    public static function canSubmit(User $user, Tenant $tenant, OsDnitOrder $order): bool
    {
        return (OsDnitPermissions::can($user, $tenant, OsDnitPermissions::CREATE, $order->contract_id)
                || OsDnitPermissions::can($user, $tenant, OsDnitPermissions::EDIT, $order->contract_id))
            && (int) $order->created_by_id === (int) $user->id
            && in_array($order->status, ['draft', 'returned_analyst'], true)
            && ($order->status !== 'draft' || $order->submitted_at === null)
            && self::hasRole($user, $tenant, $order->contract_id, 'analyst');
    }

    public static function hasRecipient(Tenant $tenant, int $contractId, string $role): bool
    {
        return OsDnitResponsible::where('tenant_id', $tenant->id)->where('contract_id', $contractId)
            ->where('role', $role)->where('active', true)
            ->whereHas('user.contractParticipations', fn ($query) => $query->where('tenant_id', $tenant->id)
                ->where('contract_id', $contractId)->where('status', 'active'))->with('user')->get()
            ->contains(fn ($responsible) => OsDnitPermissions::can($responsible->user, $tenant, OsDnitPermissions::VIEW, $contractId));
    }
}

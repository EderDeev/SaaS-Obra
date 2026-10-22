<?php

namespace App\Services;

use App\Models\OsDnitOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OsDnitContractAccess;

class OsDnitOrderNumber
{
    /** Call inside the order transaction; the contract row serializes concurrent creations. */
    public function next(User $user, Tenant $tenant, int $contractId): string
    {
        OsDnitContractAccess::contracts($user, $tenant)->whereKey($contractId)->lockForUpdate()->firstOrFail();

        return $this->numbers($tenant, [$contractId])[$contractId];
    }

    /** Read-only estimates; saving always recalculates under the contract lock. */
    public function previews(User $user, Tenant $tenant): array
    {
        return $this->numbers($tenant, OsDnitContractAccess::contracts($user, $tenant)->pluck('id')->all());
    }

    private function numbers(Tenant $tenant, array $contractIds): array
    {
        if ($contractIds === []) return [];

        $year = now()->year;
        $last = array_fill_keys($contractIds, 0);
        foreach (OsDnitOrder::withTrashed()->where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds)
            ->where('request_number', 'like', '%/'.$year)->select(['contract_id', 'request_number'])->cursor() as $order) {
            if (preg_match('/^(\d+)\/'.$year.'$/D', $order->request_number, $match)) {
                $last[$order->contract_id] = max($last[$order->contract_id], (int) $match[1]);
            }
        }

        return array_map(fn (int $number) => sprintf('%03d/%d', $number + 1, $year), $last);
    }
}

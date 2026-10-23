<?php

namespace App\Support;

use App\Models\OsDnitOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OsDnitManagementSummary
{
    private static function orders(Tenant $tenant, Collection $contractIds): Builder
    {
        return OsDnitOrder::where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds);
    }

    public static function forUser(User $user, Tenant $tenant, ?int $contractId = null): ?array
    {
        $ids = OsDnitContractAccess::contracts($user, $tenant, OsDnitPermissions::VIEW)
            ->when($contractId !== null, fn (Builder $query) => $query->whereKey($contractId))->pluck('contracts.id');
        if ($ids->isEmpty()) {
            return null;
        }

        $orders = self::orders($tenant, $ids);
        $counts = (clone $orders)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $stages = collect(OsDnitOrder::STAGES)->map(fn (array $statuses): int => (int) $counts->only($statuses)->sum())->all();

        return [
            'total' => (int) $counts->sum(),
            'stages' => $stages,
            'pending' => $stages['concremat'] + $stages['contract_inspector'],
            'returned' => (int) $counts->only(['returned_analyst', 'returned_concremat'])->sum(),
            'overdue' => (clone $orders)->whereNotIn('status', ['draft', 'completed'])
                ->whereRaw('COALESCE(estimated_deadline, desired_deadline) < ?', [today()->toDateString()])->count(),
            'recent' => (clone $orders)->with('contract:id,code')->orderByDesc('updated_at')->orderByDesc('id')->limit(4)
                ->get(['id', 'contract_id', 'request_number', 'subject', 'status', 'updated_at'])
                ->map(fn (OsDnitOrder $order): array => [
                    'id' => $order->id, 'number' => $order->request_number, 'subject' => $order->subject,
                    'status' => $order->status, 'status_label' => OsDnitOrder::STATUSES[$order->status] ?? $order->status,
                    'contract' => $order->contract?->code,
                ])->all(),
        ];
    }

    public static function forContracts(User $user, Tenant $tenant, Collection $contractIds): Collection
    {
        $ids = OsDnitContractAccess::contracts($user, $tenant, OsDnitPermissions::VIEW)
            ->whereIn('contracts.id', $contractIds)->pluck('contracts.id');
        $counts = self::orders($tenant, $ids)->selectRaw('contract_id, status, COUNT(*) AS total')
            ->groupBy('contract_id', 'status')->get()->groupBy('contract_id');

        return $ids->mapWithKeys(function ($id) use ($counts): array {
            $statuses = ($counts->get($id) ?? collect())->pluck('total', 'status');

            return [$id => [
                'total' => (int) $statuses->sum(),
                'pending' => (int) $statuses->only([...OsDnitOrder::STAGES['concremat'], ...OsDnitOrder::STAGES['contract_inspector']])->sum(),
                'completed' => (int) ($statuses['completed'] ?? 0),
            ]];
        });
    }
}

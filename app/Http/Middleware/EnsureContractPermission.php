<?php

namespace App\Http\Middleware;

use App\Models\Contract;
use App\Models\Tenant;
use App\Support\ContractPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureContractPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $tenant = $request->attributes->get('tenant');
        $permissions = $permissions ?: [ContractPermissions::VIEW];

        abort_unless($tenant instanceof Tenant, 404);
        abort_unless($request->user(), 403);

        $routeContract = $request->route('contract');
        $contract = $routeContract instanceof Contract
            ? $routeContract
            : ($request->integer('contract_id')
                ? Contract::query()->where('tenant_id', $tenant->id)->find($request->integer('contract_id'))
                : null);

        if ($contract) {
            abort_unless((int) $contract->tenant_id === (int) $tenant->id, 404);
        }

        $allowed = collect($permissions)->contains(fn (string $permission): bool => $contract
            ? ContractPermissions::can($request->user(), $tenant, $permission, $contract)
            : ContractPermissions::canAny($request->user(), $tenant, $permission));

        abort_unless($allowed, 403);

        return $next($request);
    }
}

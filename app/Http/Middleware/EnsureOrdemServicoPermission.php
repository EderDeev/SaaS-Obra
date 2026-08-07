<?php

namespace App\Http\Middleware;

use App\Models\Contract;
use App\Models\OrdemServico;
use App\Models\OrdemServicoObraResponsavel;
use App\Models\Tenant;
use App\Support\OrdemServicoPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrdemServicoPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $tenant = $request->attributes->get('tenant');
        $permissions = $permissions ?: [OrdemServicoPermissions::VIEW];

        abort_unless($tenant instanceof Tenant, 404);
        abort_unless($request->user(), 403);

        $contract = $this->routeContract($request, $tenant);
        $allowed = collect($permissions)->contains(fn (string $permission): bool => $contract
            ? OrdemServicoPermissions::can($request->user(), $tenant, $permission, $contract)
            : OrdemServicoPermissions::canAny($request->user(), $tenant, $permission));

        abort_unless($allowed, 403);

        return $next($request);
    }

    private function routeContract(Request $request, Tenant $tenant): ?Contract
    {
        $routeModel = collect(['ordem', 'responsavel'])
            ->map(fn (string $key) => $request->route($key))
            ->first(fn ($value) => is_object($value));

        $contractId = match (true) {
            $routeModel instanceof OrdemServico,
            $routeModel instanceof OrdemServicoObraResponsavel => $routeModel->contract_id,
            default => $request->integer('contract_id') ?: null,
        };

        return $contractId
            ? Contract::query()->where('tenant_id', $tenant->id)->find($contractId)
            : null;
    }
}

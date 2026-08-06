<?php

namespace App\Http\Middleware;

use App\Models\Contract;
use App\Models\RdaApontamento;
use App\Models\RdoConfiguracao;
use App\Models\RdoDiario;
use App\Models\RdoResponsavel;
use App\Models\Tenant;
use App\Support\DiarioObraPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDiarioObraPermission
{
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $tenant = $request->attributes->get('tenant');
        $permission ??= DiarioObraPermissions::VIEW;

        abort_unless($tenant instanceof Tenant, 404);
        abort_unless($request->user(), 403);

        $contract = $this->routeContract($request, $tenant);

        abort_unless(
            $contract
                ? DiarioObraPermissions::can($request->user(), $tenant, $permission, $contract)
                : DiarioObraPermissions::canAny($request->user(), $tenant, $permission),
            403
        );

        return $next($request);
    }

    private function routeContract(Request $request, Tenant $tenant): ?Contract
    {
        $routeModel = collect(['rdo', 'rda', 'responsavel'])
            ->map(fn (string $key) => $request->route($key))
            ->first(fn ($value) => is_object($value));

        $contractId = match (true) {
            $routeModel instanceof RdoDiario,
            $routeModel instanceof RdaApontamento,
            $routeModel instanceof RdoResponsavel => $routeModel->contract_id,
            default => $request->integer('contract_id') ?: null,
        };

        if (! $contractId && $request->filled('configuration_id')) {
            $contractId = RdoConfiguracao::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($request->integer('configuration_id'))
                ->value('contract_id');
        }

        return $contractId
            ? Contract::query()->where('tenant_id', $tenant->id)->find($contractId)
            : null;
    }
}

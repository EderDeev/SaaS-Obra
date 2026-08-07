<?php

namespace App\Http\Middleware;

use App\Models\BoletimMedicao;
use App\Models\Contract;
use App\Models\FolhaRosto;
use App\Models\MedicaoIndiceReajuste;
use App\Models\MedicaoItem;
use App\Models\OrdemServico;
use App\Models\Tenant;
use App\Support\MedicaoPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMedicaoPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $tenant = $request->attributes->get('tenant');
        $permissions = $permissions ?: [MedicaoPermissions::VIEW];

        abort_unless($tenant instanceof Tenant, 404);
        abort_unless($request->user(), 403);

        $contract = $this->routeContract($request, $tenant);
        $allowed = collect($permissions)->contains(fn (string $permission): bool => $contract
            ? MedicaoPermissions::can($request->user(), $tenant, $permission, $contract)
            : MedicaoPermissions::canAny($request->user(), $tenant, $permission));

        abort_unless($allowed, 403);

        return $next($request);
    }

    private function routeContract(Request $request, Tenant $tenant): ?Contract
    {
        $model = collect(['contract', 'ordem', 'folha', 'boletim', 'indice', 'item'])
            ->map(fn (string $key) => $request->route($key))
            ->first(fn ($value) => is_object($value));

        $contractId = match (true) {
            $model instanceof Contract => $model->id,
            $model instanceof OrdemServico,
            $model instanceof FolhaRosto,
            $model instanceof BoletimMedicao,
            $model instanceof MedicaoIndiceReajuste,
            $model instanceof MedicaoItem => $model->contract_id,
            $request->integer('contract_id') > 0 => $request->integer('contract_id'),
            $request->integer('boletim_id') > 0 => BoletimMedicao::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($request->integer('boletim_id'))
                ->value('contract_id'),
            default => null,
        };

        return $contractId
            ? Contract::query()->where('tenant_id', $tenant->id)->find($contractId)
            : null;
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\OsDnitContractAccess;
use App\Support\OsDnitPermissions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EnsureOsDnitPermission
{
    public function handle(Request $request, Closure $next, string $permission = OsDnitPermissions::VIEW)
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant && $request->user(), 404);
        $model = $request->route('order') ?? $request->route('product') ?? $request->route('highway') ?? $request->route('lot');
        if (is_object($model)) {
            abort_unless((int) $model->tenant_id === (int) $tenant->id, 404);
            abort_unless(OsDnitContractAccess::contracts($request->user(), $tenant)->whereKey($model->contract_id)->exists(), 404);
            abort_unless(OsDnitPermissions::can($request->user(), $tenant, $permission, (int) $model->contract_id), 403);
        } else {
            abort_unless(OsDnitPermissions::canAny($request->user(), $tenant, $permission), 403);
            if ($request->isMethod('post') && $request->filled('contract_id')) {
                if (! OsDnitPermissions::can($request->user(), $tenant, $permission, $request->integer('contract_id'))) {
                    throw ValidationException::withMessages(['contract_id' => 'Você não possui permissão para esta ação no contrato selecionado.']);
                }
            }
        }

        return $next($request);
    }
}

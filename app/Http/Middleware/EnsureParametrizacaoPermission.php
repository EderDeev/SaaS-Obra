<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\ParametrizacaoPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureParametrizacaoPermission
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $tenant = $request->attributes->get('tenant');
        $permission ??= ParametrizacaoPermissions::VIEW;

        abort_unless($tenant instanceof Tenant, 404);
        abort_unless($request->user(), 403);
        abort_unless(ParametrizacaoPermissions::can($request->user(), $tenant, ParametrizacaoPermissions::VIEW), 403);
        abort_unless(ParametrizacaoPermissions::can($request->user(), $tenant, $permission), 403);

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->route('tenant');

        if (! $tenant instanceof Tenant) {
            $host = $request->getHost();
            $rootDomain = config('app.root_domain');
            $reserved = ['admin', 'api', 'app', 'www', 'mail', 'support', 'localhost', '127'];

            if ($rootDomain && str_ends_with($host, $rootDomain)) {
                $subdomain = str($host)->before('.'.$rootDomain)->toString();

                if ($subdomain && ! in_array($subdomain, $reserved, true)) {
                    $tenant = Tenant::where('slug', $subdomain)->firstOrFail();
                }
            }
        }

        abort_if(! $tenant instanceof Tenant, 404);
        abort_if($tenant->status === 'suspended', 403, 'Empresa suspensa.');

        $request->attributes->set('tenant', $tenant);
        app()->instance('currentTenant', $tenant);

        if ($request->hasSession()) {
            $request->session()->put('current_tenant_id', $tenant->id);
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select("select set_config('app.current_tenant', ?, false)", [(string) $tenant->id]);
        }

        return $next($request);
    }
}

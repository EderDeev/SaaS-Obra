<?php

namespace App\Http\Middleware;

use App\Models\MobileApiToken;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();

        if (! $plainToken) {
            return response()->json(['message' => 'Token mobile ausente.'], 401);
        }

        $token = MobileApiToken::query()
            ->with(['user', 'tenant'])
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (! $token || ! $token->tenant || ($token->expires_at && $token->expires_at->isPast())) {
            return response()->json(['message' => 'Token mobile inválido ou expirado.'], 401);
        }

        $tenant = $token->tenant;

        if (! $token->user || ! $token->user->hasTenantAccess($tenant)) {
            return response()->json(['message' => 'Usuário sem acesso ao tenant.'], 403);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('mobile_tenant', $tenant);

        return $next($request);
    }
}

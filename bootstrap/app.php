<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'platform.admin' => \App\Http\Middleware\EnsurePlatformAdmin::class,
            'tenant.resolve' => \App\Http\Middleware\ResolveTenant::class,
            'tenant.access' => \App\Http\Middleware\EnsureTenantAccess::class,
            'tenant.admin' => \App\Http\Middleware\EnsureTenantAdmin::class,
            'parametrizacao.permission' => \App\Http\Middleware\EnsureParametrizacaoPermission::class,
            'documentation.permission' => \App\Http\Middleware\EnsureDocumentationPermission::class,
            'diario.permission' => \App\Http\Middleware\EnsureDiarioObraPermission::class,
            'ordem-servico.permission' => \App\Http\Middleware\EnsureOrdemServicoPermission::class,
            'medicao.permission' => \App\Http\Middleware\EnsureMedicaoPermission::class,
            'contract.permission' => \App\Http\Middleware\EnsureContractPermission::class,
            'password.changed' => \App\Http\Middleware\EnsurePasswordWasChanged::class,
            'mobile.token' => \App\Http\Middleware\EnsureMobileApiToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

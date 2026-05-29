<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
            'password.changed' => \App\Http\Middleware\EnsurePasswordWasChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

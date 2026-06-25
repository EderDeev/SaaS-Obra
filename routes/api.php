<?php

use App\Http\Controllers\Api\OpenSignWebhookController;
use App\Http\Controllers\Api\Mobile\MobileAuthController;
use App\Http\Controllers\Api\Mobile\MobileRncController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/opensign', OpenSignWebhookController::class)->name('api.webhooks.opensign');

Route::prefix('mobile')
    ->name('api.mobile.')
    ->group(function (): void {
        Route::post('/login', [MobileAuthController::class, 'login'])->name('login');

        Route::middleware('mobile.token')->group(function (): void {
            Route::get('/bootstrap', [MobileRncController::class, 'bootstrap'])->name('bootstrap');
            Route::post('/rnc/offline', [MobileRncController::class, 'storeOffline'])->name('rnc.offline.store');
        });
    });

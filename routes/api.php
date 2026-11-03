<?php

use App\Http\Controllers\Api\OpenSignWebhookController;
use App\Http\Controllers\Api\Mobile\MobileAuthController;
use App\Http\Controllers\Api\Mobile\MobileRdoController;
use App\Http\Controllers\Api\Mobile\MobileRncController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/opensign', OpenSignWebhookController::class)->name('api.webhooks.opensign');

Route::prefix('mobile')
    ->name('api.mobile.')
    ->group(function (): void {
        Route::post('/login', [MobileAuthController::class, 'login'])->name('login');

        Route::middleware('mobile.token')->group(function (): void {
            Route::post('/tenant', [MobileAuthController::class, 'switchTenant'])->name('tenant.switch');
            Route::get('/bootstrap', [MobileRncController::class, 'bootstrap'])->name('bootstrap');
            Route::post('/rnc/offline', [MobileRncController::class, 'storeOffline'])->name('rnc.offline.store');
            Route::post('/rdo/offline', [MobileRdoController::class, 'storeOffline'])->name('rdo.offline.store');
            Route::post('/rdo/{rdoId}/submit', [MobileRdoController::class, 'submit'])->whereNumber('rdoId')->name('rdo.submit');
            Route::post('/rda/offline', fn () => abort(410, 'O RDA foi descontinuado. Atualize o aplicativo para preencher o RDO.'))->name('rda.offline.store');
        });
    });

<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Symfony\Component\Mailer\Transport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('brevo', function (array $config) {
            $apiKey = trim((string) ($config['key'] ?? ''));

            if ($apiKey === '') {
                throw new InvalidArgumentException(
                    'BREVO_API_KEY não está configurada. Use uma chave de API v3 da Brevo.'
                );
            }

            return Transport::fromDsn(
                'brevo+api://'.rawurlencode($apiKey).'@default'
            );
        });

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Carbon::setLocale(config('app.locale'));
        setlocale(LC_TIME, 'pt_BR.UTF-8', 'pt_BR', 'Portuguese_Brazil.1252', 'Portuguese_Brazil');

        Vite::prefetch(concurrency: 3);
    }
}

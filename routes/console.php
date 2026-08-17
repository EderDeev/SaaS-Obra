<?php

use App\Models\RdoConfiguracao;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('rdo:generate-daily')
    ->dailyAt(RdoConfiguracao::GENERATION_TIME)
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();

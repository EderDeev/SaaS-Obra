<?php

namespace App\Console\Commands;

use App\Services\RdoDailyGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateDailyRdos extends Command
{
    protected $signature = 'rdo:generate-daily {--date= : Data de referência no formato AAAA-MM-DD}';

    protected $description = 'Gera os RDOs configurados para a data informada ou para hoje.';

    public function handle(RdoDailyGenerator $generator): int
    {
        $created = $this->option('date')
            ? $generator->generateForDate(
                CarbonImmutable::createFromFormat('Y-m-d', (string) $this->option('date'))->startOfDay(),
            )
            : $generator->generateDue(CarbonImmutable::now('UTC'));
        $this->info("RDOs criados: {$created}.");

        return self::SUCCESS;
    }
}

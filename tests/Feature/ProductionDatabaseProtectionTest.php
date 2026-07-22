<?php

namespace Tests\Feature;

use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

class ProductionDatabaseProtectionTest extends TestCase
{
    public function test_destructive_commands_cannot_bypass_the_guard_with_force(): void
    {
        DB::prohibitDestructiveCommands();
        SeedCommand::prohibit();

        try {
            $this->artisan('db:wipe', ['--force' => true])
                ->expectsOutputToContain('This command is prohibited from running in this environment.')
                ->assertExitCode(Command::FAILURE);

            $this->artisan('migrate:fresh', ['--force' => true])
                ->expectsOutputToContain('This command is prohibited from running in this environment.')
                ->assertExitCode(Command::FAILURE);

            $this->artisan('migrate:rollback', ['--force' => true])
                ->expectsOutputToContain('This command is prohibited from running in this environment.')
                ->assertExitCode(Command::FAILURE);

            $this->artisan('db:seed', ['--force' => true])
                ->expectsOutputToContain('This command is prohibited from running in this environment.')
                ->assertExitCode(Command::FAILURE);
        } finally {
            DB::prohibitDestructiveCommands(false);
            SeedCommand::prohibit(false);
        }
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE orcamento_etapas ALTER COLUMN ordem TYPE VARCHAR(40) USING ordem::varchar');

            return;
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE orcamento_etapas MODIFY ordem VARCHAR(40) NOT NULL');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE orcamento_etapas ALTER COLUMN ordem TYPE INTEGER USING NULLIF(split_part(ordem, '.', 1), '')::integer");

            return;
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE orcamento_etapas MODIFY ordem INT UNSIGNED NOT NULL');
        }
    }
};

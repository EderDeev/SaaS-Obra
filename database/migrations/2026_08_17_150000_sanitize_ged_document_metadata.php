<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE ged_documents
            SET metadata = replace(metadata::text, chr(92) || 'u0000', '')::json
            WHERE position(chr(92) || 'u0000' in metadata::text) > 0
        SQL);
    }

    public function down(): void
    {
        // Removed null characters cannot be reconstructed safely.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE empresas
            SET contract_id = (
                SELECT contracts.id
                FROM contracts
                WHERE contracts.tenant_id = empresas.tenant_id
                ORDER BY contracts.id
                LIMIT 1
            )
            WHERE contract_id IS NULL
              AND EXISTS (
                SELECT 1
                FROM contracts
                WHERE contracts.tenant_id = empresas.tenant_id
              )
        SQL);

        DB::statement(<<<'SQL'
            UPDATE obras
            SET contract_id = (
                SELECT contracts.id
                FROM contracts
                WHERE contracts.tenant_id = obras.tenant_id
                ORDER BY contracts.id
                LIMIT 1
            )
            WHERE contract_id IS NULL
              AND EXISTS (
                SELECT 1
                FROM contracts
                WHERE contracts.tenant_id = obras.tenant_id
              )
        SQL);
    }
};

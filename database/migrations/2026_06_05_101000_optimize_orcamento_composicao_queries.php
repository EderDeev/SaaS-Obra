<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS orc_comp_tenant_list_idx ON orcamento_composicoes (tenant_id, modelo, uf, tipo_composicao, codigo) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX IF NOT EXISTS orc_comp_global_list_idx ON orcamento_composicoes (modelo, uf, tipo_composicao, codigo) WHERE deleted_at IS NULL AND is_global = true');
            DB::statement('CREATE INDEX IF NOT EXISTS orc_comp_items_parent_sum_idx ON orcamento_composicao_items (orcamento_composicao_id) WHERE deleted_at IS NULL');

            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
                DB::statement('CREATE INDEX IF NOT EXISTS orc_comp_descricao_trgm_idx ON orcamento_composicoes USING gin (lower(descricao) gin_trgm_ops) WHERE deleted_at IS NULL');
                DB::statement('CREATE INDEX IF NOT EXISTS orc_comp_codigo_trgm_idx ON orcamento_composicoes USING gin (lower(codigo) gin_trgm_ops) WHERE deleted_at IS NULL');
            } catch (Throwable) {
                // Some managed Postgres plans may block extensions. The scoped btree indexes above are still useful.
            }

            return;
        }

        Schema::table('orcamento_composicoes', function (Blueprint $table): void {
            $table->index(['tenant_id', 'modelo', 'uf', 'tipo_composicao', 'codigo'], 'orc_comp_tenant_list_idx');
            $table->index(['is_global', 'modelo', 'uf', 'tipo_composicao', 'codigo'], 'orc_comp_global_list_idx');
        });

        Schema::table('orcamento_composicao_items', function (Blueprint $table): void {
            $table->index('orcamento_composicao_id', 'orc_comp_items_parent_sum_idx');
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS orc_comp_codigo_trgm_idx');
            DB::statement('DROP INDEX IF EXISTS orc_comp_descricao_trgm_idx');
            DB::statement('DROP INDEX IF EXISTS orc_comp_items_parent_sum_idx');
            DB::statement('DROP INDEX IF EXISTS orc_comp_global_list_idx');
            DB::statement('DROP INDEX IF EXISTS orc_comp_tenant_list_idx');

            return;
        }

        Schema::table('orcamento_composicoes', function (Blueprint $table): void {
            $table->dropIndex('orc_comp_tenant_list_idx');
            $table->dropIndex('orc_comp_global_list_idx');
        });

        Schema::table('orcamento_composicao_items', function (Blueprint $table): void {
            $table->dropIndex('orc_comp_items_parent_sum_idx');
        });
    }
};

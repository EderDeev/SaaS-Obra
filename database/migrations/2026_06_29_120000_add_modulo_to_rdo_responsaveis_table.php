<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rdo_responsaveis', function (Blueprint $table) {
            $table->string('modulo', 10)->default('rdo')->after('created_by_id');
        });

        DB::table('rdo_responsaveis')->whereNull('modulo')->update(['modulo' => 'rdo']);

        Schema::table('rdo_responsaveis', function (Blueprint $table) {
            $table->dropUnique('rdo_resp_unique');
            $table->dropIndex('rdo_resp_lookup');
            $table->unique(['tenant_id', 'modulo', 'obra_id', 'user_id', 'etapa'], 'rdo_resp_mod_unique');
            $table->index(['tenant_id', 'modulo', 'contract_id', 'obra_id', 'etapa'], 'rdo_resp_mod_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('rdo_responsaveis', function (Blueprint $table) {
            $table->dropUnique('rdo_resp_mod_unique');
            $table->dropIndex('rdo_resp_mod_lookup');
            $table->unique(['tenant_id', 'obra_id', 'user_id', 'etapa'], 'rdo_resp_unique');
            $table->index(['tenant_id', 'contract_id', 'obra_id', 'etapa'], 'rdo_resp_lookup');
            $table->dropColumn('modulo');
        });
    }
};

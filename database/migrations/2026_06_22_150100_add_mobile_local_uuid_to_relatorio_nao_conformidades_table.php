<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table): void {
            $table->string('mobile_local_uuid')->nullable()->after('tenant_id');
            $table->unique(['tenant_id', 'mobile_local_uuid'], 'rnc_tenant_mobile_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('relatorio_nao_conformidades', function (Blueprint $table): void {
            $table->dropUnique('rnc_tenant_mobile_uuid_unique');
            $table->dropColumn('mobile_local_uuid');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rdo_configuracoes', function (Blueprint $table) {
            $table->foreignId('report_company_id')->nullable()->constrained('empresas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migração somente de avanço; preserve as empresas configuradas.');
    }
};

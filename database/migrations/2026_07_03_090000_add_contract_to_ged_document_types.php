<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ged_document_types', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'name']);
            $table->foreignId('contract_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->unique(['tenant_id', 'contract_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('ged_document_types', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'contract_id', 'name']);
            $table->dropConstrainedForeignId('contract_id');
            $table->unique(['tenant_id', 'name']);
        });
    }
};

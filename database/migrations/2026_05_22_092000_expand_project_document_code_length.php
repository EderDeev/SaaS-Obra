<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_documents', function (Blueprint $table): void {
            $table->string('code', 160)->nullable()->change();
            $table->index(['tenant_id', 'obra_id'], 'project_documents_tenant_obra_index');
        });
    }

    public function down(): void
    {
        Schema::table('project_documents', function (Blueprint $table): void {
            $table->dropIndex('project_documents_tenant_obra_index');
            $table->string('code', 80)->nullable()->change();
        });
    }
};

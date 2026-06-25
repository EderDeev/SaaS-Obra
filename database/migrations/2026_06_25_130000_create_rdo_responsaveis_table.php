<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rdo_responsaveis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('obra_id')->constrained('obras')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('etapa', 30);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'obra_id', 'user_id', 'etapa'], 'rdo_resp_unique');
            $table->index(['tenant_id', 'contract_id', 'obra_id', 'etapa'], 'rdo_resp_lookup');
        });

        Schema::table('rdo_analises', function (Blueprint $table) {
            $table->foreignId('obra_id')
                ->nullable()
                ->after('rdo_diario_id')
                ->constrained('obras')
                ->nullOnDelete();
            $table->index(['rdo_diario_id', 'obra_id', 'etapa'], 'rdo_analysis_front_stage');
        });
    }

    public function down(): void
    {
        Schema::table('rdo_analises', function (Blueprint $table) {
            $table->dropIndex('rdo_analysis_front_stage');
            $table->dropConstrainedForeignId('obra_id');
        });

        Schema::dropIfExists('rdo_responsaveis');
    }
};

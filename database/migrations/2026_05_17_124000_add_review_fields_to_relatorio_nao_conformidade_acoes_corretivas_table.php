<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorio_nao_conformidade_acoes_corretivas', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('submitted_at');
            $table->text('review_observation')->nullable()->after('status');
            $table->timestamp('reviewed_at')->nullable()->after('review_observation');
            $table->foreignId('reviewed_by_id')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('relatorio_nao_conformidade_acoes_corretivas', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropConstrainedForeignId('reviewed_by_id');
            $table->dropColumn(['status', 'review_observation', 'reviewed_at']);
        });
    }
};

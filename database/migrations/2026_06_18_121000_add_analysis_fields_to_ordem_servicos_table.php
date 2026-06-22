<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordem_servicos', function (Blueprint $table) {
            $table->timestamp('submitted_for_review_at')->nullable()->after('status');
            $table->foreignId('submitted_for_review_by_id')->nullable()->after('submitted_for_review_at')->constrained('users')->nullOnDelete();
            $table->timestamp('analyzed_at')->nullable()->after('submitted_for_review_by_id');
            $table->foreignId('analyzed_by_id')->nullable()->after('analyzed_at')->constrained('users')->nullOnDelete();
            $table->text('analysis_observation')->nullable()->after('analyzed_by_id');
            $table->timestamp('approval_decided_at')->nullable()->after('analysis_observation');
            $table->foreignId('approval_decided_by_id')->nullable()->after('approval_decided_at')->constrained('users')->nullOnDelete();
            $table->text('approval_observation')->nullable()->after('approval_decided_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('ordem_servicos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_decided_by_id');
            $table->dropConstrainedForeignId('analyzed_by_id');
            $table->dropConstrainedForeignId('submitted_for_review_by_id');
            $table->dropColumn([
                'submitted_for_review_at',
                'analyzed_at',
                'analysis_observation',
                'approval_decided_at',
                'approval_observation',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('ai_monthly_token_limit')->nullable()->after('settings');
        });

        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->unsignedBigInteger('ai_monthly_token_limit')->nullable()->after('status');
        });

        Schema::create('ai_monthly_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->unsignedInteger('requests_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'period'], 'ai_monthly_usage_owner_period_unique');
            $table->index(['tenant_id', 'period'], 'ai_monthly_usage_tenant_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_monthly_usages');

        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropColumn('ai_monthly_token_limit');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('ai_monthly_token_limit');
        });
    }
};

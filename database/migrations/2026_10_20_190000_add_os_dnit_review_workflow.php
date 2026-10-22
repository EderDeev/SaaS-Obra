<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('os_dnit_responsibles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 30);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'contract_id', 'user_id', 'role'], 'os_dnit_responsible_unique');
        });
        Schema::table('os_dnit_orders', function (Blueprint $table) {
            $table->unsignedInteger('workflow_version')->default(1);
            $table->timestamp('submitted_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->date('estimated_deadline')->nullable();
            $table->json('review_items')->nullable();
            $table->foreignId('analyzed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
        });
        Schema::create('os_dnit_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('os_dnit_orders')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('comment')->nullable();
            $table->json('snapshot');
            $table->timestamps();
        });
        // Earlier registrations had no approval meaning. Preserve them as editable drafts.
        DB::table('os_dnit_orders')->where('status', 'registered')->update(['status' => 'draft']);
    }

    public function down(): void
    {
        throw new RuntimeException('Migração somente para frente: preserve o histórico de análise das OSs.');
    }
};

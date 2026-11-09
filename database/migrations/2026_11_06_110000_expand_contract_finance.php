<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', fn (Blueprint $table) => $table->string('contract_type', 20)->nullable());
        Schema::table('contract_finance_months', function (Blueprint $table) {
            $table->decimal('revenue_amount', 15, 2)->nullable();
            $table->decimal('operating_cost', 15, 2)->nullable();
            $table->decimal('measured_amount', 15, 2)->nullable();
            $table->unsignedInteger('revision')->default(1);
        });
        Schema::create('contract_finance_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id');
            $table->date('reference_month');
            $table->date('received_date');
            $table->decimal('received_amount', 15, 2);
            $table->foreign(['contract_id', 'reference_month'], 'finance_receipts_month_fk')
                ->references(['contract_id', 'reference_month'])->on('contract_finance_months')->cascadeOnDelete();
            $table->index(['contract_id', 'reference_month', 'received_date'], 'finance_receipts_month_index');
        });
        DB::table('contract_finance_receipts')->insertUsing(
            ['contract_id', 'reference_month', 'received_date', 'received_amount'],
            DB::table('contract_finance_months')->select('contract_id', 'reference_month', 'received_date', 'received_amount')
                ->whereNotNull('received_date')->whereNotNull('received_amount'),
        );
        Schema::create('contract_finance_targets', function (Blueprint $table) {
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('revenue_target', 15, 2)->nullable();
            $table->decimal('margin_target', 5, 2)->nullable();
            $table->primary(['contract_id', 'year']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migração somente de avanço; preserve os registros financeiros.');
    }
};

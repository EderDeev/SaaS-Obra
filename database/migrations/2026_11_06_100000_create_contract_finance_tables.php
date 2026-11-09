<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_finance_baselines', function (Blueprint $table) {
            $table->foreignId('contract_id')->primary()->constrained()->cascadeOnDelete();
            $table->decimal('expected_amount', 15, 2);
            $table->date('expected_date');
            $table->timestamps();
        });

        Schema::create('contract_finance_months', function (Blueprint $table) {
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->date('reference_month');
            $table->decimal('billed_amount', 15, 2)->nullable();
            $table->date('billed_date')->nullable();
            $table->decimal('received_amount', 15, 2)->nullable();
            $table->date('received_date')->nullable();
            $table->timestamps();
            $table->primary(['contract_id', 'reference_month']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migração somente de avanço; preserve os registros financeiros.');
    }
};

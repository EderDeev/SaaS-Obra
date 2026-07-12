<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_additives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('type', 30);
            $table->string('title');
            $table->text('motivation');
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('previous_total_value', 15, 2)->nullable();
            $table->decimal('new_total_value', 15, 2)->nullable();
            $table->unsignedInteger('deadline_days')->nullable();
            $table->date('previous_ends_at')->nullable();
            $table->date('new_ends_at')->nullable();
            $table->string('attachment_path');
            $table->string('attachment_original_name');
            $table->string('attachment_mime_type', 120)->nullable();
            $table->unsignedBigInteger('attachment_size')->default(0);
            $table->timestamps();

            $table->unique(['contract_id', 'sequence_number']);
            $table->index(['tenant_id', 'contract_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_additives');
    }
};

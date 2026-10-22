<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('os_dnit_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('highway_id')->constrained('os_dnit_highways')->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->string('request_number', 80);
            $table->string('supervision_lot', 120);
            $table->string('highway_name');
            $table->decimal('km_start', 10, 3);
            $table->decimal('km_end', 10, 3);
            $table->string('subject');
            $table->date('desired_deadline');
            $table->text('technical_need');
            $table->text('justification');
            $table->string('sei_reference')->nullable();
            $table->string('status', 30)->default('registered');
            $table->timestamps();
            $table->unique(['tenant_id', 'contract_id', 'request_number'], 'os_dnit_order_number_unique');
            $table->index(['tenant_id', 'contract_id', 'created_at']);
        });

        Schema::create('os_dnit_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('os_dnit_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('os_dnit_products')->restrictOnDelete();
            $table->string('product_code', 40);
            $table->string('product_name');
            $table->string('unit', 20);
            $table->string('frequency', 20);
            $table->decimal('quantity', 15, 3);
            $table->timestamps();
            $table->unique(['order_id', 'product_id']);
        });

        Schema::create('os_dnit_order_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('os_dnit_orders')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('storage_disk', 80);
            $table->string('path');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('os_dnit_order_attachments');
        Schema::dropIfExists('os_dnit_order_items');
        Schema::dropIfExists('os_dnit_orders');
    }
};

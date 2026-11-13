<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('os_dnit_order_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('os_dnit_orders');
            $table->unsignedInteger('revision');
            $table->foreignId('created_by_id')->constrained('users');
            $table->string('storage_disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->text('comment');
            $table->string('decision')->nullable();
            $table->text('review_comment')->nullable();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('os_dnit_order_deliveries');
    }
};

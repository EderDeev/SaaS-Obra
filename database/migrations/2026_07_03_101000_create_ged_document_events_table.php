<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ged_document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('ged_documents')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 80)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'document_id', 'created_at'], 'ged_document_events_tenant_document_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ged_document_events');
    }
};

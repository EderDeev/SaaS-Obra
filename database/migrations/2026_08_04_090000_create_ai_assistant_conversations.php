<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'last_message_at'], 'ai_conversations_owner_last_idx');
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->json('sources')->nullable();
            $table->json('usage')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at'], 'ai_messages_conversation_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};

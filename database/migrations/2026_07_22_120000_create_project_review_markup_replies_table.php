<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_review_markup_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_review_markup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('resolves_markup')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'project_review_markup_id'], 'project_markup_replies_tenant_markup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_review_markup_replies');
    }
};

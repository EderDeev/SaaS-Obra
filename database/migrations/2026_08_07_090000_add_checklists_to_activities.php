<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->string('activity_type', 20)->default('activity')->after('description');
            $table->index(['tenant_id', 'activity_type']);
        });

        Schema::create('activity_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('label', 500);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_completed')->default(false);
            $table->foreignId('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['activity_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_checklist_items');

        Schema::table('activities', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'activity_type']);
            $table->dropColumn('activity_type');
        });
    }
};

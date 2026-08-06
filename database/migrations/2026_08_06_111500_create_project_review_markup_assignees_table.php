<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_review_markup_assignees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_review_markup_id')->constrained('project_review_markups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_review_markup_id', 'user_id'], 'project_markup_assignee_unique');
            $table->index(['tenant_id', 'user_id'], 'project_markup_assignee_tenant_user_index');
        });

        DB::table('project_review_markups')
            ->whereNotNull('assigned_to_id')
            ->orderBy('id')
            ->chunkById(500, function ($markups): void {
                $now = now();
                $rows = $markups->map(fn ($markup): array => [
                    'tenant_id' => $markup->tenant_id,
                    'project_review_markup_id' => $markup->id,
                    'user_id' => $markup->assigned_to_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('project_review_markup_assignees')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_review_markup_assignees');
    }
};

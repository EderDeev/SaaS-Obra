<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table): void {
            if (! Schema::hasColumn('orcamentos', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('orcamentos', 'closed_by_id')) {
                $table->foreignId('closed_by_id')
                    ->nullable()
                    ->after('closed_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table): void {
            if (Schema::hasColumn('orcamentos', 'closed_by_id')) {
                $table->dropConstrainedForeignId('closed_by_id');
            }

            if (Schema::hasColumn('orcamentos', 'closed_at')) {
                $table->dropColumn('closed_at');
            }
        });
    }
};

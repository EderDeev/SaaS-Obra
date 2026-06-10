<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orcamento_composicao_items', 'sicro3_section')) {
            Schema::table('orcamento_composicao_items', function (Blueprint $table): void {
                $table->string('sicro3_section', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orcamento_composicao_items', 'sicro3_section')) {
            Schema::table('orcamento_composicao_items', function (Blueprint $table): void {
                $table->dropColumn('sicro3_section');
            });
        }
    }
};

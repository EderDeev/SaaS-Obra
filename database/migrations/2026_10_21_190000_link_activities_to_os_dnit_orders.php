<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('os_dnit_order_id')->nullable()->constrained('os_dnit_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve os vínculos das atividades com uma migração corretiva para frente.');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rdo_mobile_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('local_uuid', 120);
            $table->string('request_hash', 64);
            $table->json('response');
            $table->timestamp('created_at');
            $table->unique(['tenant_id', 'user_id', 'local_uuid'], 'rdo_mobile_sync_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migração somente de avanço; preserve os registros de sincronização.');
    }
};

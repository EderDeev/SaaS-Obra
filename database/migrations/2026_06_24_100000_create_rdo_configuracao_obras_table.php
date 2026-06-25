<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rdo_configuracao_obras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rdo_configuracao_id')->constrained('rdo_configuracoes')->cascadeOnDelete();
            $table->foreignId('obra_id')->constrained('obras')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['rdo_configuracao_id', 'obra_id'], 'rdo_config_obra_unique');
            $table->index('obra_id');
        });

        DB::table('rdo_configuracoes')
            ->select(['id', 'obra_id', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->each(function (object $configuration): void {
                DB::table('rdo_configuracao_obras')->insert([
                    'rdo_configuracao_id' => $configuration->id,
                    'obra_id' => $configuration->obra_id,
                    'created_at' => $configuration->created_at,
                    'updated_at' => $configuration->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('rdo_configuracao_obras');
    }
};

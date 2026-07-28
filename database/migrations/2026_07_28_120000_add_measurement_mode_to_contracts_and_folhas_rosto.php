<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->string('measurement_mode', 20)->nullable()->after('status');
        });

        Schema::table('folhas_rosto', function (Blueprint $table): void {
            $table->foreignId('ordem_servico_id')->nullable()->change();
        });

        Schema::table('folha_rosto_itens', function (Blueprint $table): void {
            $table->foreignId('medicao_item_id')
                ->nullable()
                ->after('ordem_servico_item_id')
                ->constrained('medicao_itens')
                ->restrictOnDelete();
            $table->foreignId('ordem_servico_item_id')->nullable()->change();
        });

        DB::statement(
            'UPDATE folha_rosto_itens
             SET medicao_item_id = (
                 SELECT ordem_servico_itens.medicao_item_id
                 FROM ordem_servico_itens
                 WHERE ordem_servico_itens.id = folha_rosto_itens.ordem_servico_item_id
             )
             WHERE ordem_servico_item_id IS NOT NULL'
        );

        Schema::table('folha_rosto_itens', function (Blueprint $table): void {
            $table->unique(['folha_rosto_id', 'medicao_item_id'], 'folha_rosto_medicao_item_unique');
            $table->index('medicao_item_id', 'folha_rosto_itens_medicao_item_idx');
        });
    }

    public function down(): void
    {
        Schema::table('folha_rosto_itens', function (Blueprint $table): void {
            $table->dropUnique('folha_rosto_medicao_item_unique');
            $table->dropIndex('folha_rosto_itens_medicao_item_idx');
            $table->dropConstrainedForeignId('medicao_item_id');
            $table->foreignId('ordem_servico_item_id')->nullable(false)->change();
        });

        Schema::table('folhas_rosto', function (Blueprint $table): void {
            $table->foreignId('ordem_servico_id')->nullable(false)->change();
        });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn('measurement_mode');
        });
    }
};

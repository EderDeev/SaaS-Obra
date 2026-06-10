<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_composicao_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('orcamento_composicao_items', 'sicro3_referenced_item_id')) {
                $table->foreignId('sicro3_referenced_item_id')
                    ->nullable()
                    ->after('sicro3_utilizacao_improdutiva')
                    ->constrained('orcamento_composicao_items')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('orcamento_composicao_items', 'sicro3_referenced_item_code')) {
                $table->string('sicro3_referenced_item_code', 50)->nullable()->after('sicro3_referenced_item_id');
            }

            if (! Schema::hasColumn('orcamento_composicao_items', 'sicro3_referenced_item_description')) {
                $table->text('sicro3_referenced_item_description')->nullable()->after('sicro3_referenced_item_code');
            }

            if (! Schema::hasColumn('orcamento_composicao_items', 'sicro3_transport_ln_code')) {
                $table->string('sicro3_transport_ln_code', 50)->nullable()->after('sicro3_referenced_item_description');
            }

            if (! Schema::hasColumn('orcamento_composicao_items', 'sicro3_transport_rp_code')) {
                $table->string('sicro3_transport_rp_code', 50)->nullable()->after('sicro3_transport_ln_code');
            }

            if (! Schema::hasColumn('orcamento_composicao_items', 'sicro3_transport_p_code')) {
                $table->string('sicro3_transport_p_code', 50)->nullable()->after('sicro3_transport_rp_code');
            }

            if (! Schema::hasColumn('orcamento_composicao_items', 'sicro3_transport_fe_code')) {
                $table->string('sicro3_transport_fe_code', 50)->nullable()->after('sicro3_transport_p_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orcamento_composicao_items', function (Blueprint $table): void {
            foreach ([
                'sicro3_transport_fe_code',
                'sicro3_transport_p_code',
                'sicro3_transport_rp_code',
                'sicro3_transport_ln_code',
                'sicro3_referenced_item_description',
                'sicro3_referenced_item_code',
            ] as $column) {
                if (Schema::hasColumn('orcamento_composicao_items', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('orcamento_composicao_items', 'sicro3_referenced_item_id')) {
                $table->dropConstrainedForeignId('sicro3_referenced_item_id');
            }
        });
    }
};

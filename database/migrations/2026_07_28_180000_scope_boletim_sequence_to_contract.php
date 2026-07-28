<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boletins_medicao', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'codigo']);
            $table->dropUnique(['tenant_id', 'sequencial']);
        });

        DB::table('boletins_medicao')
            ->orderBy('tenant_id')
            ->orderBy('contract_id')
            ->orderBy('periodo')
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'contract_id'])
            ->groupBy(fn ($boletim): string => $boletim->tenant_id.':'.$boletim->contract_id)
            ->each(function ($boletins): void {
                $boletins->values()->each(function ($boletim, int $index): void {
                    $sequence = $index + 1;

                    DB::table('boletins_medicao')
                        ->where('id', $boletim->id)
                        ->update([
                            'sequencial' => $sequence,
                            'codigo' => 'BM-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                        ]);
                });
            });

        Schema::table('boletins_medicao', function (Blueprint $table): void {
            $table->unique(
                ['tenant_id', 'contract_id', 'codigo'],
                'boletins_medicao_contract_code_unique',
            );
            $table->unique(
                ['tenant_id', 'contract_id', 'sequencial'],
                'boletins_medicao_contract_sequence_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('boletins_medicao', function (Blueprint $table): void {
            $table->dropUnique('boletins_medicao_contract_code_unique');
            $table->dropUnique('boletins_medicao_contract_sequence_unique');
        });

        DB::table('boletins_medicao')
            ->orderBy('tenant_id')
            ->orderBy('periodo')
            ->orderBy('id')
            ->get(['id', 'tenant_id'])
            ->groupBy('tenant_id')
            ->each(function ($boletins): void {
                $boletins->values()->each(function ($boletim, int $index): void {
                    $sequence = $index + 1;

                    DB::table('boletins_medicao')
                        ->where('id', $boletim->id)
                        ->update([
                            'sequencial' => $sequence,
                            'codigo' => 'BM-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                        ]);
                });
            });

        Schema::table('boletins_medicao', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'codigo']);
            $table->unique(['tenant_id', 'sequencial']);
        });
    }
};

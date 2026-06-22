<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordem_servicos', function (Blueprint $table): void {
            $table->unsignedInteger('sequencial')->nullable()->after('codigo');
        });

        $sequences = [];

        DB::table('ordem_servicos')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'contract_id', 'obra_id'])
            ->each(function (object $ordem) use (&$sequences): void {
                if (! $ordem->obra_id) {
                    return;
                }

                $key = "{$ordem->tenant_id}:{$ordem->contract_id}:{$ordem->obra_id}";
                $sequence = ($sequences[$key] ?? 0) + 1;
                $sequences[$key] = $sequence;

                $contractCode = DB::table('contracts')->where('id', $ordem->contract_id)->value('code');
                $obraCode = DB::table('obras')->where('id', $ordem->obra_id)->value('codigo');

                DB::table('ordem_servicos')
                    ->where('id', $ordem->id)
                    ->update([
                        'sequencial' => $sequence,
                        'codigo' => $this->buildCode((string) $contractCode, (string) $obraCode, $sequence),
                    ]);
            });

        Schema::table('ordem_servicos', function (Blueprint $table): void {
            $table->unique(
                ['tenant_id', 'contract_id', 'obra_id', 'sequencial'],
                'ordem_servicos_eap_sequence_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('ordem_servicos', function (Blueprint $table): void {
            $table->dropUnique('ordem_servicos_eap_sequence_unique');
            $table->dropColumn('sequencial');
        });
    }

    private function buildCode(string $contractCode, string $obraCode, int $sequence): string
    {
        return collect([$contractCode, $obraCode, 'OS', str_pad((string) $sequence, 3, '0', STR_PAD_LEFT)])
            ->map(fn (string $part): string => mb_strtoupper($part))
            ->map(fn (string $part): string => preg_replace('/\s+/', '', trim($part)) ?? '')
            ->map(fn (string $part): string => preg_replace('/[^A-Z0-9]/', '', $part) ?? '')
            ->filter()
            ->implode('-');
    }
};

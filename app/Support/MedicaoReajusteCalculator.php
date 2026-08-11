<?php

namespace App\Support;

use App\Models\MedicaoItem;
use Illuminate\Support\Carbon;

class MedicaoReajusteCalculator
{
    public static function percentage(?MedicaoItem $item, mixed $competencia = null): float
    {
        $indice = $item?->reajusteIndice?->indice;
        $base = (float) ($indice?->indice_base ?? 0);

        if (! $indice || $base <= 0) {
            return 0.0;
        }

        $reference = $competencia ? Carbon::parse($competencia)->startOfMonth() : null;
        $latestCompetencia = $indice->competencias
            ->when(
                $reference,
                fn ($competencias) => $competencias->filter(
                    fn ($entry): bool => $entry->competencia->copy()->startOfMonth()->lte($reference)
                )
            )
            ->sortByDesc('competencia')
            ->first();

        $current = $latestCompetencia
            ? (float) $latestCompetencia->valor_indice
            : ($reference ? $base : (float) $indice->indice_atual);

        return (($current - $base) / $base) * 100;
    }

    public static function adjustedValue(float $baseValue, ?MedicaoItem $item, mixed $competencia = null, int $precision = 6): float
    {
        return round($baseValue * (1 + (self::percentage($item, $competencia) / 100)), $precision);
    }
}

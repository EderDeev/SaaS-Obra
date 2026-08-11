<?php

namespace App\Support;

use App\Models\MedicaoItem;
use Illuminate\Support\Carbon;

class MedicaoItemValueResolver
{
    /** @return array{quantidade_total: float, preco_unitario_p0: float, valor_total_p0: float} */
    public static function resolve(?MedicaoItem $item, mixed $competencia = null, mixed $ordemItem = null): array
    {
        if (! $item) {
            $quantidade = (float) ($ordemItem?->quantidade_solicitada ?? 0);
            $valorTotal = (float) ($ordemItem?->valor_previsto ?? 0);

            return [
                'quantidade_total' => $quantidade,
                'preco_unitario_p0' => $quantidade > 0 ? $valorTotal / $quantidade : 0.0,
                'valor_total_p0' => $valorTotal,
            ];
        }

        $reference = $competencia ? Carbon::parse($competencia)->endOfMonth() : null;
        $versions = $item->relationLoaded('versions') ? $item->versions : $item->versions()->get();
        $eligibleVersions = $reference
            ? $versions->filter(fn ($version): bool => ! $version->starts_at || $version->starts_at->lte($reference))
            : $versions;
        $version = $eligibleVersions
            ->sortByDesc(fn ($entry): string => sprintf(
                '%020d-%010d',
                $entry->starts_at?->getTimestamp() ?? 0,
                (int) $entry->version_number
            ))
            ->first();

        if ($reference && $versions->isNotEmpty() && ! $version) {
            return [
                'quantidade_total' => 0.0,
                'preco_unitario_p0' => 0.0,
                'valor_total_p0' => 0.0,
            ];
        }

        $quantidade = (float) ($version?->quantidade_prevista ?? $item->quantidade_prevista ?? $ordemItem?->quantidade_solicitada ?? 0);
        $precoUnitario = (float) ($version?->valor_com_bdi ?? $item->valor_com_bdi ?? 0);
        $valorTotal = (float) ($version?->valor_total ?? ($quantidade * $precoUnitario));

        return [
            'quantidade_total' => $quantidade,
            'preco_unitario_p0' => $precoUnitario,
            'valor_total_p0' => $valorTotal,
        ];
    }
}

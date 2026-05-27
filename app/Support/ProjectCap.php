<?php

namespace App\Support;

use App\Models\ProjectDocumentVersion;
use App\Models\Tenant;

class ProjectCap
{
    public const IMPACT_LABELS = [
        'custo' => 'Custo',
        'prazo' => 'Prazo',
        'mudanca_material' => 'Mudanca de material',
        'seguranca' => 'Seguranca',
        'compatibilidade' => 'Compatibilidade com outros projetos',
    ];

    public static function impactKeys(): array
    {
        return array_keys(self::IMPACT_LABELS);
    }

    public static function normalizeImpacts(?array $impacts): array
    {
        return collect($impacts ?? [])
            ->map(fn ($impact): string => (string) $impact)
            ->filter(fn (string $impact): bool => array_key_exists($impact, self::IMPACT_LABELS))
            ->unique()
            ->values()
            ->all();
    }

    public static function nextSequence(Tenant $tenant, int $year): int
    {
        return ((int) ProjectDocumentVersion::query()
            ->where('tenant_id', $tenant->id)
            ->where('cap_year', $year)
            ->max('cap_sequence')) + 1;
    }

    public static function number(int $sequence, int $year): string
    {
        return 'CAP-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT).'-'.$year;
    }
}

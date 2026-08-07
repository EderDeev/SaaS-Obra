<?php

namespace App\Support;

use App\Models\ProjectDocumentVersion;
use App\Models\ProjectSubmissionBatch;
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
        $versionSequence = (int) ProjectDocumentVersion::query()
            ->where('tenant_id', $tenant->id)
            ->where('cap_year', $year)
            ->max('cap_sequence');
        $batchSequence = (int) ProjectSubmissionBatch::query()
            ->where('tenant_id', $tenant->id)
            ->where('cap_year', $year)
            ->max('cap_sequence');

        return max($versionSequence, $batchSequence) + 1;
    }

    public static function number(int $sequence, int $year): string
    {
        return 'CAP-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT).'-'.$year;
    }

    public static function fromProjectCode(string $documentCode, string $revision): string
    {
        $parts = array_values(array_filter(explode('-', $documentCode)));

        if (count($parts) >= 2) {
            $parts[count($parts) - 2] = 'CAP';
        }

        $parts[] = mb_strtoupper($revision);

        return implode('-', $parts);
    }

    public static function fromBatch(
        string $contractCode,
        string $obraCode,
        string $trechoCode,
        array $disciplineCodes,
        string $phaseCode,
        int $sequence,
        string $revision,
    ): string {
        $disciplines = collect($disciplineCodes)
            ->map(fn ($code): string => mb_strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values();

        return collect([
            $contractCode,
            $obraCode,
            $trechoCode,
            $disciplines->count() === 1 ? $disciplines->first() : 'MUL',
            $phaseCode,
            'CAP',
            str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            mb_strtoupper($revision),
        ])->map(fn ($part): string => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $part)) ?? '')
            ->filter()
            ->implode('-');
    }
}

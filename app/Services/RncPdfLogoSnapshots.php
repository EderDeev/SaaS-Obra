<?php

namespace App\Services;

use App\Models\{RelatorioNaoConformidade, RncPdfSetting, Tenant};
use App\Support\RncPdfTemplates;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{DB, Storage};

class RncPdfLogoSnapshots
{
    public function current(Tenant $tenant, int $contractId): array
    {
        return [
            'tenant_id' => (int) $tenant->id, 'contract_id' => $contractId,
            ...Arr::only(RncPdfTemplates::forContract($tenant, $contractId)['options'], RncPdfTemplates::LOGO_OPTIONS),
            'left_logo_label' => '', 'right_logo_label' => '',
        ];
    }

    public function forRnc(Tenant $tenant, RelatorioNaoConformidade $rnc): array
    {
        abort_unless((int) $rnc->tenant_id === (int) $tenant->id, 404);
        return $rnc->pdf_logo_snapshot ?? $this->legacy($tenant, $rnc);
    }

    private function legacy(Tenant $tenant, RelatorioNaoConformidade $rnc): array
    {
        $snapshot = $this->current($tenant, (int) $rnc->contract_id);
        $options = RncPdfSetting::where('tenant_id', $tenant->id)->where('contract_id', $rnc->contract_id)->first()?->options ?? [];
        if (array_key_exists('left_logo_path', $options) || array_key_exists('right_logo_path', $options)) return $snapshot;

        $rnc->loadMissing(['contratante', 'contratada', 'contract.fiscalizadoraEmpresa']);
        foreach (['left' => 'contratante', 'right' => 'contratada'] as $side => $default) {
            $company = match ($options[$side.'_logo'] ?? $default) {
                'contratante' => $rnc->contratante,
                'contratada' => $rnc->contratada,
                'fiscalizadora' => $rnc->contract?->fiscalizadoraEmpresa,
                default => null,
            };
            if (! $company || (int) $company->tenant_id !== (int) $tenant->id) continue;
            $snapshot[$side.'_logo_label'] = $company->nome ?? '';
            $path = $company->logo_path;
            if (! $path || ! Storage::disk('public')->exists($path)) continue;
            $bytes = Storage::disk('public')->get($path);
            $type = @getimagesizefromstring($bytes);
            $extension = match ($type[2] ?? null) {
                IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', default => null,
            };
            if (! $extension) continue;
            $copy = RncPdfTemplates::logoDirectory($tenant, (int) $rnc->contract_id).'/legacy-'.hash('sha256', $bytes).'.'.$extension;
            if (! Storage::disk('local')->exists($copy) && ! Storage::disk('local')->put($copy, $bytes)) {
                throw new \RuntimeException('Não foi possível preservar a logo original da RNC.');
            }
            $snapshot[$side.'_logo_path'] = $copy;
            $snapshot[$side.'_logo_name'] = basename($path);
        }
        return $snapshot;
    }

    public function freezeOne(Tenant $tenant, RelatorioNaoConformidade $rnc): bool
    {
        if ($rnc->pdf_logo_snapshot !== null) return false;
        $snapshot = $this->forRnc($tenant, $rnc);
        // No workflow events or updated_at changes during historical backfill.
        $updated = DB::table('relatorio_nao_conformidades')->where('tenant_id', $tenant->id)->where('id', $rnc->id)
            ->whereNull('pdf_logo_snapshot')->update(['pdf_logo_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        $rnc->pdf_logo_snapshot = $snapshot;
        return (bool) $updated;
    }

    public function freezeContract(Tenant $tenant, int $contractId): int
    {
        $count = 0;
        RelatorioNaoConformidade::withTrashed()->where('tenant_id', $tenant->id)->where('contract_id', $contractId)
            ->whereNull('pdf_logo_snapshot')->orderBy('id')->chunkById(100, function ($rncs) use ($tenant, &$count) {
                foreach ($rncs as $rnc) $count += (int) $this->freezeOne($tenant, $rnc);
            });
        return $count;
    }

    public function template(Tenant $tenant, RelatorioNaoConformidade $rnc, array $template): array
    {
        $snapshot = $this->forRnc($tenant, $rnc);
        return [...$template, 'options' => [...$template['options'], ...Arr::only($snapshot, RncPdfTemplates::LOGO_OPTIONS)], 'logo_snapshot' => $snapshot];
    }
}

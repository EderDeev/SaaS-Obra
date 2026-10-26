<?php

namespace App\Console\Commands;

use App\Models\{RelatorioNaoConformidade, Tenant};
use App\Services\RncPdfLogoSnapshots;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FreezeRncPdfLogos extends Command
{
    protected $signature = 'rnc:freeze-pdf-logos';
    protected $description = 'Preserva as logos das RNCs anteriores à parametrização de imagens por contrato';

    public function handle(RncPdfLogoSnapshots $snapshots): int
    {
        $count = 0;
        $tenantIds = RelatorioNaoConformidade::withTrashed()->whereNull('pdf_logo_snapshot')->distinct()->pluck('tenant_id');
        foreach ($tenantIds as $tenantId) {
            $count += DB::transaction(function () use ($tenantId, $snapshots) {
                $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
                $contractIds = RelatorioNaoConformidade::withTrashed()->where('tenant_id', $tenantId)
                    ->whereNull('pdf_logo_snapshot')->distinct()->pluck('contract_id');
                return $contractIds->sum(fn ($id) => $snapshots->freezeContract($tenant, (int) $id));
            });
        }
        $this->info("Logos preservadas em {$count} RNC(s).");
        return self::SUCCESS;
    }
}

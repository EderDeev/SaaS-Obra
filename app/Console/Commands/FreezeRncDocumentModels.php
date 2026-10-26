<?php

namespace App\Console\Commands;

use App\Models\{RelatorioNaoConformidade, Tenant};
use App\Services\RncDocumentSnapshots;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FreezeRncDocumentModels extends Command
{
    protected $signature = 'rnc:freeze-document-models';
    protected $description = 'Preserva os modelos e campos das RNCs existentes sem alterar o fluxo';

    public function handle(RncDocumentSnapshots $snapshots): int
    {
        $count = 0;
        foreach (RelatorioNaoConformidade::withTrashed()->whereNull('pdf_template_snapshot')->distinct()->pluck('tenant_id') as $id) {
            $count += DB::transaction(function () use ($id, $snapshots) {
                $tenant = Tenant::whereKey($id)->lockForUpdate()->firstOrFail();
                return RelatorioNaoConformidade::withTrashed()->where('tenant_id', $id)->whereNull('pdf_template_snapshot')->distinct()->pluck('contract_id')
                    ->sum(fn ($contractId) => $snapshots->freezeContract($tenant, (int) $contractId));
            });
        }
        $this->info("Modelos preservados em {$count} RNC(s).");
        return self::SUCCESS;
    }
}

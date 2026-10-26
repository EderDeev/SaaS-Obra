<?php

namespace App\Services;

use App\Models\{RelatorioNaoConformidade, RncPdfSetting, Tenant};
use App\Support\{RncModelFields, RncPdfTemplates};
use Illuminate\Support\Facades\DB;

class RncDocumentSnapshots
{
    public function current(Tenant $tenant, int $contractId): array
    {
        return $this->version(RncPdfTemplates::forContract($tenant, $contractId));
    }

    private function version(array $template): array
    {
        unset($template['preview'], $template['version']);
        return [...$template, 'version' => substr(hash('sha256', json_encode($template, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 12)];
    }

    public function forRnc(Tenant $tenant, RelatorioNaoConformidade $rnc): array
    {
        abort_unless((int) $rnc->tenant_id === (int) $tenant->id, 404);
        if ($rnc->pdf_template_snapshot !== null) return $rnc->pdf_template_snapshot;
        $template = RncPdfTemplates::forContract($tenant, (int) $rnc->contract_id);
        $original = $rnc->signatureRequests()->whereNotNull('unsigned_pdf_path')->orderByDesc('id')->first()?->request_payload['pdf_template'] ?? null;
        if (is_array($original)) $template = RncPdfTemplates::configuration($original['layout'] ?? 'standard', $original['options'] ?? [], $original['field_definitions'] ?? null);
        return $this->version(app(RncPdfLogoSnapshots::class)->template($tenant, $rnc, $template));
    }

    public function legacyValues(Tenant $tenant, RelatorioNaoConformidade $rnc): array
    {
        if ($rnc->model_field_values !== null) return $rnc->model_field_values;
        $template = $this->forRnc($tenant, $rnc);
        $raw = RncPdfSetting::where('tenant_id', $tenant->id)->where('contract_id', $rnc->contract_id)->first()?->options ?? [];
        $original = $rnc->signatureRequests()->whereNotNull('unsigned_pdf_path')->orderByDesc('id')->first()?->request_payload['pdf_template']['options'] ?? null;
        if (is_array($original)) $raw = $original;
        return collect($template['field_definitions'])->mapWithKeys(fn ($field) => [$field['key'] => $raw[$field['key']] ?? null])->all();
    }

    public function freezeOne(Tenant $tenant, RelatorioNaoConformidade $rnc): bool
    {
        if ($rnc->pdf_template_snapshot !== null) return false;
        $snapshot = $this->forRnc($tenant, $rnc);
        $values = $this->legacyValues($tenant, $rnc);
        $updated = DB::table('relatorio_nao_conformidades')->where('tenant_id', $tenant->id)->where('id', $rnc->id)->whereNull('pdf_template_snapshot')
            ->update(['pdf_template_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'model_field_values' => json_encode($values, JSON_THROW_ON_ERROR)]);
        $rnc->pdf_template_snapshot = $snapshot;
        $rnc->model_field_values = $values;
        return (bool) $updated;
    }

    public function freezeContract(Tenant $tenant, int $contractId): int
    {
        $count = 0;
        RelatorioNaoConformidade::withTrashed()->where('tenant_id', $tenant->id)->where('contract_id', $contractId)->whereNull('pdf_template_snapshot')
            ->chunkById(100, function ($rncs) use ($tenant, &$count) {
                foreach ($rncs as $rnc) $count += (int) $this->freezeOne($tenant, $rnc);
            });
        return $count;
    }

    public function form(Tenant $tenant, RelatorioNaoConformidade $rnc): array
    {
        $template = $this->forRnc($tenant, $rnc);
        return [
            'layout' => $template['layout'], 'version' => $template['version'], 'field_definitions' => $template['field_definitions'],
            'name' => collect(RncPdfTemplates::catalog())->firstWhere('value', $template['layout'])['name'],
        ];
    }

    public function rows(Tenant $tenant, RelatorioNaoConformidade $rnc): array
    {
        return RncModelFields::rows($this->forRnc($tenant, $rnc)['field_definitions'], $this->legacyValues($tenant, $rnc));
    }
}

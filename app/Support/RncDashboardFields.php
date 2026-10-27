<?php

namespace App\Support;

use App\Models\{RelatorioNaoConformidade, Tenant};
use App\Services\RncDocumentSnapshots;

class RncDashboardFields
{
    public static function catalog(Tenant $tenant, int $contractId): array
    {
        $fields = [];
        $include = function (array $definitions) use (&$fields) {
            foreach ($definitions as $field) {
                if ($field['type'] !== 'select') continue;
                $key = $field['key'];
                $fields[$key] ??= ['key' => $key, 'label' => $field['label'], 'options' => []];
                $fields[$key]['options'] = array_values(array_unique([...$fields[$key]['options'], ...$field['options']]));
            }
        };
        $include(RncPdfTemplates::forContract($tenant, $contractId)['field_definitions']);
        $tenant->relatorioNaoConformidades()->where('contract_id', $contractId)
            ->select(['id', 'tenant_id', 'contract_id', 'pdf_template_snapshot'])->chunkById(100, function ($rncs) use ($tenant, $include) {
                foreach ($rncs as $rnc) $include(app(RncDocumentSnapshots::class)->forRnc($tenant, $rnc)['field_definitions']);
            });
        return array_values($fields);
    }

    public static function matches(Tenant $tenant, RelatorioNaoConformidade $rnc, array $filters): bool
    {
        if (!$filters) return true;
        $definitions = app(RncDocumentSnapshots::class)->forRnc($tenant, $rnc)['field_definitions'];
        $values = $rnc->model_field_values ?? app(RncDocumentSnapshots::class)->legacyValues($tenant, $rnc);
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') continue;
            $field = collect($definitions)->firstWhere('key', $key);
            if (($field['type'] ?? null) !== 'select' || ($values[$key] ?? null) !== $value) return false;
        }
        return true;
    }
}

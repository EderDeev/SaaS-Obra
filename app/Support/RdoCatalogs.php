<?php

namespace App\Support;

use App\Models\{RdoDiario, RdoMaoObraCadastro, RdoEquipamentoCadastro, RdoSubcontratadaCadastro};
use Illuminate\Validation\ValidationException;

class RdoCatalogs
{
    public static function validate(RdoDiario $rdo, array $sections): void
    {
        foreach ([['mao_obra', 'efetivos', RdoMaoObraCadastro::class], ['mao_obra', 'subcontratadas', RdoSubcontratadaCadastro::class], ['equipamentos', 'registros', RdoEquipamentoCadastro::class]] as [$section, $key, $model]) {
            $entries = data_get($sections, $section.'.'.$key, []);
            if (!is_array($entries)) throw ValidationException::withMessages([$section => 'Formato de cadastro inválido.']);
            $ids = array_keys($entries);
            if (!$ids) continue;
            foreach ($ids as $id) {
                if (!ctype_digit((string) $id)) throw ValidationException::withMessages([$section => 'Identificador de cadastro inválido.']);
            }
            $allowed = $model::where('tenant_id', $rdo->tenant_id)->where('contract_id', $rdo->contract_id)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (string) $id)->all();
            // Keep existing historical references usable without making legacy catalogs public to other contracts.
            $existing = $rdo->secoes()->where('secao', $section)->get()->flatMap(fn ($row) => array_map('strval', array_keys($row->dados[$key] ?? [])))->all();
            $legacy = $model::withTrashed()->where('tenant_id', $rdo->tenant_id)->where(fn ($q) => $q->whereNull('contract_id')->orWhere('contract_id', $rdo->contract_id))->whereIn('id', $existing)->pluck('id')->map(fn ($id) => (string) $id)->all();
            if (array_diff(array_map('strval', $ids), [...$allowed, ...$legacy])) {
                throw ValidationException::withMessages([$section => 'Selecione somente cadastros vinculados ao contrato deste RDO.']);
            }
        }
    }
}

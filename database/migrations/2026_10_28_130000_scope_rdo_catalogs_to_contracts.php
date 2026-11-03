<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'rdo_mao_obra_cadastros' => ['rdo_mao_obra_tenant_descricao_tipo_unique', ['tenant_id', 'contract_id', 'descricao', 'tipo'], 'rdo_labor_contract_unique'],
            'rdo_equipamento_cadastros' => ['rdo_equipamento_tenant_codigo_unique', ['tenant_id', 'contract_id', 'codigo'], 'rdo_equipment_contract_unique'],
            'rdo_subcontratada_cadastros' => ['rdo_subcontratada_tenant_cnpj_unique', ['tenant_id', 'contract_id', 'cnpj'], 'rdo_subcontractor_contract_unique'],
        ];
        foreach ($tables as $name => [$oldIndex, $columns, $newIndex]) {
            Schema::table($name, function (Blueprint $table) use ($oldIndex, $columns, $newIndex) {
                $table->foreignId('contract_id')->nullable()->constrained('contracts')->restrictOnDelete();
                $table->dropUnique($oldIndex);
                $table->unique($columns, $newIndex);
            });
            // A tenant with multiple contracts needs an explicit choice, never an arbitrary assignment.
            $contracts = DB::table('contracts')->select('tenant_id')->selectRaw('MIN(id) as contract_id')->groupBy('tenant_id')->havingRaw('COUNT(*) = 1')->get();
            foreach ($contracts as $contract) {
                DB::table($name)->where('tenant_id', $contract->tenant_id)->whereNull('contract_id')->update(['contract_id' => $contract->contract_id]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('This migration is forward-only; contract catalog assignments must be preserved.');
    }
};

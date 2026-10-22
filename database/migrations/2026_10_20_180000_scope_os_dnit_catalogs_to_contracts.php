<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['os_dnit_products', 'os_dnit_highways'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreignId('contract_id')->nullable()->constrained('contracts')->restrictOnDelete());
        }

        foreach (DB::table('os_dnit_products')->get(['id', 'tenant_id']) as $product) {
            $usedContracts = DB::table('os_dnit_order_items')->join('os_dnit_orders', 'os_dnit_orders.id', '=', 'os_dnit_order_items.order_id')
                ->where('product_id', $product->id)->distinct()->pluck('os_dnit_orders.contract_id');
            DB::table('os_dnit_products')->where('id', $product->id)->update(['contract_id' => $this->resolveContract($product->tenant_id, $usedContracts->all(), 'produto', $product->id)]);
        }
        foreach (DB::table('os_dnit_highways')->get(['id', 'tenant_id']) as $highway) {
            $usedContracts = DB::table('os_dnit_orders')->where('highway_id', $highway->id)->distinct()->pluck('contract_id');
            DB::table('os_dnit_highways')->where('id', $highway->id)->update(['contract_id' => $this->resolveContract($highway->tenant_id, $usedContracts->all(), 'rodovia', $highway->id)]);
        }

        Schema::table('os_dnit_products', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_id')->nullable(false)->change();
            $table->dropUnique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'contract_id', 'code'], 'os_dnit_product_contract_code_unique');
        });
        Schema::table('os_dnit_highways', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_id')->nullable(false)->change();
            $table->dropUnique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'contract_id', 'name'], 'os_dnit_highway_contract_name_unique');
        });
    }

    private function resolveContract(int $tenantId, array $usedContracts, string $kind, int $id): int
    {
        $contracts = DB::table('contracts')->where('tenant_id', $tenantId);
        if (count($usedContracts) === 1 && (clone $contracts)->where('id', $usedContracts[0])->exists()) {
            return (int) $usedContracts[0];
        }
        if ($usedContracts === []) {
            $ids = $contracts->pluck('id');
            if ($ids->count() === 1) return (int) $ids->first();
        }
        throw new RuntimeException("Não é possível atribuir automaticamente o contrato do {$kind} OS DNIT {$id}. Defina o vínculo antes de aplicar esta migração; nenhum contrato será escolhido arbitrariamente.");
    }

    public function down(): void
    {
        throw new RuntimeException('O isolamento contratual OS DNIT exige migrações corretivas para frente; reversão automática não suportada.');
    }
};

<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OsDnitSupervisionLotMigrationTest extends TestCase
{
    public function test_forward_migration_keeps_document_snapshots_separates_contracts_and_leaves_conflicting_references_unset(): void
    {
        foreach (['tenants', 'contracts', 'users'] as $table) {
            Schema::create($table, fn (Blueprint $t) => $t->id());
        }
        Schema::create('os_dnit_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('contract_id');
            $t->string('supervision_lot');
            $t->string('sei_reference')->nullable();
            $t->string('status');
        });
        DB::table('tenants')->insert(['id' => 1]);
        DB::table('contracts')->insert([['id' => 1], ['id' => 2]]);
        DB::table('os_dnit_orders')->insert([
            ['id' => 1, 'tenant_id' => 1, 'contract_id' => 1, 'supervision_lot' => 'Lote A', 'sei_reference' => 'SEI-A', 'status' => 'approved'],
            ['id' => 2, 'tenant_id' => 1, 'contract_id' => 1, 'supervision_lot' => 'Lote A', 'sei_reference' => 'SEI-A', 'status' => 'draft'],
            ['id' => 3, 'tenant_id' => 1, 'contract_id' => 2, 'supervision_lot' => 'Lote A', 'sei_reference' => 'SEI-OUTRO', 'status' => 'draft'],
            ['id' => 4, 'tenant_id' => 1, 'contract_id' => 1, 'supervision_lot' => 'Lote B', 'sei_reference' => 'SEI-1', 'status' => 'draft'],
            ['id' => 5, 'tenant_id' => 1, 'contract_id' => 1, 'supervision_lot' => 'Lote B', 'sei_reference' => 'SEI-2', 'status' => 'draft'],
        ]);
        (require database_path('migrations/2026_10_22_190000_add_supervision_lots_and_order_completion.php'))->up();
        $this->assertSame(3, DB::table('os_dnit_supervision_lots')->count());
        $this->assertSame('SEI-A', DB::table('os_dnit_supervision_lots')->where('name', 'Lote A')->where('contract_id', 1)->value('sei_reference'));
        $this->assertNull(DB::table('os_dnit_supervision_lots')->where('name', 'Lote B')->value('sei_reference'));
        $this->assertSame('SEI-1', DB::table('os_dnit_orders')->where('id', 4)->value('sei_reference'));
        $this->assertSame('SEI-2', DB::table('os_dnit_orders')->where('id', 5)->value('sei_reference'));
        $this->assertSame('approved', DB::table('os_dnit_orders')->where('id', 1)->value('status'));
        $this->assertSame(0, DB::table('os_dnit_orders')->whereNull('supervision_lot_id')->count());
        $this->assertNotEquals(DB::table('os_dnit_orders')->where('id', 1)->value('supervision_lot_id'), DB::table('os_dnit_orders')->where('id', 3)->value('supervision_lot_id'));
        $this->assertSame(0, DB::table('os_dnit_orders')->whereNotNull('finalized_at')->count());
    }
}

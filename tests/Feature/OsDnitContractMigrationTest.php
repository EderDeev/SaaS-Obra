<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class OsDnitContractMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('tenants', fn (Blueprint $table) => $table->id());
        Schema::create('contracts', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('tenant_id'); });
        Schema::create('os_dnit_products', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('code'); $table->unique(['tenant_id', 'code']);
        });
        Schema::create('os_dnit_highways', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->string('name'); $table->unique(['tenant_id', 'name']);
        });
        Schema::create('os_dnit_orders', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('contract_id'); $table->unsignedBigInteger('highway_id'); });
        Schema::create('os_dnit_order_items', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('order_id'); $table->unsignedBigInteger('product_id'); });
        DB::table('tenants')->insert(['id' => 1]);
        DB::table('contracts')->insert(['id' => 10, 'tenant_id' => 1]);
        DB::table('os_dnit_products')->insert(['id' => 20, 'tenant_id' => 1, 'code' => '02']);
        DB::table('os_dnit_highways')->insert(['id' => 30, 'tenant_id' => 1, 'name' => 'BR-364/AC']);
    }

    public function test_existing_catalog_is_preserved_and_linked_to_the_only_contract(): void
    {
        $migration = require database_path('migrations/2026_10_20_180000_scope_os_dnit_catalogs_to_contracts.php');
        $migration->up();
        $this->assertSame(10, (int) DB::table('os_dnit_products')->where('id', 20)->value('contract_id'));
        $this->assertSame(10, (int) DB::table('os_dnit_highways')->where('id', 30)->value('contract_id'));
        $this->assertSame('02', DB::table('os_dnit_products')->where('id', 20)->value('code'));
        $this->assertSame('BR-364/AC', DB::table('os_dnit_highways')->where('id', 30)->value('name'));
        $this->assertFalse(collect(Schema::getColumns('os_dnit_products'))->firstWhere('name', 'contract_id')['nullable']);
        $this->assertFalse(collect(Schema::getColumns('os_dnit_highways'))->firstWhere('name', 'contract_id')['nullable']);
    }

    public function test_existing_order_usage_identifies_the_contract_when_tenant_has_several(): void
    {
        DB::table('contracts')->insert(['id' => 11, 'tenant_id' => 1]);
        DB::table('os_dnit_orders')->insert(['id' => 40, 'contract_id' => 11, 'highway_id' => 30]);
        DB::table('os_dnit_order_items')->insert(['id' => 50, 'order_id' => 40, 'product_id' => 20]);
        $migration = require database_path('migrations/2026_10_20_180000_scope_os_dnit_catalogs_to_contracts.php');
        $migration->up();
        $this->assertSame(11, (int) DB::table('os_dnit_products')->where('id', 20)->value('contract_id'));
        $this->assertSame(11, (int) DB::table('os_dnit_highways')->where('id', 30)->value('contract_id'));
        $this->assertSame(1, DB::table('os_dnit_order_items')->count());
    }

    public function test_ambiguous_existing_catalog_is_not_assigned_arbitrarily(): void
    {
        DB::table('contracts')->insert(['id' => 11, 'tenant_id' => 1]);
        $migration = require database_path('migrations/2026_10_20_180000_scope_os_dnit_catalogs_to_contracts.php');
        try {
            DB::transaction(fn () => $migration->up());
            $this->fail('Ambiguous contract assignment must stop the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Não é possível atribuir automaticamente', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('os_dnit_products')->count());
        $this->assertSame(1, DB::table('os_dnit_highways')->count());
        $this->assertFalse(Schema::hasColumn('os_dnit_products', 'contract_id'));
    }
}

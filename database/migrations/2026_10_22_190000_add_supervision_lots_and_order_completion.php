<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('os_dnit_supervision_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('contract_id')->constrained();
            $table->string('name', 120);
            $table->string('sei_reference')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'contract_id', 'name'], 'os_lots_contract_name_unique');
        });
        Schema::table('os_dnit_orders', function (Blueprint $table) {
            $table->foreignId('supervision_lot_id')->nullable()->constrained('os_dnit_supervision_lots');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by_id')->nullable()->constrained('users');
        });
        // Keep original names and SEI values on each document. Conflicting legacy
        // references must be resolved in settings, rather than choosing one silently.
        DB::table('os_dnit_orders')->select(['tenant_id', 'contract_id', 'supervision_lot'])->distinct()->get()->each(function ($row) {
            if (trim((string) $row->supervision_lot) === '') {
                return;
            }
            $orders = DB::table('os_dnit_orders')->where('tenant_id', $row->tenant_id)->where('contract_id', $row->contract_id)->where('supervision_lot', $row->supervision_lot);
            $refs = (clone $orders)->whereNotNull('sei_reference')->where('sei_reference', '!=', '')->distinct()->pluck('sei_reference');
            $id = DB::table('os_dnit_supervision_lots')->insertGetId(['tenant_id' => $row->tenant_id, 'contract_id' => $row->contract_id,
                'name' => $row->supervision_lot, 'sei_reference' => $refs->count() === 1 ? $refs->first() : null,
                'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $orders->update(['supervision_lot_id' => $id]);
        });
    }

    public function down(): void
    {
        Schema::table('os_dnit_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervision_lot_id');
            $table->dropConstrainedForeignId('finalized_by_id');
            $table->dropColumn('finalized_at');
        });
        Schema::dropIfExists('os_dnit_supervision_lots');
    }
};

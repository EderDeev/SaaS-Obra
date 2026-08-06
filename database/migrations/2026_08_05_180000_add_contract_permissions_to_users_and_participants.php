<?php

use App\Support\ContractPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->json('contract_permissions')->nullable()->after('medicao_permissions');
        });
        Schema::table('contract_participants', function (Blueprint $table): void {
            $table->json('contract_permissions')->nullable()->after('medicao_permissions');
        });

        DB::table('tenant_users')->select(['id', 'role'])->orderBy('id')->eachById(function (object $membership): void {
            DB::table('tenant_users')->where('id', $membership->id)->update([
                'contract_permissions' => json_encode(ContractPermissions::defaultForRole($membership->role)),
            ]);
        });
        DB::table('contract_participants')->select(['id', 'role'])->orderBy('id')->eachById(function (object $participant): void {
            DB::table('contract_participants')->where('id', $participant->id)->update([
                'contract_permissions' => json_encode(ContractPermissions::defaultForParticipantRole($participant->role)),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('contract_participants', fn (Blueprint $table) => $table->dropColumn('contract_permissions'));
        Schema::table('tenant_users', fn (Blueprint $table) => $table->dropColumn('contract_permissions'));
    }
};

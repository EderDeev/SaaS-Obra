<?php

use App\Support\MedicaoPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->json('medicao_permissions')->nullable()->after('ordem_servico_permissions');
        });
        Schema::table('contract_participants', function (Blueprint $table): void {
            $table->json('medicao_permissions')->nullable()->after('ordem_servico_permissions');
        });

        DB::table('tenant_users')->orderBy('id')->each(function ($membership): void {
            DB::table('tenant_users')->where('id', $membership->id)->update([
                'medicao_permissions' => json_encode(MedicaoPermissions::defaultForRole($membership->role)),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('contract_participants', fn (Blueprint $table) => $table->dropColumn('medicao_permissions'));
        Schema::table('tenant_users', fn (Blueprint $table) => $table->dropColumn('medicao_permissions'));
    }
};

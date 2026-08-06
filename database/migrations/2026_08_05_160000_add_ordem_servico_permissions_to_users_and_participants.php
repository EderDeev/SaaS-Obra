<?php

use App\Support\OrdemServicoPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->json('ordem_servico_permissions')->nullable()->after('diario_obra_permissions');
        });

        Schema::table('contract_participants', function (Blueprint $table): void {
            $table->json('ordem_servico_permissions')->nullable()->after('diario_obra_permissions');
        });

        DB::table('tenant_users')
            ->select(['id', 'role'])
            ->orderBy('id')
            ->eachById(function (object $membership): void {
                DB::table('tenant_users')
                    ->where('id', $membership->id)
                    ->update([
                        'ordem_servico_permissions' => json_encode(OrdemServicoPermissions::defaultForRole($membership->role)),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('contract_participants', function (Blueprint $table): void {
            $table->dropColumn('ordem_servico_permissions');
        });

        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropColumn('ordem_servico_permissions');
        });
    }
};

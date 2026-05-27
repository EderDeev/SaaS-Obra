<?php

use App\Support\RncPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorio_nao_conformidade_responsaveis', function (Blueprint $table): void {
            $table->json('permissions')->nullable()->after('status');
        });

        DB::table('relatorio_nao_conformidade_responsaveis')
            ->whereNull('permissions')
            ->update([
                'permissions' => json_encode([
                    RncPermissions::VIEW,
                    RncPermissions::CORRECTIVE_ACTION,
                    RncPermissions::EVIDENCE,
                ]),
            ]);
    }

    public function down(): void
    {
        Schema::table('relatorio_nao_conformidade_responsaveis', function (Blueprint $table): void {
            $table->dropColumn('permissions');
        });
    }
};

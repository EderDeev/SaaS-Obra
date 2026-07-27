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
            $table->string('responsibility_type', 30)
                ->nullable()
                ->after('status')
                ->index();
        });

        DB::table('relatorio_nao_conformidade_responsaveis')
            ->select(['id', 'permissions'])
            ->orderBy('id')
            ->eachById(function (object $responsavel): void {
                $permissions = json_decode($responsavel->permissions ?: '[]', true);
                $type = RncPermissions::responsibilityTypeForPermissions(is_array($permissions) ? $permissions : []);

                DB::table('relatorio_nao_conformidade_responsaveis')
                    ->where('id', $responsavel->id)
                    ->update([
                        'responsibility_type' => $type,
                        'permissions' => json_encode(RncPermissions::permissionsForResponsibility($type)),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('relatorio_nao_conformidade_responsaveis', function (Blueprint $table): void {
            $table->dropIndex(['responsibility_type']);
            $table->dropColumn('responsibility_type');
        });
    }
};

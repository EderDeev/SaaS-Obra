<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('orcamento_etapas', function (Blueprint $table): void {
                $table->string('ordem', 40)->change();
            });
        }

        Schema::table('orcamentos', function (Blueprint $table): void {
            $table->decimal('encargos_horista', 10, 6)->nullable()->after('encargos_sociais');
            $table->decimal('encargos_mensalista', 10, 6)->nullable()->after('encargos_horista');
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table): void {
            $table->dropColumn(['encargos_horista', 'encargos_mensalista']);
        });
    }
};

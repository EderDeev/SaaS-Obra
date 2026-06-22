<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folhas_rosto', function (Blueprint $table): void {
            $table->string('memoria_calculo_path')->nullable()->after('comentario');
            $table->string('memoria_calculo_nome_original')->nullable()->after('memoria_calculo_path');
            $table->string('memoria_calculo_mime_type', 120)->nullable()->after('memoria_calculo_nome_original');
            $table->unsignedBigInteger('memoria_calculo_size')->nullable()->after('memoria_calculo_mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('folhas_rosto', function (Blueprint $table): void {
            $table->dropColumn([
                'memoria_calculo_path',
                'memoria_calculo_nome_original',
                'memoria_calculo_mime_type',
                'memoria_calculo_size',
            ]);
        });
    }
};

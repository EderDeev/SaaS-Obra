<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tipos_empresa', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 80)->unique();
            $table->timestamps();
        });

        $now = now();

        DB::table('tipos_empresa')->insert([
            ['nome' => 'construtora', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 'subcontratada', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 'projetista', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 'fiscalizadora', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 'cliente', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 'gerenciadora', 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 'fornecedora', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tipos_empresa');
    }
};

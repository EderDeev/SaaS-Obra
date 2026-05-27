<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_phases', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        DB::table('project_phases')->insert([
            [
                'name' => 'Estudo Preliminar',
                'code' => 'EP',
                'position' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Projeto Base',
                'code' => 'PB',
                'position' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Projeto Executivo',
                'code' => 'PE',
                'position' => 3,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::table('project_documents', function (Blueprint $table): void {
            $table->foreignId('project_phase_id')
                ->nullable()
                ->after('disciplina_id')
                ->constrained('project_phases')
                ->nullOnDelete();

            $table->index(['tenant_id', 'project_phase_id']);
        });
    }

    public function down(): void
    {
        Schema::table('project_documents', function (Blueprint $table): void {
            $table->dropForeign(['project_phase_id']);
            $table->dropIndex(['tenant_id', 'project_phase_id']);
            $table->dropColumn('project_phase_id');
        });

        Schema::dropIfExists('project_phases');
    }
};

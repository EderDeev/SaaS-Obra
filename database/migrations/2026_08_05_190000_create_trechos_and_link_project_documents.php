<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trechos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('obra_id')->constrained('obras')->cascadeOnDelete();
            $table->string('codigo', 3);
            $table->string('nome');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'obra_id', 'codigo']);
            $table->index(['tenant_id', 'obra_id', 'is_default']);
        });

        Schema::table('project_documents', function (Blueprint $table): void {
            $table->foreignId('trecho_id')
                ->nullable()
                ->after('obra_id')
                ->constrained('trechos')
                ->nullOnDelete();
            $table->index(['tenant_id', 'trecho_id'], 'project_documents_tenant_trecho_index');
        });

        $now = now();
        DB::table('obras')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'tenant_id'])
            ->each(function (object $obra) use ($now): void {
                DB::table('trechos')->insert([
                    'tenant_id' => $obra->tenant_id,
                    'obra_id' => $obra->id,
                    'codigo' => 'GER',
                    'nome' => 'Geral',
                    'is_default' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('project_documents', function (Blueprint $table): void {
            $table->dropIndex('project_documents_tenant_trecho_index');
            $table->dropConstrainedForeignId('trecho_id');
        });

        Schema::dropIfExists('trechos');
    }
};

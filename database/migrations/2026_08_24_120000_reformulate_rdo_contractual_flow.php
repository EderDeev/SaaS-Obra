<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rdo_diarios', function (Blueprint $table) {
            $table->unsignedInteger('current_version')->default(0)->after('status');
        });

        Schema::table('rdo_analises', function (Blueprint $table) {
            $table->json('detalhes')->nullable()->after('comentario');
        });

        Schema::create('rdo_versoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rdo_diario_id')->constrained('rdo_diarios')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('trigger', 40)->default('submission');
            $table->string('status', 40);
            $table->json('sections_snapshot');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['rdo_diario_id', 'version_number'], 'rdo_versions_rdo_number_unique');
            $table->index(['tenant_id', 'rdo_diario_id', 'created_at']);
        });

        Schema::create('rdo_fiscalizacao_registros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rdo_diario_id')->constrained('rdo_diarios')->cascadeOnDelete();
            $table->foreignId('obra_id')->constrained('obras')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->unsignedInteger('version_number');
            $table->text('resumo_fiscalizacao');
            $table->text('servicos_atestados')->nullable();
            $table->text('ocorrencias')->nullable();
            $table->text('orientacoes')->nullable();
            $table->json('evidencias')->nullable();
            $table->string('status', 20)->default('rascunho');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['rdo_diario_id', 'obra_id', 'version_number'], 'rdo_inspection_rdo_obra_version_unique');
            $table->index(['tenant_id', 'rdo_diario_id', 'status']);
        });

        Schema::create('rdo_divergencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rdo_diario_id')->constrained('rdo_diarios')->cascadeOnDelete();
            $table->foreignId('obra_id')->constrained('obras')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('topico', 80);
            $table->string('titulo', 180);
            $table->text('versao_construtora');
            $table->text('versao_gerenciadora');
            $table->string('status', 30)->default('aberta');
            $table->foreignId('responded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resposta_construtora')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('concluded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('conclusao_gerenciadora')->nullable();
            $table->timestamp('concluded_at')->nullable();
            $table->foreignId('client_decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decisao_cliente', 40)->nullable();
            $table->text('comentario_cliente')->nullable();
            $table->timestamp('client_decided_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'rdo_diario_id', 'status']);
            $table->index(['rdo_diario_id', 'obra_id', 'version_number']);
        });

        DB::table('rdo_diarios')
            ->where('status', 'em_aprovacao')
            ->update(['status' => 'em_analise_gerenciadora']);

        DB::table('rdo_analises')
            ->where('status_novo', 'em_aprovacao')
            ->update(['status_novo' => 'em_analise_gerenciadora']);
    }

    public function down(): void
    {
        DB::table('rdo_diarios')
            ->whereIn('status', ['em_analise_gerenciadora', 'em_aprovacao_cliente'])
            ->update(['status' => 'em_aprovacao']);

        DB::table('rdo_analises')
            ->whereIn('status_novo', ['em_analise_gerenciadora', 'em_aprovacao_cliente'])
            ->update(['status_novo' => 'em_aprovacao']);

        Schema::dropIfExists('rdo_divergencias');
        Schema::dropIfExists('rdo_fiscalizacao_registros');
        Schema::dropIfExists('rdo_versoes');

        Schema::table('rdo_analises', function (Blueprint $table) {
            $table->dropColumn('detalhes');
        });

        Schema::table('rdo_diarios', function (Blueprint $table) {
            $table->dropColumn('current_version');
        });
    }
};

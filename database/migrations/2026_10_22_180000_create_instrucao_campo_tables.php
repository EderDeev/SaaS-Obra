<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instrucoes_campo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('contract_id')->constrained();
            $table->foreignId('obra_id')->constrained('obras');
            $table->foreignId('disciplina_id')->constrained('disciplinas');
            $table->foreignId('contratante_empresa_id')->constrained('empresas');
            $table->foreignId('contratada_empresa_id')->constrained('empresas');
            $table->foreignId('created_by_id')->constrained('users');
            $table->unsignedInteger('sequence_number');
            $table->unsignedSmallInteger('sequence_year');
            $table->date('opened_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('gravidade', 30);
            $table->text('instrucao');
            $table->string('status', 40)->default('draft');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_id')->nullable()->constrained('users');
            $table->text('closing_comment')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'sequence_year', 'sequence_number'], 'ic_tenant_year_number_unique');
            $table->index(['tenant_id', 'contract_id', 'status']);
        });
        Schema::create('instrucao_campo_responsaveis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('contract_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('created_by_id')->nullable()->constrained('users');
            $table->string('status')->default('active');
            $table->string('responsibility_type');
            $table->json('permissions')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['contract_id', 'user_id'], 'ic_responsible_contract_user_unique');
        });
        Schema::create('instrucao_campo_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('instrucao_campo_id')->constrained('instrucoes_campo');
            $table->foreignId('user_id')->constrained();
            $table->string('type', 40);
            $table->text('comment')->nullable();
            $table->timestamps();
        });
        Schema::create('instrucao_campo_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('instrucao_campo_id')->constrained('instrucoes_campo');
            $table->foreignId('event_id')->nullable()->constrained('instrucao_campo_events');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->text('comment')->nullable();
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instrucao_campo_photos');
        Schema::dropIfExists('instrucao_campo_events');
        Schema::dropIfExists('instrucao_campo_responsaveis');
        Schema::dropIfExists('instrucoes_campo');
    }
};

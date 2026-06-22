<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicao_item_additives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('source_orcamento_id')->nullable()->constrained('orcamentos')->nullOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->text('reason')->nullable();
            $table->string('source_type', 30)->default('manual');
            $table->string('status', 30)->default('applied');
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'contract_id', 'number'], 'medicao_additives_contract_number_unique');
            $table->index(['tenant_id', 'contract_id', 'created_at']);
        });

        Schema::create('medicao_item_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('medicao_item_id')->constrained('medicao_itens')->cascadeOnDelete();
            $table->foreignId('additive_id')->nullable()->constrained('medicao_item_additives')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('version_label', 80);
            $table->string('change_type', 30)->default('base');
            $table->decimal('quantidade_prevista', 18, 6)->default(0);
            $table->decimal('valor_unitario', 18, 6)->default(0);
            $table->decimal('valor_com_bdi', 18, 6)->default(0);
            $table->decimal('valor_total', 18, 6)->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->unique(['medicao_item_id', 'version_number'], 'medicao_item_versions_item_number_unique');
            $table->index(['tenant_id', 'contract_id', 'medicao_item_id']);
        });

        Schema::create('medicao_item_additive_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('additive_id')->constrained('medicao_item_additives')->cascadeOnDelete();
            $table->foreignId('medicao_item_id')->nullable()->constrained('medicao_itens')->nullOnDelete();
            $table->foreignId('medicao_item_version_id')->nullable()->constrained('medicao_item_versions')->nullOnDelete();
            $table->string('status', 30);
            $table->string('item', 40)->nullable();
            $table->string('codigo', 80)->nullable();
            $table->string('banco', 40)->nullable();
            $table->text('descricao')->nullable();
            $table->string('unidade', 30)->nullable();
            $table->decimal('quantidade_anterior', 18, 6)->nullable();
            $table->decimal('quantidade_nova', 18, 6)->nullable();
            $table->decimal('valor_unitario_anterior', 18, 6)->nullable();
            $table->decimal('valor_unitario_novo', 18, 6)->nullable();
            $table->decimal('valor_com_bdi_anterior', 18, 6)->nullable();
            $table->decimal('valor_com_bdi_novo', 18, 6)->nullable();
            $table->decimal('valor_total_anterior', 18, 6)->nullable();
            $table->decimal('valor_total_novo', 18, 6)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'contract_id', 'status']);
            $table->index(['additive_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicao_item_additive_items');
        Schema::dropIfExists('medicao_item_versions');
        Schema::dropIfExists('medicao_item_additives');
    }
};

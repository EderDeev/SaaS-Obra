<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rdo_configuracoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('obra_id')->constrained('obras')->cascadeOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->time('generation_time')->default('00:00');
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->json('generation_weekdays');
            $table->boolean('generate_on_holidays')->default(true);
            $table->boolean('copy_previous_day')->default(false);
            $table->boolean('copy_workforce')->default(true);
            $table->boolean('copy_equipment')->default(true);
            $table->boolean('copy_pending_activities')->default(true);
            $table->boolean('require_photos')->default(false);
            $table->time('submission_deadline_time')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'contract_id', 'obra_id'], 'rdo_config_tenant_contract_obra_unique');
            $table->index(['active', 'start_date', 'end_date']);
        });

        Schema::create('rdo_diarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rdo_configuracao_id')->constrained('rdo_configuracoes')->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('obra_id')->constrained('obras')->cascadeOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('copied_from_rdo_id')->nullable()->constrained('rdo_diarios')->nullOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('code', 60);
            $table->date('reference_date');
            $table->string('status', 30)->default('rascunho');
            $table->boolean('generated_automatically')->default(false);
            $table->uuid('mobile_local_uuid')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'contract_id', 'obra_id', 'reference_date'], 'rdo_tenant_contract_obra_date_unique');
            $table->unique(['tenant_id', 'obra_id', 'sequence_number'], 'rdo_tenant_obra_sequence_unique');
            $table->unique(['tenant_id', 'mobile_local_uuid'], 'rdo_tenant_mobile_uuid_unique');
            $table->index(['tenant_id', 'reference_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rdo_diarios');
        Schema::dropIfExists('rdo_configuracoes');
    }
};

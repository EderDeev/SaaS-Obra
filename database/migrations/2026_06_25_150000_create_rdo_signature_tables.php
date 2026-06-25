<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rdo_signature_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rdo_diario_id')->constrained('rdo_diarios')->cascadeOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider')->default('opensign');
            $table->string('provider_request_id')->nullable();
            $table->string('provider_document_id')->nullable();
            $table->string('status')->default('draft');
            $table->string('title');
            $table->string('unsigned_pdf_path')->nullable();
            $table->string('signed_pdf_path')->nullable();
            $table->string('audit_trail_path')->nullable();
            $table->text('signing_url')->nullable();
            $table->text('error_message')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('provider_payload')->nullable();
            $table->json('webhook_payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'rdo_diario_id']);
            $table->index(['provider', 'provider_request_id']);
            $table->index('status');
        });

        Schema::create('rdo_signature_signers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rdo_signature_request_id')->constrained('rdo_signature_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->string('role');
            $table->string('name');
            $table->string('email');
            $table->string('provider_signer_id')->nullable();
            $table->string('status')->default('pending');
            $table->text('signing_url')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'role']);
            $table->index(['rdo_signature_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rdo_signature_signers');
        Schema::dropIfExists('rdo_signature_requests');
    }
};

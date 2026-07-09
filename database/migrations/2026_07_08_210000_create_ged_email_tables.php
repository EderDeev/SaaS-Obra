<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ged_email_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('host');
            $table->unsignedInteger('port')->default(993);
            $table->string('encryption', 20)->default('ssl');
            $table->string('username');
            $table->text('password')->nullable();
            $table->string('mailbox')->default('INBOX');
            $table->string('post_action', 30)->default('mark_read');
            $table->string('move_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contract_id', 'is_active'], 'ged_email_accounts_tenant_contract_active_index');
            $table->unique(['tenant_id', 'email'], 'ged_email_accounts_tenant_email_unique');
        });

        Schema::create('ged_email_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('ged_email_accounts')->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('ged_document_types')->nullOnDelete();
            $table->foreignId('correspondent_id')->nullable()->constrained('ged_correspondents')->nullOnDelete();
            $table->string('name');
            $table->string('from_contains')->nullable();
            $table->string('subject_contains')->nullable();
            $table->string('body_contains')->nullable();
            $table->string('attachment_name_contains')->nullable();
            $table->json('tag_ids')->nullable();
            $table->boolean('consume_attachments')->default(true);
            $table->unsignedInteger('priority')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contract_id', 'is_active'], 'ged_email_rules_tenant_contract_active_index');
            $table->index(['tenant_id', 'account_id', 'priority'], 'ged_email_rules_tenant_account_priority_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ged_email_rules');
        Schema::dropIfExists('ged_email_accounts');
    }
};

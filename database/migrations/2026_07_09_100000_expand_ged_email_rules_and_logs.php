<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ged_email_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('ged_email_rules', 'mailbox')) {
                $table->string('mailbox')->default('INBOX')->after('name');
            }

            if (! Schema::hasColumn('ged_email_rules', 'max_age_days')) {
                $table->unsignedInteger('max_age_days')->nullable()->after('mailbox');
            }

            if (! Schema::hasColumn('ged_email_rules', 'to_contains')) {
                $table->string('to_contains')->nullable()->after('from_contains');
            }

            if (! Schema::hasColumn('ged_email_rules', 'include_attachment_patterns')) {
                $table->string('include_attachment_patterns')->nullable()->after('attachment_name_contains');
            }

            if (! Schema::hasColumn('ged_email_rules', 'exclude_attachment_patterns')) {
                $table->string('exclude_attachment_patterns')->nullable()->after('include_attachment_patterns');
            }

            if (! Schema::hasColumn('ged_email_rules', 'consume_scope')) {
                $table->string('consume_scope', 40)->default('attachments')->after('exclude_attachment_patterns');
            }

            if (! Schema::hasColumn('ged_email_rules', 'attachment_type')) {
                $table->string('attachment_type', 40)->default('attachments')->after('consume_scope');
            }

            if (! Schema::hasColumn('ged_email_rules', 'pdf_layout')) {
                $table->string('pdf_layout', 40)->default('system')->after('attachment_type');
            }

            if (! Schema::hasColumn('ged_email_rules', 'post_action')) {
                $table->string('post_action', 40)->default('mark_read')->after('pdf_layout');
            }

            if (! Schema::hasColumn('ged_email_rules', 'title_source')) {
                $table->string('title_source', 40)->default('subject')->after('post_action');
            }

            if (! Schema::hasColumn('ged_email_rules', 'assign_owner_from_rule')) {
                $table->boolean('assign_owner_from_rule')->default(false)->after('title_source');
            }
        });

        Schema::create('ged_email_processed_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('ged_email_accounts')->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('ged_email_rules')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('ged_documents')->nullOnDelete();
            $table->string('message_uid')->nullable();
            $table->string('message_id')->nullable();
            $table->string('subject')->nullable();
            $table->string('from')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('status', 40)->default('success');
            $table->text('error')->nullable();
            $table->unsignedInteger('attachments_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'rule_id', 'processed_at'], 'ged_email_processed_rule_processed_index');
            $table->index(['tenant_id', 'account_id', 'processed_at'], 'ged_email_processed_account_processed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ged_email_processed_messages');

        Schema::table('ged_email_rules', function (Blueprint $table) {
            foreach ([
                'assign_owner_from_rule',
                'title_source',
                'post_action',
                'pdf_layout',
                'attachment_type',
                'consume_scope',
                'exclude_attachment_patterns',
                'include_attachment_patterns',
                'to_contains',
                'max_age_days',
                'mailbox',
            ] as $column) {
                if (Schema::hasColumn('ged_email_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

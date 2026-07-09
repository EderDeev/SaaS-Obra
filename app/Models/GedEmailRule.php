<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'account_id',
    'contract_id',
    'document_type_id',
    'correspondent_id',
    'name',
    'mailbox',
    'max_age_days',
    'from_contains',
    'to_contains',
    'subject_contains',
    'body_contains',
    'attachment_name_contains',
    'include_attachment_patterns',
    'exclude_attachment_patterns',
    'consume_scope',
    'attachment_type',
    'pdf_layout',
    'post_action',
    'title_source',
    'assign_owner_from_rule',
    'tag_ids',
    'consume_attachments',
    'priority',
    'is_active',
])]
class GedEmailRule extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'tag_ids' => 'array',
            'consume_attachments' => 'boolean',
            'assign_owner_from_rule' => 'boolean',
            'max_age_days' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GedEmailAccount::class, 'account_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(GedDocumentType::class, 'document_type_id');
    }

    public function correspondent(): BelongsTo
    {
        return $this->belongsTo(GedCorrespondent::class);
    }

    public function processedMessages(): HasMany
    {
        return $this->hasMany(GedEmailProcessedMessage::class, 'rule_id')->latest('processed_at');
    }
}

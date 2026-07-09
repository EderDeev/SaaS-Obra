<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'account_id',
    'rule_id',
    'document_id',
    'message_uid',
    'message_id',
    'subject',
    'from',
    'received_at',
    'processed_at',
    'status',
    'error',
    'attachments_count',
    'imported_count',
    'duplicate_count',
    'metadata',
])]
class GedEmailProcessedMessage extends Model
{
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'attachments_count' => 'integer',
            'imported_count' => 'integer',
            'duplicate_count' => 'integer',
            'metadata' => 'array',
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

    public function rule(): BelongsTo
    {
        return $this->belongsTo(GedEmailRule::class, 'rule_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(GedDocument::class);
    }
}

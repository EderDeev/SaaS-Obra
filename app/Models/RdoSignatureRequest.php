<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'rdo_diario_id',
    'requested_by_id',
    'provider',
    'provider_request_id',
    'provider_document_id',
    'status',
    'title',
    'unsigned_pdf_path',
    'signed_pdf_path',
    'audit_trail_path',
    'signing_url',
    'error_message',
    'request_payload',
    'provider_payload',
    'webhook_payload',
    'sent_at',
    'completed_at',
    'cancelled_at',
])]
class RdoSignatureRequest extends Model
{
    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'provider_payload' => 'array',
            'webhook_payload' => 'array',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function rdo(): BelongsTo
    {
        return $this->belongsTo(RdoDiario::class, 'rdo_diario_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function signers(): HasMany
    {
        return $this->hasMany(RdoSignatureSigner::class);
    }
}

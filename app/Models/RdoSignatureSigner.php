<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'rdo_signature_request_id',
    'user_id',
    'empresa_id',
    'role',
    'name',
    'email',
    'provider_signer_id',
    'status',
    'signing_url',
    'provider_payload',
    'signed_at',
])]
class RdoSignatureSigner extends Model
{
    protected function casts(): array
    {
        return [
            'provider_payload' => 'array',
            'signed_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(RdoSignatureRequest::class, 'rdo_signature_request_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class)->withTrashed();
    }
}

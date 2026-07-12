<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractAdditive extends Model
{
    protected $fillable = [
        'tenant_id',
        'contract_id',
        'user_id',
        'sequence_number',
        'type',
        'title',
        'motivation',
        'amount',
        'previous_total_value',
        'new_total_value',
        'deadline_days',
        'previous_ends_at',
        'new_ends_at',
        'attachment_path',
        'attachment_original_name',
        'attachment_mime_type',
        'attachment_size',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'previous_total_value' => 'decimal:2',
            'new_total_value' => 'decimal:2',
            'deadline_days' => 'integer',
            'previous_ends_at' => 'date',
            'new_ends_at' => 'date',
            'attachment_size' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

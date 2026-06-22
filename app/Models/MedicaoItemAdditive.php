<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'contract_id',
    'created_by_id',
    'source_orcamento_id',
    'number',
    'title',
    'reason',
    'source_type',
    'status',
    'effective_at',
    'applied_at',
    'meta',
])]
class MedicaoItemAdditive extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'effective_at' => 'datetime',
            'applied_at' => 'datetime',
            'meta' => 'array',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function sourceOrcamento(): BelongsTo
    {
        return $this->belongsTo(Orcamento::class, 'source_orcamento_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(MedicaoItemAdditiveItem::class, 'additive_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MedicaoItemVersion::class, 'additive_id');
    }
}

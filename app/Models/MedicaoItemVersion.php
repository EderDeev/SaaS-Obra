<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'contract_id',
    'medicao_item_id',
    'additive_id',
    'created_by_id',
    'version_number',
    'version_label',
    'change_type',
    'quantidade_prevista',
    'valor_unitario',
    'valor_com_bdi',
    'valor_total',
    'starts_at',
    'snapshot',
])]
class MedicaoItemVersion extends Model
{
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'quantidade_prevista' => 'decimal:6',
            'valor_unitario' => 'decimal:6',
            'valor_com_bdi' => 'decimal:6',
            'valor_total' => 'decimal:6',
            'starts_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MedicaoItem::class, 'medicao_item_id');
    }

    public function additive(): BelongsTo
    {
        return $this->belongsTo(MedicaoItemAdditive::class, 'additive_id');
    }
}

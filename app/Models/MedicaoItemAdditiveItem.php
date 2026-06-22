<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'contract_id',
    'additive_id',
    'medicao_item_id',
    'medicao_item_version_id',
    'status',
    'item',
    'codigo',
    'banco',
    'descricao',
    'unidade',
    'quantidade_anterior',
    'quantidade_nova',
    'valor_unitario_anterior',
    'valor_unitario_novo',
    'valor_com_bdi_anterior',
    'valor_com_bdi_novo',
    'valor_total_anterior',
    'valor_total_novo',
    'meta',
])]
class MedicaoItemAdditiveItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantidade_anterior' => 'decimal:6',
            'quantidade_nova' => 'decimal:6',
            'valor_unitario_anterior' => 'decimal:6',
            'valor_unitario_novo' => 'decimal:6',
            'valor_com_bdi_anterior' => 'decimal:6',
            'valor_com_bdi_novo' => 'decimal:6',
            'valor_total_anterior' => 'decimal:6',
            'valor_total_novo' => 'decimal:6',
            'meta' => 'array',
        ];
    }

    public function additive(): BelongsTo
    {
        return $this->belongsTo(MedicaoItemAdditive::class, 'additive_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MedicaoItem::class, 'medicao_item_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(MedicaoItemVersion::class, 'medicao_item_version_id');
    }
}

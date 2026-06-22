<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'contract_id',
    'medicao_item_id',
    'medicao_indice_reajuste_id',
    'created_by_id',
    'item_codigo',
    'indice_codigo',
    'source_type',
])]
class MedicaoItemReajusteIndice extends Model
{
    use SoftDeletes;

    protected $table = 'medicao_item_reajuste_indices';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MedicaoItem::class, 'medicao_item_id');
    }

    public function indice(): BelongsTo
    {
        return $this->belongsTo(MedicaoIndiceReajuste::class, 'medicao_indice_reajuste_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}

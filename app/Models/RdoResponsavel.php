<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'contract_id',
    'obra_id',
    'user_id',
    'created_by_id',
    'etapa',
    'status',
])]
class RdoResponsavel extends Model
{
    use SoftDeletes;

    protected $table = 'rdo_responsaveis';

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

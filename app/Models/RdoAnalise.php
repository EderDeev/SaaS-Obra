<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'rdo_diario_id',
    'obra_id',
    'user_id',
    'empresa_id',
    'etapa',
    'decisao',
    'comentario',
    'status_anterior',
    'status_novo',
])]
class RdoAnalise extends Model
{
    public function rdo(): BelongsTo
    {
        return $this->belongsTo(RdoDiario::class, 'rdo_diario_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class)->withTrashed();
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class)->withTrashed();
    }
}

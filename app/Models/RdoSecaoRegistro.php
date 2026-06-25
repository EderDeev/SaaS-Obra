<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'rdo_diario_id', 'obra_id', 'updated_by_id', 'secao', 'dados'])]
class RdoSecaoRegistro extends Model
{
    protected $table = 'rdo_secao_registros';

    protected function casts(): array
    {
        return ['dados' => 'array'];
    }

    public function rdo(): BelongsTo
    {
        return $this->belongsTo(RdoDiario::class, 'rdo_diario_id');
    }

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class)->withTrashed();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'folha_rosto_id',
    'user_id',
    'status_origem',
    'status_destino',
    'acao',
    'motivo',
])]
class FolhaRostoFluxoHistorico extends Model
{
    protected $table = 'folha_rosto_fluxo_historicos';

    public function folhaRosto(): BelongsTo
    {
        return $this->belongsTo(FolhaRosto::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

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
    'responsaveis_snapshot',
])]
class FolhaRostoFluxoHistorico extends Model
{
    protected $table = 'folha_rosto_fluxo_historicos';

    protected function casts(): array
    {
        return [
            'responsaveis_snapshot' => 'array',
        ];
    }

    public function folhaRosto(): BelongsTo
    {
        return $this->belongsTo(FolhaRosto::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

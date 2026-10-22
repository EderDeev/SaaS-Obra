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
    'version_number',
    'resumo_fiscalizacao',
    'servicos_atestados',
    'ocorrencias',
    'orientacoes',
    'evidencias',
    'status',
    'submitted_at',
])]
class RdoFiscalizacaoRegistro extends Model
{
    protected $table = 'rdo_fiscalizacao_registros';

    protected function casts(): array
    {
        return [
            'evidencias' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function rdo(): BelongsTo
    {
        return $this->belongsTo(RdoDiario::class, 'rdo_diario_id');
    }

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class)->withTrashed();
    }
}

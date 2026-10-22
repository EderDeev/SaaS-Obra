<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'rdo_diario_id',
    'obra_id',
    'created_by_id',
    'version_number',
    'topico',
    'titulo',
    'versao_construtora',
    'versao_gerenciadora',
    'status',
    'responded_by_id',
    'resposta_construtora',
    'responded_at',
    'concluded_by_id',
    'conclusao_gerenciadora',
    'concluded_at',
    'client_decided_by_id',
    'decisao_cliente',
    'comentario_cliente',
    'client_decided_at',
])]
class RdoDivergencia extends Model
{
    protected $table = 'rdo_divergencias';

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
            'concluded_at' => 'datetime',
            'client_decided_at' => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_id');
    }

    public function concludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluded_by_id');
    }

    public function clientDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_decided_by_id');
    }
}

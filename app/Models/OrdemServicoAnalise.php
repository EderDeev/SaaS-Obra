<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ordem_servico_id',
    'user_id',
    'tipo',
    'decisao',
    'observacao',
])]
class OrdemServicoAnalise extends Model
{
    protected $table = 'ordem_servico_analises';

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

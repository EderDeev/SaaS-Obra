<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'ordem_servico_id',
    'medicao_item_id',
    'quantidade_solicitada',
    'valor_previsto',
])]
class OrdemServicoItem extends Model
{
    protected $table = 'ordem_servico_itens';

    protected function casts(): array
    {
        return [
            'quantidade_solicitada' => 'decimal:6',
            'valor_previsto' => 'decimal:2',
        ];
    }

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class);
    }

    public function medicaoItem(): BelongsTo
    {
        return $this->belongsTo(MedicaoItem::class);
    }

    public function folhaRostoItens(): HasMany
    {
        return $this->hasMany(FolhaRostoItem::class);
    }
}

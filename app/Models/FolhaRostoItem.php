<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'folha_rosto_id',
    'ordem_servico_item_id',
    'quantidade_pleiteada',
    'valor_pleiteado',
    'precisa_analise_topografica',
    'precisa_analise_qualidade',
])]
class FolhaRostoItem extends Model
{
    protected $table = 'folha_rosto_itens';

    protected function casts(): array
    {
        return [
            'quantidade_pleiteada' => 'decimal:6',
            'valor_pleiteado' => 'decimal:2',
            'precisa_analise_topografica' => 'boolean',
            'precisa_analise_qualidade' => 'boolean',
        ];
    }

    public function folhaRosto(): BelongsTo
    {
        return $this->belongsTo(FolhaRosto::class);
    }

    public function ordemServicoItem(): BelongsTo
    {
        return $this->belongsTo(OrdemServicoItem::class);
    }

    public function analises(): HasMany
    {
        return $this->hasMany(FolhaRostoItemAnalise::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'folha_rosto_item_id',
    'folha_rosto_analise_id',
    'setor',
    'quantidade_aprovada',
    'comentario',
])]
class FolhaRostoItemAnalise extends Model
{
    protected $table = 'folha_rosto_item_analises';

    protected function casts(): array
    {
        return [
            'quantidade_aprovada' => 'decimal:6',
        ];
    }

    public function folhaRostoItem(): BelongsTo
    {
        return $this->belongsTo(FolhaRostoItem::class);
    }

    public function analise(): BelongsTo
    {
        return $this->belongsTo(FolhaRostoAnalise::class, 'folha_rosto_analise_id');
    }
}

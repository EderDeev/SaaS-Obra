<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'folha_rosto_id',
    'user_id',
    'setor',
    'comentario_geral',
])]
class FolhaRostoAnalise extends Model
{
    protected $table = 'folha_rosto_analises';

    public function folhaRosto(): BelongsTo
    {
        return $this->belongsTo(FolhaRosto::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(FolhaRostoItemAnalise::class);
    }
}

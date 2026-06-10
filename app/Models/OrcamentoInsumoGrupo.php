<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'created_by_id',
    'nome',
    'descricao',
])]
class OrcamentoInsumoGrupo extends Model
{
    use SoftDeletes;

    protected $table = 'orcamento_insumo_grupos';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function insumos(): HasMany
    {
        return $this->hasMany(OrcamentoInsumo::class, 'grupo_id');
    }
}

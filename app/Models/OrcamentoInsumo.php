<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'created_by_id',
    'banco',
    'tipo',
    'classificacao',
    'codigo_insumo',
    'descricao',
    'unidade',
    'uf',
    'origem_preco',
    'preco_nao_desonerado',
    'preco_desonerado',
    'data_referencia',
])]
class OrcamentoInsumo extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'data_referencia' => 'date',
            'preco_nao_desonerado' => 'decimal:2',
            'preco_desonerado' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}

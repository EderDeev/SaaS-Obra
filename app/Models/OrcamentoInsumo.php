<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'created_by_id',
    'grupo_id',
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
    'custo_improdutivo_nao_desonerado',
    'custo_improdutivo_desonerado',
    'data_referencia',
    'observacao',
])]
class OrcamentoInsumo extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'data_referencia' => 'date',
            'preco_nao_desonerado' => 'decimal:6',
            'preco_desonerado' => 'decimal:6',
            'custo_improdutivo_nao_desonerado' => 'decimal:6',
            'custo_improdutivo_desonerado' => 'decimal:6',
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

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(OrcamentoInsumoGrupo::class, 'grupo_id');
    }
}

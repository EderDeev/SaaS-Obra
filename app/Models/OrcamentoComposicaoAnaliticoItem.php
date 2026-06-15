<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'created_by_id',
    'is_global',
    'modelo',
    'grupo',
    'codigo_composicao',
    'descricao_composicao',
    'unidade_composicao',
    'producao_equipe',
    'fator_influencia_chuvas',
    'tipo_item',
    'codigo_item',
    'descricao_item',
    'unidade',
    'uf',
    'data_referencia',
    'coeficiente',
    'secao',
    'tipo_transporte',
    'codigo_item_referenciado',
    'utilizacao_operativa',
    'utilizacao_improdutiva',
    'raw_payload',
])]
class OrcamentoComposicaoAnaliticoItem extends Model
{
    use SoftDeletes;

    protected $table = 'orcamento_composicao_analitico_items';

    protected function casts(): array
    {
        return [
            'is_global' => 'boolean',
            'data_referencia' => 'date',
            'coeficiente' => 'decimal:6',
            'producao_equipe' => 'decimal:6',
            'fator_influencia_chuvas' => 'decimal:6',
            'utilizacao_operativa' => 'decimal:6',
            'utilizacao_improdutiva' => 'decimal:6',
            'raw_payload' => 'array',
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

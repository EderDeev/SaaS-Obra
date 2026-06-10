<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'orcamento_composicao_id',
    'created_by_id',
    'item_type',
    'sicro3_section',
    'orcamento_insumo_id',
    'child_composicao_id',
    'base',
    'codigo',
    'descricao',
    'tipo',
    'unidade',
    'preco_unitario_onerado',
    'preco_unitario_desonerado',
    'custo_improdutivo_onerado',
    'custo_improdutivo_desonerado',
    'coeficiente',
    'sicro3_utilizacao_operativa',
    'sicro3_utilizacao_improdutiva',
    'sicro3_referenced_item_id',
    'sicro3_referenced_item_code',
    'sicro3_referenced_item_description',
    'sicro3_transport_ln_code',
    'sicro3_transport_rp_code',
    'sicro3_transport_p_code',
    'sicro3_transport_fe_code',
    'preco_onerado',
    'preco_desonerado',
    'observacao',
])]
class OrcamentoComposicaoItem extends Model
{
    use SoftDeletes;

    protected $table = 'orcamento_composicao_items';

    protected function casts(): array
    {
        return [
            'preco_unitario_onerado' => 'decimal:6',
            'preco_unitario_desonerado' => 'decimal:6',
            'custo_improdutivo_onerado' => 'decimal:6',
            'custo_improdutivo_desonerado' => 'decimal:6',
            'coeficiente' => 'decimal:6',
            'sicro3_utilizacao_operativa' => 'decimal:6',
            'sicro3_utilizacao_improdutiva' => 'decimal:6',
            'sicro3_referenced_item_id' => 'integer',
            'preco_onerado' => 'decimal:6',
            'preco_desonerado' => 'decimal:6',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function composicao(): BelongsTo
    {
        return $this->belongsTo(OrcamentoComposicao::class, 'orcamento_composicao_id');
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(OrcamentoInsumo::class, 'orcamento_insumo_id');
    }

    public function childComposicao(): BelongsTo
    {
        return $this->belongsTo(OrcamentoComposicao::class, 'child_composicao_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}

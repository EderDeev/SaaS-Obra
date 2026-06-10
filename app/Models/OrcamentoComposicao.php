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
    'is_global',
    'codigo',
    'descricao',
    'tipo_composicao',
    'unidade',
    'uf',
    'modelo',
    'metodo_calculo',
    'producao_equipe',
    'adicional_mao_obra',
    'fator_influencia_chuvas',
    'observacao',
    'base_references',
    'preco_onerado',
    'preco_desonerado',
])]
class OrcamentoComposicao extends Model
{
    use SoftDeletes;

    protected $table = 'orcamento_composicoes';

    protected function casts(): array
    {
        return [
            'base_references' => 'array',
            'is_global' => 'boolean',
            'preco_onerado' => 'decimal:6',
            'preco_desonerado' => 'decimal:6',
            'producao_equipe' => 'decimal:6',
            'adicional_mao_obra' => 'decimal:6',
            'fator_influencia_chuvas' => 'decimal:6',
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

    public function items(): HasMany
    {
        return $this->hasMany(OrcamentoComposicaoItem::class, 'orcamento_composicao_id');
    }
}

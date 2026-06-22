<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'contract_id',
    'created_by_id',
    'source_type',
    'source_orcamento_id',
    'source_orcamento_etapa_id',
    'source_orcamento_item_id',
    'item',
    'nivel',
    'item_type',
    'codigo',
    'banco',
    'descricao',
    'unidade',
    'quantidade_prevista',
    'valor_unitario',
    'valor_com_bdi',
    'valor_total',
    'meta',
])]
class MedicaoItem extends Model
{
    use SoftDeletes;

    protected $table = 'medicao_itens';

    protected function casts(): array
    {
        return [
            'nivel' => 'integer',
            'quantidade_prevista' => 'decimal:6',
            'valor_unitario' => 'decimal:6',
            'valor_com_bdi' => 'decimal:6',
            'valor_total' => 'decimal:6',
            'meta' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function sourceOrcamento(): BelongsTo
    {
        return $this->belongsTo(Orcamento::class, 'source_orcamento_id')->withTrashed();
    }

    public function sourceEtapa(): BelongsTo
    {
        return $this->belongsTo(OrcamentoEtapa::class, 'source_orcamento_etapa_id')->withTrashed();
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(OrcamentoItem::class, 'source_orcamento_item_id')->withTrashed();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MedicaoItemVersion::class);
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(MedicaoItemVersion::class)->latestOfMany('version_number');
    }

    public function additiveItems(): HasMany
    {
        return $this->hasMany(MedicaoItemAdditiveItem::class);
    }

    public function reajusteIndice(): HasOne
    {
        return $this->hasOne(MedicaoItemReajusteIndice::class);
    }

    public function ordemServicoItens(): HasMany
    {
        return $this->hasMany(OrdemServicoItem::class);
    }
}

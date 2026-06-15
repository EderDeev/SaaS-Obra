<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'orcamento_id',
    'orcamento_etapa_id',
    'created_by_id',
    'item_type',
    'orcamento_composicao_id',
    'orcamento_insumo_id',
    'ordem',
    'codigo',
    'banco',
    'descricao',
    'unidade',
    'quantidade',
    'valor_unitario_nao_desonerado',
    'valor_unitario_desonerado',
    'valor_com_bdi_nao_desonerado',
    'valor_com_bdi_desonerado',
    'valor_total_nao_desonerado',
    'valor_total_desonerado',
    'aplicar_bdi',
    'meta',
])]
class OrcamentoItem extends Model
{
    use SoftDeletes;

    protected $table = 'orcamento_itens';

    protected function casts(): array
    {
        return [
            'ordem' => 'integer',
            'quantidade' => 'decimal:6',
            'valor_unitario_nao_desonerado' => 'decimal:6',
            'valor_unitario_desonerado' => 'decimal:6',
            'valor_com_bdi_nao_desonerado' => 'decimal:6',
            'valor_com_bdi_desonerado' => 'decimal:6',
            'valor_total_nao_desonerado' => 'decimal:6',
            'valor_total_desonerado' => 'decimal:6',
            'aplicar_bdi' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function orcamento(): BelongsTo
    {
        return $this->belongsTo(Orcamento::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(OrcamentoEtapa::class, 'orcamento_etapa_id');
    }

    public function composicao(): BelongsTo
    {
        return $this->belongsTo(OrcamentoComposicao::class, 'orcamento_composicao_id')->withTrashed();
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(OrcamentoInsumo::class, 'orcamento_insumo_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}

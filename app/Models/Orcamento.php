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
    'cliente_empresa_id',
    'codigo',
    'descricao',
    'categoria',
    'prazo_entrega_at',
    'permitir_insumos_preco_zerado',
    'is_licitacao',
    'licitacao_tipo',
    'licitacao_abertura_at',
    'licitacao_processo',
    'arredondamento',
    'encargos_sociais',
    'bdi_tipo',
    'bdi_percentual',
    'base_references',
    'status',
    'valor_nao_desonerado',
    'valor_desonerado',
])]
class Orcamento extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'prazo_entrega_at' => 'datetime',
            'licitacao_abertura_at' => 'datetime',
            'permitir_insumos_preco_zerado' => 'boolean',
            'is_licitacao' => 'boolean',
            'base_references' => 'array',
            'bdi_percentual' => 'decimal:6',
            'valor_nao_desonerado' => 'decimal:6',
            'valor_desonerado' => 'decimal:6',
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

    public function clienteEmpresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'cliente_empresa_id')->withTrashed();
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(OrcamentoEtapa::class)->orderBy('ordem');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(OrcamentoItem::class)->orderBy('ordem');
    }
}

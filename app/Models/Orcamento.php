<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'created_by_id',
    'closed_by_id',
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
    'encargos_horista',
    'encargos_mensalista',
    'bdi_tipo',
    'bdi_percentual',
    'base_references',
    'status',
    'closed_at',
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
            'encargos_horista' => 'decimal:6',
            'encargos_mensalista' => 'decimal:6',
            'bdi_percentual' => 'decimal:6',
            'closed_at' => 'datetime',
            'valor_nao_desonerado' => 'decimal:6',
            'valor_desonerado' => 'decimal:6',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeUsedInContract(Builder $query, Contract $contract): Builder
    {
        $items = MedicaoItem::query()->where('tenant_id', $contract->tenant_id)
            ->where('contract_id', $contract->id)->select('source_orcamento_id');
        $additives = MedicaoItemAdditive::query()->where('tenant_id', $contract->tenant_id)
            ->where('contract_id', $contract->id)->select('source_orcamento_id');

        return $query->where('orcamentos.tenant_id', $contract->tenant_id)
            ->where(fn (Builder $budgets) => $budgets->whereIn('orcamentos.id', $items)
                ->orWhereIn('orcamentos.id', $additives));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
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

    public function accesses(): HasMany
    {
        return $this->hasMany(OrcamentoAcesso::class);
    }
}

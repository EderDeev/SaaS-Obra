<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'orcamento_id',
    'created_by_id',
    'ordem',
    'descricao',
    'quantidade',
    'valor_nao_desonerado',
    'valor_desonerado',
    'meta',
])]
class OrcamentoEtapa extends Model
{
    use SoftDeletes;

    protected $table = 'orcamento_etapas';

    protected function casts(): array
    {
        return [
            'ordem' => 'integer',
            'quantidade' => 'decimal:6',
            'valor_nao_desonerado' => 'decimal:6',
            'valor_desonerado' => 'decimal:6',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(OrcamentoItem::class, 'orcamento_etapa_id')->orderBy('ordem');
    }
}

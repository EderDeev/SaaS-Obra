<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'contract_id',
    'obra_id',
    'project_document_id',
    'gerenciadora_empresa_id',
    'construtora_empresa_id',
    'created_by_id',
    'codigo',
    'sequencial',
    'titulo',
    'descricao',
    'prazo_execucao',
    'custo_previsto',
    'custo_observacao',
    'status',
    'submitted_for_review_at',
    'submitted_for_review_by_id',
    'analyzed_at',
    'analyzed_by_id',
    'analysis_observation',
    'approval_decided_at',
    'approval_decided_by_id',
    'approval_observation',
])]
class OrdemServico extends Model
{
    use SoftDeletes;

    protected $table = 'ordem_servicos';

    protected function casts(): array
    {
        return [
            'prazo_execucao' => 'date',
            'sequencial' => 'integer',
            'custo_previsto' => 'decimal:2',
            'submitted_for_review_at' => 'datetime',
            'analyzed_at' => 'datetime',
            'approval_decided_at' => 'datetime',
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

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class)->withTrashed();
    }

    public function projectDocument(): BelongsTo
    {
        return $this->belongsTo(ProjectDocument::class)->withTrashed();
    }

    public function projectDocuments(): BelongsToMany
    {
        return $this->belongsToMany(ProjectDocument::class, 'ordem_servico_project_documents')
            ->withTrashed()
            ->withTimestamps();
    }

    public function gerenciadoraEmpresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'gerenciadora_empresa_id')->withTrashed();
    }

    public function construtoraEmpresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'construtora_empresa_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_for_review_by_id');
    }

    public function analyzedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyzed_by_id');
    }

    public function approvalDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approval_decided_by_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(OrdemServicoItem::class);
    }

    public function responsaveis(): HasMany
    {
        return $this->hasMany(OrdemServicoResponsavel::class);
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(OrdemServicoDocumento::class);
    }

    public function analises(): HasMany
    {
        return $this->hasMany(OrdemServicoAnalise::class);
    }

    public function folhasRosto(): HasMany
    {
        return $this->hasMany(FolhaRosto::class);
    }
}

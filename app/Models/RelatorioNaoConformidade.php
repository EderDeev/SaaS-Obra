<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'mobile_local_uuid',
    'sequence_number',
    'sequence_year',
    'contract_id',
    'obra_id',
    'project_document_id',
    'disciplina_id',
    'contratante_empresa_id',
    'contratada_empresa_id',
    'created_by_id',
    'deleted_by_id',
    'opened_at',
    'notified_at',
    'finalized_at',
    'finalized_by_id',
    'latitude',
    'longitude',
    'natureza',
    'gravidade',
    'descricao_problema',
    'observacao',
    'acoes_corretivas_recomendadas',
    'prazo_resposta_acao_corretiva',
    'status',
])]
class RelatorioNaoConformidade extends Model
{
    use SoftDeletes;

    protected $table = 'relatorio_nao_conformidades';

    protected $appends = ['formatted_number'];

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'sequence_year' => 'integer',
            'opened_at' => 'date',
            'notified_at' => 'datetime',
            'finalized_at' => 'datetime',
            'prazo_resposta_acao_corretiva' => 'date',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function getFormattedNumberAttribute(): string
    {
        $number = $this->sequence_number ?: $this->id;
        $year = $this->sequence_year ?: ($this->opened_at?->format('Y') ?: now()->format('Y'));

        return str_pad((string) $number, 3, '0', STR_PAD_LEFT).'-'.$year;
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

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class)->withTrashed();
    }

    public function contratante(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'contratante_empresa_id')->withTrashed();
    }

    public function contratada(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'contratada_empresa_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_id');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RelatorioNaoConformidadePhoto::class)->orderBy('position');
    }

    public function acoesCorretivas(): HasMany
    {
        return $this->hasMany(RelatorioNaoConformidadeAcaoCorretiva::class)->latest('submitted_at');
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(RelatorioNaoConformidadeEvidencia::class)->latest('submitted_at');
    }
}

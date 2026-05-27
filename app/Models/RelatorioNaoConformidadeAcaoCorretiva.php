<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'relatorio_nao_conformidade_id',
    'user_id',
    'descricao_proposta',
    'prazo_execucao_proposto',
    'attachment_path',
    'attachment_original_name',
    'attachment_mime_type',
    'attachment_size',
    'submitted_at',
    'status',
    'review_observation',
    'reviewed_at',
    'reviewed_by_id',
])]
class RelatorioNaoConformidadeAcaoCorretiva extends Model
{
    protected $table = 'relatorio_nao_conformidade_acoes_corretivas';

    protected function casts(): array
    {
        return [
            'prazo_execucao_proposto' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'attachment_size' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function rnc(): BelongsTo
    {
        return $this->belongsTo(RelatorioNaoConformidade::class, 'relatorio_nao_conformidade_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}

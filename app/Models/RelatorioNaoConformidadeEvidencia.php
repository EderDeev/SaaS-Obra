<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'relatorio_nao_conformidade_id',
    'relatorio_nao_conformidade_acao_corretiva_id',
    'user_id',
    'attachment_path',
    'attachment_original_name',
    'attachment_mime_type',
    'attachment_size',
    'submitted_at',
])]
class RelatorioNaoConformidadeEvidencia extends Model
{
    protected $table = 'relatorio_nao_conformidade_evidencias';

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
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

    public function acaoCorretiva(): BelongsTo
    {
        return $this->belongsTo(RelatorioNaoConformidadeAcaoCorretiva::class, 'relatorio_nao_conformidade_acao_corretiva_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RelatorioNaoConformidadeEvidenciaPhoto::class, 'relatorio_nao_conformidade_evidencia_id')->orderBy('position');
    }
}

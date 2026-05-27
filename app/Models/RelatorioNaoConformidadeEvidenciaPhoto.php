<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'relatorio_nao_conformidade_evidencia_id',
    'user_id',
    'path',
    'original_name',
    'mime_type',
    'size',
    'position',
    'comment',
])]
class RelatorioNaoConformidadeEvidenciaPhoto extends Model
{
    protected $table = 'relatorio_nao_conformidade_evidencia_photos';

    protected $appends = ['url'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function evidencia(): BelongsTo
    {
        return $this->belongsTo(RelatorioNaoConformidadeEvidencia::class, 'relatorio_nao_conformidade_evidencia_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getUrlAttribute(): string
    {
        return '/storage/'.ltrim(str_replace('\\', '/', $this->path), '/');
    }
}

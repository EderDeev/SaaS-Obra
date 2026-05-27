<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'relatorio_nao_conformidade_id',
    'user_id',
    'path',
    'original_name',
    'mime_type',
    'size',
    'position',
    'comment',
])]
class RelatorioNaoConformidadePhoto extends Model
{
    protected $table = 'relatorio_nao_conformidade_photos';

    protected $appends = ['url'];

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

    public function getUrlAttribute(): string
    {
        return '/storage/'.ltrim(str_replace('\\', '/', $this->path), '/');
    }
}

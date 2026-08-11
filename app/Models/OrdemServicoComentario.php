<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'ordem_servico_id',
    'user_id',
    'parent_id',
    'tipo',
    'body',
    'status',
    'resolved_at',
    'resolved_by_id',
])]
class OrdemServicoComentario extends Model
{
    protected $table = 'ordem_servico_comentarios';

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest('id');
    }

    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ordem_servico_comentario_mencoes', 'comentario_id', 'user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OrdemServicoDocumento::class, 'comentario_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }
}

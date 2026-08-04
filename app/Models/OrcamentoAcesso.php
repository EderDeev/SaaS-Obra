<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'orcamento_id',
    'user_id',
    'access_level',
    'granted_by_id',
])]
class OrcamentoAcesso extends Model
{
    public const LEVEL_VIEW = 'view';
    public const LEVEL_EDIT = 'edit';

    protected $table = 'orcamento_acessos';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function orcamento(): BelongsTo
    {
        return $this->belongsTo(Orcamento::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_id');
    }
}

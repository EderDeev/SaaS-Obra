<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'rdo_diario_id',
    'created_by_id',
    'version_number',
    'trigger',
    'status',
    'sections_snapshot',
    'submitted_at',
])]
class RdoVersao extends Model
{
    protected $table = 'rdo_versoes';

    protected function casts(): array
    {
        return [
            'sections_snapshot' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function rdo(): BelongsTo
    {
        return $this->belongsTo(RdoDiario::class, 'rdo_diario_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}

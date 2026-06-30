<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'rdo_configuracao_id',
    'rdo_diario_id',
    'contract_id',
    'obra_id',
    'created_by_id',
    'updated_by_id',
    'reference_date',
    'status',
    'dados',
    'published_at',
])]
class RdaApontamento extends Model
{
    use SoftDeletes;

    protected $table = 'rda_apontamentos';

    protected function casts(): array
    {
        return [
            'reference_date' => 'date',
            'dados' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function configuracao(): BelongsTo
    {
        return $this->belongsTo(RdoConfiguracao::class, 'rdo_configuracao_id');
    }

    public function rdo(): BelongsTo
    {
        return $this->belongsTo(RdoDiario::class, 'rdo_diario_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class)->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}

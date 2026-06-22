<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'contract_id',
    'created_by_id',
    'nome',
    'codigo',
    'indice_base',
    'data_base',
    'indice_atual',
    'data_atual',
    'observacao',
])]
class MedicaoIndiceReajuste extends Model
{
    use SoftDeletes;

    protected $table = 'medicao_indices_reajuste';

    protected function casts(): array
    {
        return [
            'indice_base' => 'decimal:6',
            'indice_atual' => 'decimal:6',
            'data_base' => 'date',
            'data_atual' => 'date',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function competencias(): HasMany
    {
        return $this->hasMany(MedicaoIndiceReajusteCompetencia::class);
    }

    public function itemVinculos(): HasMany
    {
        return $this->hasMany(MedicaoItemReajusteIndice::class);
    }
}

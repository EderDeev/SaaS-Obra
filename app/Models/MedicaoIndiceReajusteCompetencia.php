<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'contract_id',
    'medicao_indice_reajuste_id',
    'created_by_id',
    'competencia',
    'valor_indice',
    'data_publicacao',
    'observacao',
])]
class MedicaoIndiceReajusteCompetencia extends Model
{
    use SoftDeletes;

    protected $table = 'medicao_indice_reajuste_competencias';

    protected function casts(): array
    {
        return [
            'competencia' => 'date',
            'valor_indice' => 'decimal:6',
            'data_publicacao' => 'date',
        ];
    }

    public function indice(): BelongsTo
    {
        return $this->belongsTo(MedicaoIndiceReajuste::class, 'medicao_indice_reajuste_id');
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
}

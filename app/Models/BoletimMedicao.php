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
    'codigo',
    'sequencial',
    'periodo',
    'tipo',
    'status',
])]
class BoletimMedicao extends Model
{
    use SoftDeletes;

    protected $table = 'boletins_medicao';

    protected function casts(): array
    {
        return [
            'sequencial' => 'integer',
            'periodo' => 'date',
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

    public function folhasRosto(): HasMany
    {
        return $this->hasMany(FolhaRosto::class);
    }
}

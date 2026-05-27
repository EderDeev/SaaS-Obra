<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'contract_id', 'obra_pai_id', 'nome', 'codigo', 'tipo'])]
class Obra extends Model
{
    use SoftDeletes;

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function obraPai(): BelongsTo
    {
        return $this->belongsTo(self::class, 'obra_pai_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function obrasFilhas(): HasMany
    {
        return $this->hasMany(self::class, 'obra_pai_id');
    }

    public function isPai(): bool
    {
        return $this->tipo === 'pai';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'contract_id', 'nome', 'sigla', 'cor'])]
class Disciplina extends Model
{
    use SoftDeletes;

    protected $table = 'disciplinas';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function projectResponsaveis(): HasMany
    {
        return $this->hasMany(ProjectDisciplineResponsavel::class);
    }
}

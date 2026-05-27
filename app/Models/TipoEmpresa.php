<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nome'])]
class TipoEmpresa extends Model
{
    protected $table = 'tipos_empresa';

    public function empresas(): HasMany
    {
        return $this->hasMany(Empresa::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'codigo', 'descricao', 'unidade', 'propriedade', 'active'])]
class RdoEquipamentoCadastro extends Model
{
    use SoftDeletes;

    protected $table = 'rdo_equipamento_cadastros';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}

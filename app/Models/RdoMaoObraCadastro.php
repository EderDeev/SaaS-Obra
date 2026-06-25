<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'descricao', 'tipo', 'unidade', 'active'])]
class RdoMaoObraCadastro extends Model
{
    use SoftDeletes;

    protected $table = 'rdo_mao_obra_cadastros';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'razao_social',
    'nome_fantasia',
    'cnpj',
    'responsavel',
    'telefone',
    'email',
    'active',
])]
class RdoSubcontratadaCadastro extends Model
{
    use SoftDeletes;

    protected $table = 'rdo_subcontratada_cadastros';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}

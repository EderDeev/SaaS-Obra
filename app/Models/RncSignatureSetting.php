<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'contract_id', 'signers', 'updated_by_id'])]
class RncSignatureSetting extends Model
{
    public const ROLES = ['cliente' => 'Cliente', 'gerenciadora' => 'Gerenciadora', 'construtora' => 'Construtora'];

    protected function casts(): array
    {
        return ['signers' => 'array'];
    }
}

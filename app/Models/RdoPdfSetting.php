<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'contract_id', 'configuration'])]
class RdoPdfSetting extends Model
{
    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }
}

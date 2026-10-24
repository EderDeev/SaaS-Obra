<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'contract_id', 'layout', 'options', 'updated_by_id'])]
class RncPdfSetting extends Model
{
    protected function casts(): array
    {
        return ['options' => 'array'];
    }
}

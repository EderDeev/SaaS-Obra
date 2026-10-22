<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'contract_id', 'user_id', 'role', 'active'])]
class OsDnitResponsible extends Model
{
    public const ROLES = ['analyst' => 'Analista DNIT', 'concremat' => 'Fiscal Concremat', 'contract_inspector' => 'Fiscal do contrato'];

    protected function casts(): array { return ['active' => 'boolean']; }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}

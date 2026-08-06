<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'contract_id', 'user_id', 'side', 'role', 'status', 'activity_permissions', 'project_permissions', 'documentation_permissions', 'diario_obra_permissions', 'ordem_servico_permissions', 'medicao_permissions', 'contract_permissions', 'invited_at', 'joined_at'])]
class ContractParticipant extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'activity_permissions' => 'array',
            'project_permissions' => 'array',
            'documentation_permissions' => 'array',
            'diario_obra_permissions' => 'array',
            'ordem_servico_permissions' => 'array',
            'medicao_permissions' => 'array',
            'contract_permissions' => 'array',
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

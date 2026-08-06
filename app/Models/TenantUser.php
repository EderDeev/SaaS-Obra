<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'user_id', 'empresa_id', 'role', 'status', 'ai_monthly_token_limit', 'activity_permissions', 'user_permissions', 'parametrizacao_permissions', 'project_permissions', 'budget_permissions', 'documentation_permissions', 'diario_obra_permissions', 'ordem_servico_permissions', 'medicao_permissions', 'contract_permissions', 'invited_at', 'joined_at'])]
class TenantUser extends Model
{
    protected function casts(): array
    {
        return [
            'activity_permissions' => 'array',
            'user_permissions' => 'array',
            'parametrizacao_permissions' => 'array',
            'project_permissions' => 'array',
            'budget_permissions' => 'array',
            'documentation_permissions' => 'array',
            'diario_obra_permissions' => 'array',
            'ordem_servico_permissions' => 'array',
            'medicao_permissions' => 'array',
            'contract_permissions' => 'array',
            'ai_monthly_token_limit' => 'integer',
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}

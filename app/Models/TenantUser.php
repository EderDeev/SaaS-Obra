<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'user_id', 'empresa_id', 'role', 'status', 'activity_permissions', 'user_permissions', 'parametrizacao_permissions', 'project_permissions', 'invited_at', 'joined_at'])]
class TenantUser extends Model
{
    protected function casts(): array
    {
        return [
            'activity_permissions' => 'array',
            'user_permissions' => 'array',
            'parametrizacao_permissions' => 'array',
            'project_permissions' => 'array',
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

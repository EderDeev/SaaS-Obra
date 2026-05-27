<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'contract_id', 'user_id', 'side', 'role', 'status', 'activity_permissions', 'project_permissions', 'invited_at', 'joined_at'])]
class ContractParticipant extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'activity_permissions' => 'array',
            'project_permissions' => 'array',
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'contract_id',
    'name',
    'email',
    'host',
    'port',
    'encryption',
    'username',
    'password',
    'mailbox',
    'post_action',
    'move_to',
    'is_active',
    'last_checked_at',
    'last_error',
    'settings',
])]
class GedEmailAccount extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    protected $hidden = [
        'password',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(GedEmailRule::class, 'account_id')->orderBy('priority')->orderBy('name');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'contract_id',
    'assigned_to_id',
    'created_by_id',
    'title',
    'description',
    'category',
    'visibility',
    'status',
    'priority',
    'due_date',
    'position',
    'completed_at',
])]
class Activity extends Model
{
    use SoftDeletes;

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_RESTRICTED = 'restricted';

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'activity_user')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ActivityComment::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ActivityFile::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query
                ->where('activities.visibility', self::VISIBILITY_PUBLIC)
                ->orWhere('activities.created_by_id', $user->id)
                ->orWhere('activities.assigned_to_id', $user->id)
                ->orWhereHas('assignees', fn (Builder $query): Builder => $query->where('users.id', $user->id));
        });
    }

    public function isVisibleTo(User $user): bool
    {
        if ($this->visibility === self::VISIBILITY_PUBLIC) {
            return true;
        }

        if ((int) $this->created_by_id === (int) $user->id || (int) $this->assigned_to_id === (int) $user->id) {
            return true;
        }

        return $this->assignees()->where('users.id', $user->id)->exists();
    }
}

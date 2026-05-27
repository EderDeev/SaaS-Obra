<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'phone', 'avatar_url', 'is_platform_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot(['role', 'status', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function contractParticipations(): HasMany
    {
        return $this->hasMany(ContractParticipant::class);
    }

    public function assignedActivities(): HasMany
    {
        return $this->hasMany(Activity::class, 'assigned_to_id');
    }

    public function activityAssignments(): BelongsToMany
    {
        return $this->belongsToMany(Activity::class, 'activity_user')->withTimestamps();
    }

    public function createdActivities(): HasMany
    {
        return $this->hasMany(Activity::class, 'created_by_id');
    }

    public function hasTenantAccess(Tenant $tenant): bool
    {
        if ($this->is_platform_admin) {
            return true;
        }

        return $this->tenantMemberships()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->exists()
            || $this->contractParticipations()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->exists();
    }

    public function tenantRole(Tenant $tenant): ?string
    {
        if ($this->is_platform_admin) {
            return 'tenant_owner';
        }

        return $this->tenantMemberships()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->value('role');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'contract_id',
    'obra_id',
    'disciplina_id',
    'project_phase_id',
    'created_by_id',
    'reviewed_by_id',
    'approved_by_id',
    'title',
    'code',
    'document_number',
    'document_type',
    'status',
    'reviewed_at',
    'review_notes',
    'approved_at',
    'approval_notes',
])]
class ProjectDocument extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
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

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class)->withTrashed();
    }

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class)->withTrashed();
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProjectDocumentVersion::class);
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(ProjectDocumentVersion::class)->latestOfMany();
    }

    public function latestApprovedVersion(): HasOne
    {
        return $this->hasOne(ProjectDocumentVersion::class)
            ->where('status', 'ativo')
            ->latestOfMany();
    }
}

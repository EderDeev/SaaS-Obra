<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'contract_id',
    'obra_id',
    'trecho_id',
    'project_phase_id',
    'submitted_by_id',
    'reviewed_by_id',
    'approved_by_id',
    'package_number',
    'package_sequence',
    'sequence_year',
    'title',
    'document_type',
    'status',
    'has_revisions',
    'cap_number',
    'cap_sequence',
    'cap_year',
    'cap_requested_at',
    'cap_reason',
    'cap_description',
    'cap_impacts',
    'reviewed_at',
    'review_notes',
    'approved_at',
    'approval_notes',
])]
class ProjectSubmissionBatch extends Model
{
    protected function casts(): array
    {
        return [
            'has_revisions' => 'boolean',
            'cap_impacts' => 'array',
            'cap_requested_at' => 'datetime',
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

    public function trecho(): BelongsTo
    {
        return $this->belongsTo(Trecho::class)->withTrashed();
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
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
        return $this->hasMany(ProjectDocumentVersion::class, 'project_submission_batch_id');
    }
}

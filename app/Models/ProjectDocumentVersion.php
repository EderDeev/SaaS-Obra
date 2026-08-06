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
    'project_document_id',
    'project_submission_batch_id',
    'uploaded_by_id',
    'reviewed_by_id',
    'approved_by_id',
    'cap_requested_by_id',
    'revision',
    'revision_change_summary',
    'cap_number',
    'cap_sequence',
    'cap_year',
    'cap_requested_at',
    'cap_reason',
    'cap_description',
    'cap_impacts',
    'status',
    'reviewed_at',
    'review_notes',
    'approved_at',
    'approval_notes',
    'original_name',
    'stored_name',
    'file_path',
    'storage_disk',
    'mime_type',
    'file_size',
    'aps_object_id',
    'aps_urn',
    'derivative_status',
    'submitted_to_aps_at',
    'processed_at',
])]
class ProjectDocumentVersion extends Model
{
    use SoftDeletes;

    protected $appends = ['url', 'size_label'];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'cap_requested_at' => 'datetime',
            'cap_impacts' => 'array',
            'submitted_to_aps_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ProjectDocument::class, 'project_document_id');
    }

    public function submissionBatch(): BelongsTo
    {
        return $this->belongsTo(ProjectSubmissionBatch::class, 'project_submission_batch_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function capRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cap_requested_by_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function reviewMarkups(): HasMany
    {
        return $this->hasMany(ProjectReviewMarkup::class, 'project_document_version_id');
    }

    public function reviewChecklist(): HasOne
    {
        return $this->hasOne(ProjectReviewChecklist::class, 'project_document_version_id');
    }

    public function getUrlAttribute(): ?string
    {
        if (blank($this->file_path)) {
            return null;
        }

        $tenant = request()->route('tenant');

        if (! $tenant instanceof Tenant || (int) $tenant->id !== (int) $this->tenant_id) {
            return null;
        }

        return route('tenant.projects.versions.download', [
            'tenant' => $tenant,
            'version' => $this,
        ], false);
    }

    public function storageDisk(): string
    {
        return $this->storage_disk ?: 'public';
    }

    public function getSizeLabelAttribute(): string
    {
        $bytes = (int) ($this->file_size ?? 0);

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1, ',', '.').' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, ',', '.').' KB';
        }

        return $bytes.' B';
    }

    public function getEapAttribute(): string
    {
        return $this->document?->eap($this->revision) ?? mb_strtoupper((string) $this->revision);
    }
}

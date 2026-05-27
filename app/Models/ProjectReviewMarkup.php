<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'contract_id',
    'project_document_id',
    'project_document_version_id',
    'created_by_id',
    'assigned_to_id',
    'closed_by_id',
    'title',
    'description',
    'markup_type',
    'markup_payload',
    'viewer_state',
    'priority',
    'status',
    'due_date',
    'closed_at',
])]
class ProjectReviewMarkup extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'markup_payload' => 'array',
            'viewer_state' => 'array',
            'due_date' => 'date',
            'closed_at' => 'datetime',
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

    public function document(): BelongsTo
    {
        return $this->belongsTo(ProjectDocument::class, 'project_document_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProjectDocumentVersion::class, 'project_document_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }
}

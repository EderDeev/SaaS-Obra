<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'project_review_checklist_id',
    'checked_by_id',
    'label',
    'required',
    'checked',
    'checked_at',
    'notes',
    'position',
])]
class ProjectReviewChecklistItem extends Model
{
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'checked' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(ProjectReviewChecklist::class, 'project_review_checklist_id');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'project_review_markup_id',
    'created_by_id',
    'body',
    'resolves_markup',
])]
class ProjectReviewMarkupReply extends Model
{
    protected function casts(): array
    {
        return [
            'resolves_markup' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function markup(): BelongsTo
    {
        return $this->belongsTo(ProjectReviewMarkup::class, 'project_review_markup_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}

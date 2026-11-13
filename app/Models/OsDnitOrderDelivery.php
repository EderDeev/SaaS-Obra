<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'revision', 'created_by_id', 'storage_disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'comment', 'decision', 'review_comment', 'reviewed_by_id', 'reviewed_at'])]
class OsDnitOrderDelivery extends Model
{
    protected $hidden = ['storage_disk', 'path'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}

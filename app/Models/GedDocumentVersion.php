<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_id',
    'uploaded_by_id',
    'version_number',
    'original_filename',
    'mime_type',
    'size_bytes',
    'checksum',
    'storage_disk',
    'path',
    'notes',
])]
class GedDocumentVersion extends Model
{
    public function document(): BelongsTo
    {
        return $this->belongsTo(GedDocument::class, 'document_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}

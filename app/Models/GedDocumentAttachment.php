<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_id',
    'uploaded_by_id',
    'title',
    'original_filename',
    'mime_type',
    'extension',
    'size_bytes',
    'checksum',
    'storage_disk',
    'path',
    'notes',
    'ocr_status',
    'extracted_text',
    'archive_path',
    'page_count',
    'processed_at',
    'ocr_metadata',
])]
class GedDocumentAttachment extends Model
{
    protected $casts = [
        'processed_at' => 'datetime',
        'ocr_metadata' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(GedDocument::class, 'document_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function isPdf(): bool
    {
        return strtolower((string) $this->extension) === 'pdf'
            || strtolower((string) $this->mime_type) === 'application/pdf';
    }
}

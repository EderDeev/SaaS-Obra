<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'tenant_id',
    'contract_id',
    'obra_id',
    'document_type_id',
    'correspondent_id',
    'uploaded_by_id',
    'title',
    'document_number',
    'sequence_year',
    'sequence_number',
    'document_date',
    'status',
    'description',
    'extracted_text',
    'page_count',
    'original_filename',
    'mime_type',
    'extension',
    'size_bytes',
    'checksum',
    'storage_disk',
    'original_path',
    'archive_path',
    'thumbnail_path',
    'metadata',
    'processed_at',
])]
class GedDocument extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'sequence_year' => 'integer',
            'sequence_number' => 'integer',
            'metadata' => 'array',
            'processed_at' => 'datetime',
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

    public function type(): BelongsTo
    {
        return $this->belongsTo(GedDocumentType::class, 'document_type_id');
    }

    public function correspondent(): BelongsTo
    {
        return $this->belongsTo(GedCorrespondent::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(GedTag::class, 'ged_document_tag', 'document_id', 'tag_id')->withTimestamps();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(GedDocumentVersion::class, 'document_id')->orderByDesc('version_number');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(GedDocumentAttachment::class, 'document_id')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GedDocumentEvent::class, 'document_id')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(GedDocumentNote::class, 'document_id')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function getFileUrlAttribute(): ?string
    {
        if (! $this->original_path) {
            return null;
        }

        return Storage::disk($this->storage_disk ?: 'public')->url($this->original_path);
    }
}

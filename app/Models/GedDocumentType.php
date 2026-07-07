<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'contract_id', 'name', 'description', 'matching_rules'])]
class GedDocumentType extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'matching_rules' => 'array',
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

    public function documents(): HasMany
    {
        return $this->hasMany(GedDocument::class, 'document_type_id');
    }
}

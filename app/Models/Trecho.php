<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'obra_id', 'codigo', 'nome', 'is_default'])]
class Trecho extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class)->withTrashed();
    }

    public function projectDocuments(): HasMany
    {
        return $this->hasMany(ProjectDocument::class);
    }

    public static function defaultForObra(Obra $obra): self
    {
        $existing = self::withTrashed()
            ->where('tenant_id', $obra->tenant_id)
            ->where('obra_id', $obra->id)
            ->where('codigo', 'GER')
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            $existing->forceFill([
                'nome' => $existing->nome ?: 'Geral',
                'is_default' => true,
            ])->save();

            return $existing;
        }

        return self::create([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'codigo' => 'GER',
            'nome' => 'Geral',
            'is_default' => true,
        ]);
    }
}

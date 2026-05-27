<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Empresa extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'contract_id',
        'tipo_empresa_id',
        'nome',
        'cnpj',
        'sigla',
        'logo_path',
    ];

    protected $appends = ['logo_url'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tipoEmpresa(): BelongsTo
    {
        return $this->belongsTo(TipoEmpresa::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return '/storage/'.ltrim(str_replace('\\', '/', $this->logo_path), '/');
    }
}

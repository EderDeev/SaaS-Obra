<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'obra_id',
    'cliente_empresa_id',
    'construtora_empresa_id',
    'fiscalizadora_empresa_id',
    'code',
    'name',
    'description',
    'client_company_name',
    'contractor_company_name',
    'total_value',
    'currency',
    'city',
    'state',
    'starts_at',
    'ends_at',
    'status',
])]
class Contract extends Model
{
    protected function casts(): array
    {
        return [
            'total_value' => 'decimal:2',
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ContractParticipant::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function relatorioNaoConformidadeResponsaveis(): HasMany
    {
        return $this->hasMany(RelatorioNaoConformidadeResponsavel::class);
    }

    public function projectDisciplineResponsaveis(): HasMany
    {
        return $this->hasMany(ProjectDisciplineResponsavel::class);
    }

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class)->withTrashed();
    }

    public function clienteEmpresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'cliente_empresa_id')->withTrashed();
    }

    public function construtoraEmpresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'construtora_empresa_id')->withTrashed();
    }

    public function fiscalizadoraEmpresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'fiscalizadora_empresa_id')->withTrashed();
    }

    public function gerenciadoraEmpresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'fiscalizadora_empresa_id')->withTrashed();
    }

    public function empresas(): HasMany
    {
        return $this->hasMany(Empresa::class);
    }

    public function obras(): HasMany
    {
        return $this->hasMany(Obra::class);
    }
}

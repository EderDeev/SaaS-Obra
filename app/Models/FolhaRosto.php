<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'contract_id',
    'obra_id',
    'ordem_servico_id',
    'boletim_medicao_id',
    'construtora_empresa_id',
    'created_by_id',
    'codigo',
    'sequencial',
    'comentario',
    'memoria_calculo_path',
    'memoria_calculo_nome_original',
    'memoria_calculo_mime_type',
    'memoria_calculo_size',
    'status',
    'submitted_for_analysis_at',
])]
class FolhaRosto extends Model
{
    use SoftDeletes;

    protected $table = 'folhas_rosto';

    protected function casts(): array
    {
        return [
            'sequencial' => 'integer',
            'memoria_calculo_size' => 'integer',
            'submitted_for_analysis_at' => 'datetime',
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

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class);
    }

    public function boletimMedicao(): BelongsTo
    {
        return $this->belongsTo(BoletimMedicao::class);
    }

    public function construtoraEmpresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'construtora_empresa_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(FolhaRostoItem::class);
    }

    public function analises(): HasMany
    {
        return $this->hasMany(FolhaRostoAnalise::class);
    }

    public function fluxoHistoricos(): HasMany
    {
        return $this->hasMany(FolhaRostoFluxoHistorico::class);
    }
}

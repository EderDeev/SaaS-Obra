<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'rdo_configuracao_id',
    'contract_id',
    'obra_id',
    'responsible_user_id',
    'created_by_id',
    'copied_from_rdo_id',
    'sequence_number',
    'code',
    'reference_date',
    'status',
    'generated_automatically',
    'mobile_local_uuid',
    'submitted_at',
    'approved_at',
])]
class RdoDiario extends Model
{
    use SoftDeletes;

    protected $table = 'rdo_diarios';

    protected function casts(): array
    {
        return [
            'reference_date' => 'date',
            'generated_automatically' => 'boolean',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function configuracao(): BelongsTo
    {
        return $this->belongsTo(RdoConfiguracao::class, 'rdo_configuracao_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class)->withTrashed();
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'copied_from_rdo_id')->withTrashed();
    }

    public function secoes(): HasMany
    {
        return $this->hasMany(RdoSecaoRegistro::class, 'rdo_diario_id');
    }

    public function analises(): HasMany
    {
        return $this->hasMany(RdoAnalise::class, 'rdo_diario_id');
    }

    public function signatureRequests(): HasMany
    {
        return $this->hasMany(RdoSignatureRequest::class, 'rdo_diario_id');
    }
}

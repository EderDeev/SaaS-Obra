<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'contract_id',
    'obra_id',
    'responsible_user_id',
    'created_by_id',
    'start_date',
    'end_date',
    'generation_time',
    'timezone',
    'generation_weekdays',
    'generate_on_holidays',
    'copy_previous_day',
    'copy_workforce',
    'copy_equipment',
    'copy_pending_activities',
    'require_photos',
    'digital_signature_enabled',
    'submission_deadline_days',
    'active',
])]
class RdoConfiguracao extends Model
{
    protected $table = 'rdo_configuracoes';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'generation_weekdays' => 'array',
            'generate_on_holidays' => 'boolean',
            'copy_previous_day' => 'boolean',
            'copy_workforce' => 'boolean',
            'copy_equipment' => 'boolean',
            'copy_pending_activities' => 'boolean',
            'require_photos' => 'boolean',
            'digital_signature_enabled' => 'boolean',
            'active' => 'boolean',
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

    public function obras(): BelongsToMany
    {
        return $this->belongsToMany(Obra::class, 'rdo_configuracao_obras')->withTimestamps();
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function diarios(): HasMany
    {
        return $this->hasMany(RdoDiario::class);
    }
}

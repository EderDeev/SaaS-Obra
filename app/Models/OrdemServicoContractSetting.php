<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'contract_id',
    'require_project',
    'require_document',
    'require_deadline',
    'require_execution_responsible',
    'created_by_id',
    'updated_by_id',
])]
class OrdemServicoContractSetting extends Model
{
    protected $table = 'ordem_servico_contract_settings';

    protected function casts(): array
    {
        return [
            'require_project' => 'boolean',
            'require_document' => 'boolean',
            'require_deadline' => 'boolean',
            'require_execution_responsible' => 'boolean',
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
}

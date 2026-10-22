<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'contract_id', 'code', 'name', 'category', 'unit', 'frequency'])]
class OsDnitProduct extends Model
{
    public const CATEGORIES = [
        'gestao_controle' => 'Gestão e controle',
        'supervisao_obras' => 'Supervisão de obras',
        'controle_tecnologico' => 'Controle tecnológico',
        'controle_executivo' => 'Controle executivo',
        'levantamentos_estudos' => 'Levantamentos e estudos',
        'sondagens' => 'Sondagens',
        'projetos_inspecoes' => 'Projetos e inspeções',
        'ambiental' => 'Ambiental',
    ];

    public const FREQUENCIES = [
        'monthly' => 'Mensal',
        'on_demand' => 'Por demanda',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}

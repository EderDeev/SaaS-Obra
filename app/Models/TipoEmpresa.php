<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nome'])]
class TipoEmpresa extends Model
{
    protected $table = 'tipos_empresa';

    public const ALLOWED_COMPANY_TYPES = [
        'gerenciadora' => 'Gerenciadora',
        'construtora' => 'Construtora',
        'cliente' => 'Cliente',
    ];

    public function empresas(): HasMany
    {
        return $this->hasMany(Empresa::class);
    }

    public static function allowedCompanyTypeNames(): array
    {
        return array_keys(self::ALLOWED_COMPANY_TYPES);
    }

    public static function allowedCompanyTypeOptions()
    {
        return self::query()
            ->whereIn('nome', self::allowedCompanyTypeNames())
            ->orderByRaw("case nome when 'gerenciadora' then 1 when 'construtora' then 2 when 'cliente' then 3 else 4 end")
            ->get(['id', 'nome'])
            ->map(fn (self $tipo): array => [
                'id' => $tipo->id,
                'nome' => $tipo->nome,
                'label' => self::ALLOWED_COMPANY_TYPES[$tipo->nome] ?? ucfirst($tipo->nome),
            ])
            ->values();
    }
}

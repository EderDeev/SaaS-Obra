<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'cnpj', 'plan', 'status', 'branding', 'settings', 'trial_ends_at'])]
class Tenant extends Model
{
    /** @use \Illuminate\Database\Eloquent\Factories\HasFactory<TenantFactory> */
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected function casts(): array
    {
        return [
            'branding' => 'array',
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot(['empresa_id', 'role', 'status', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function activityComments(): HasMany
    {
        return $this->hasMany(ActivityComment::class);
    }

    public function activityFiles(): HasMany
    {
        return $this->hasMany(ActivityFile::class);
    }

    public function relatorioNaoConformidades(): HasMany
    {
        return $this->hasMany(RelatorioNaoConformidade::class);
    }

    public function relatorioNaoConformidadeResponsaveis(): HasMany
    {
        return $this->hasMany(RelatorioNaoConformidadeResponsavel::class);
    }

    public function empresas(): HasMany
    {
        return $this->hasMany(Empresa::class);
    }

    public function obras(): HasMany
    {
        return $this->hasMany(Obra::class);
    }

    public function disciplinas(): HasMany
    {
        return $this->hasMany(Disciplina::class);
    }

    public function projectDocuments(): HasMany
    {
        return $this->hasMany(ProjectDocument::class);
    }

    public function projectDisciplineResponsaveis(): HasMany
    {
        return $this->hasMany(ProjectDisciplineResponsavel::class);
    }

    public function orcamentoInsumos(): HasMany
    {
        return $this->hasMany(OrcamentoInsumo::class);
    }

    public function orcamentos(): HasMany
    {
        return $this->hasMany(Orcamento::class);
    }

    public function orcamentoEtapas(): HasMany
    {
        return $this->hasMany(OrcamentoEtapa::class);
    }

    public function orcamentoComposicoes(): HasMany
    {
        return $this->hasMany(OrcamentoComposicao::class);
    }

    public function orcamentoComposicaoItems(): HasMany
    {
        return $this->hasMany(OrcamentoComposicaoItem::class);
    }

    public function medicaoItens(): HasMany
    {
        return $this->hasMany(MedicaoItem::class);
    }

    public function boletinsMedicao(): HasMany
    {
        return $this->hasMany(BoletimMedicao::class);
    }

    public function ordemServicos(): HasMany
    {
        return $this->hasMany(OrdemServico::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

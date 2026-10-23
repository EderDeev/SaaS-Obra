<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstrucaoCampo extends Model
{
    use SoftDeletes;

    protected $table = 'instrucoes_campo';

    protected $guarded = ['id'];

    protected $appends = ['formatted_number'];

    public const STATUSES = ['draft' => 'Rascunho', 'notified' => 'Aguardando correção', 'correction_submitted' => 'Aguardando verificação', 'closed' => 'Resolvida', 'rnc_required' => 'Requer RNC'];

    protected function casts(): array
    {
        return ['opened_at' => 'date', 'notified_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function getFormattedNumberAttribute(): string
    {
        return sprintf('%03d/%d', $this->sequence_number, $this->sequence_year);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function obra()
    {
        return $this->belongsTo(Obra::class)->withTrashed();
    }

    public function disciplina()
    {
        return $this->belongsTo(Disciplina::class)->withTrashed();
    }

    public function contratante()
    {
        return $this->belongsTo(Empresa::class, 'contratante_empresa_id')->withTrashed();
    }

    public function contratada()
    {
        return $this->belongsTo(Empresa::class, 'contratada_empresa_id')->withTrashed();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function photos()
    {
        return $this->hasMany(InstrucaoCampoPhoto::class)->orderBy('position')->orderBy('id');
    }

    public function events()
    {
        return $this->hasMany(InstrucaoCampoEvent::class)->orderBy('id');
    }
}

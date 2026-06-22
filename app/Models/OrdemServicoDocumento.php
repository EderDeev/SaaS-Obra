<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ordem_servico_id',
    'uploaded_by_id',
    'nome_original',
    'path',
    'mime_type',
    'size',
])]
class OrdemServicoDocumento extends Model
{
    protected $table = 'ordem_servico_documentos';

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}

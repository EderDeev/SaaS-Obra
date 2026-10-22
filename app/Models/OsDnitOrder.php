<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'contract_id', 'highway_id', 'created_by_id', 'request_number', 'supervision_lot', 'highway_name', 'km_start', 'km_end', 'subject', 'desired_deadline', 'technical_need', 'justification', 'sei_reference', 'status', 'workflow_version', 'submitted_at', 'review_comment', 'estimated_deadline', 'review_items', 'analyzed_by_id', 'reviewed_at'])]
class OsDnitOrder extends Model
{
    use SoftDeletes;
    protected $attributes = ['status' => 'draft', 'workflow_version' => 1];

    public const STATUSES = [
        'draft' => 'Rascunho', 'awaiting_concremat' => 'Em análise Concremat',
        'awaiting_contract' => 'Aguardando fiscal do contrato', 'returned_concremat' => 'Devolvida à Concremat',
        'returned_analyst' => 'Devolvida ao analista DNIT', 'approved' => 'Aprovada',
    ];

    public const STAGES = [
        'analyst' => ['draft', 'returned_analyst'],
        'concremat' => ['awaiting_concremat', 'returned_concremat'],
        'contract_inspector' => ['awaiting_contract'],
        'completed' => ['approved'],
    ];

    protected function casts(): array
    {
        return ['km_start' => 'decimal:3', 'km_end' => 'decimal:3', 'desired_deadline' => 'date:Y-m-d',
            'estimated_deadline' => 'date:Y-m-d', 'review_items' => 'array', 'workflow_version' => 'integer',
            'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OsDnitOrderItem::class, 'order_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OsDnitOrderAttachment::class, 'order_id');
    }

    public function analystReview(): BelongsTo { return $this->belongsTo(User::class, 'analyzed_by_id'); }

    public function events(): HasMany { return $this->hasMany(OsDnitOrderEvent::class, 'order_id')->orderBy('id'); }
}

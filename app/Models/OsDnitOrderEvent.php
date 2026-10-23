<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'actor_id', 'action', 'from_status', 'to_status', 'comment', 'snapshot'])]
class OsDnitOrderEvent extends Model
{
    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OsDnitOrder::class, 'order_id');
    }
}

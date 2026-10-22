<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['order_id', 'product_id', 'product_code', 'product_name', 'unit', 'frequency', 'quantity'])]
class OsDnitOrderItem extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }
}

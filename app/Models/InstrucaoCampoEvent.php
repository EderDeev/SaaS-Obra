<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstrucaoCampoEvent extends Model
{
    protected $table = 'instrucao_campo_events';

    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function photos()
    {
        return $this->hasMany(InstrucaoCampoPhoto::class, 'event_id');
    }
}

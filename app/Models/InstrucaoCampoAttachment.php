<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstrucaoCampoAttachment extends Model
{
    protected $table = 'instrucao_campo_attachments';

    protected $guarded = ['id'];

    protected $hidden = ['path'];
}

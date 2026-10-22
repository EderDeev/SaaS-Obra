<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['order_id', 'original_name', 'storage_disk', 'path', 'mime_type', 'size_bytes'])]
#[Hidden(['storage_disk', 'path'])]
class OsDnitOrderAttachment extends Model
{
}

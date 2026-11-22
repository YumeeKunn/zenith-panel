<?php

namespace Serverbackgrounds\Models;

use Illuminate\Database\Eloquent\Model;

class Background extends Model
{
    protected $table = 'ext_serverbackgrounds';
    protected $fillable = ['server_id', 'image_url'];
}

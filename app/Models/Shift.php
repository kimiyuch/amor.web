<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $fillable = ['date', 'code', 'scan_id'];

    protected $casts = ['date' => 'date:Y-m-d'];
}

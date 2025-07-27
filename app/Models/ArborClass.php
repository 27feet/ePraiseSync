<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArborClass extends Model
{
    public $timestamps = true;
    protected $fillable = [
        'arbor_id',
        'name',
        'start_date_time',
        'end_date_time',
    ];

    protected $casts = [
        'arbor_id' => 'integer',
        'name' => 'string',
        'start_date_time' => 'datetime',
        'end_date_time' => 'datetime',
    ];
}

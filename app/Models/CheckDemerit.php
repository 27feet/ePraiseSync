<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckDemerit extends Model
{
    public $timestamps = true;
    protected $fillable = [

        'record_id',
        'date',
        'last_seen_at',
        'last_seen_run',
    ];

    protected $casts = [
        'date' => 'datetime',
        'record_id' => 'integer',
        'last_seen_at' => 'datetime',
        'last_seen_run' => 'string',
    ];
}

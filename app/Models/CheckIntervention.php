<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckIntervention extends Model
{
    public $timestamps = true;
    protected $fillable = [

        'record_id',
        'date',
    ];

    protected $casts = [
        'date' => 'datetime',
        'record_id' => 'integer',
    ];
}

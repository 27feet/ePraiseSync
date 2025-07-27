<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArborStaff extends Model
{
    public $timestamps = true;
    protected $fillable = [

        'arbor_id',
        'email',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'arbor_id' => 'string',
        'email' => 'string',
    ];
}

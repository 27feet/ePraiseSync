<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Student extends Model
{
    public $timestamps = true;
    protected $fillable = [

        'record_id',
        'arbor_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'record_id' => 'integer',
        'arbor_id' => 'string',
    ];

    public function points(): BelongsToMany
    {
        return $this->belongsToMany(Point::class, 'student_id', 'record_id');
    }
}

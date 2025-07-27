<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Course extends Model
{
    public $timestamps = true;

    protected $fillable = [

        'course_id',
        'course_name',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'course_id' => 'integer',
        'course_name' => 'string',
    ];

    public function points(): BelongsToMany
    {
        return $this->belongsToMany(Point::class, 'course_id', 'course_id');
    }
}

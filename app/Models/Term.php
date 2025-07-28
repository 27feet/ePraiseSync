<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Term extends Model
{
    public $timestamps = true;

    protected $fillable = [

        'arbor_id',
        'term_name',
        'academic_year_id',
        'start_date',
        'end_date',
        'first_school_day_date',
        'last_school_day_date',
        'first_school_day_id',
        'last_school_day_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'arbor_id' => 'integer',
        'term_name' => 'string',
        'academic_year_id' => 'integer',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'first_school_day_date' => 'datetime',
        'last_school_day_date' => 'datetime',
        'first_school_day_id' => 'integer',
        'last_school_day_id' => 'integer',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'arbor_id', 'academic_year_id');
    }
    public function academicDays(): HasMany
    {
        return $this->hasMany(AcademicCalendarDate::class, 'term_id', 'arbor_id');
    }
}

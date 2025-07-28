<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AcademicCalendarDate extends Model
{
    public $timestamps = true;

    protected $fillable = [

        'arbor_id',
        'academic_year_id',
        'term_id',
        'date',
        'calendar_year',
        'calendar_month',
        'calendar_day',
        'calendar_week',
        'day_of_term',
        'day_of_academic_year',
        'is_school_day',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'arbor_id' => 'integer',
        'academic_year_id' => 'integer',
        'term_id' => 'integer',
        'date' => 'datetime',
        'calendar_year' => 'integer',
        'calendar_month' => 'integer',
        'calendar_day' => 'integer',
        'calendar_week' => 'integer',
        'day_of_term' => 'integer',
        'day_of_academic_year' => 'integer',
        'is_school_day' => 'boolean',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'arbor_id', 'academic_year_id');
    }
    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'arbor_id', 'term_id');
    }
}

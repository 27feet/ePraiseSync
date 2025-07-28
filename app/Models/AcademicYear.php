<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AcademicYear extends Model
{
    public $timestamps = true;

    protected $fillable = [

        'arbor_id',
        'code',
        'academic_year_name',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'arbor_id' => 'integer',
        'code' => 'string',
        'academic_year_name' => 'string',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];


    public function terms(): HasMany
    {
        return $this->hasMany(Term::class, 'academic_year_id', 'arbor_id');
    }
    public function detentionTypes(): HasMany
    {
        return $this->hasMany(DetentionType::class, 'academic_year_id', 'arbor_id');
    }
    public function calendarDates(): HasMany
    {
        return $this->hasMany(AcademicCalendarDate::class, 'academic_year_id', 'arbor_id');
    }
}

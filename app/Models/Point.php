<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Point extends Model
{
    protected $fillable = [

        'record_id',
        'student_id',
        'teacher_id',
        'class_id',
        'subject',
        'category',
        'date',
        'points',
        'arbor_id',
        'checksum',
        'matched_teacher',
        'matched_class',
        'sync_status',
        'synced_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'date' => 'datetime',
        'points' => 'integer',
        'record_id' => 'integer',
        'student_id' => 'integer',
        'teacher_id' => 'integer',
        'class_id' => 'integer',
        'arbor_id' => 'string',
        'checksum' => 'string',
        'subject' => 'string',
        'category' => 'string',
        'matched_teacher' => 'boolean',
        'matched_class' => 'boolean',
        'sync_status' => 'integer',
        'synced_at' => 'datetime',
    ];

    public $timestamps = true;

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'record_id');
    }
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'record_id');
    }
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'course_id');
    }

    public function stillLive(): HasOne
    {
        return $this->hasOne(CheckPoint::class, 'record_id', 'record_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Demerit extends Model
{

    protected $fillable = [

        'record_id',
        'student_id',
        'teacher_id',
        'class_id',
        'subject',
        'category',
        'reason',
        'date',
        'points',
        'arbor_point_id',
        'arbor_behaviour_id',
        'arbor_behaviour_student_id',
        'arbor_action_id',
        'arbor_detention_id',
        'sync_status',
        'comment',
        'matched_teacher',
        'matched_class',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'synced_at' => 'datetime',
        'date' => 'datetime',
        'points' => 'integer',
        'record_id' => 'integer',
        'student_id' => 'integer',
        'teacher_id' => 'integer',
        'class_id' => 'integer',
        'sync_status' => 'integer',
        'arbor_point_id' => 'string',
        'arbor_behaviour_id' => 'string',
        'arbor_behaviour_student_id' => 'string',
        'arbor_action_id' => 'json',
        'arbor_detention_id' => 'string',
        'subject' => 'string',
        'category' => 'string',
        'reason' => 'string',
        'comment' => 'string',
        'matched_teacher' => 'boolean',
        'matched_class' => 'boolean',
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
        return $this->hasOne(CheckDemerit::class, 'record_id', 'record_id');
    }
}

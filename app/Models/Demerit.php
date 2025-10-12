<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Demerit extends Model
{
    public const STATUS_REQUIRES_CREATE = 1;
    public const STATUS_REQUIRES_DELETION = -1;
    public const STATUS_REQUIRES_UPDATE = -2;
    public const STATUS_READY_TO_DELETE = -10;
    public const STATUS_READY_TO_UPDATE = -20;
    public const STATUS_READY_TO_CREATE = 10;
    public const STATUS_FAILED_DELETION = -11;
    public const STATUS_FAILED_CREATION = 11;
    public const STATUS_FAILED_UPDATE = -21;
    public const STATUS_PROCESSED_SUCCESSFULLY = 0;
    public const STATUS_DELETED = -100;
    public const STATUS_PARTIALLY_DELETED = -101;

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

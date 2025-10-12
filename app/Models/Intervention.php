<?php

namespace App\Models;

use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Intervention extends Model
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

    public $timestamps = true;
    protected $fillable = [

        'record_id',
        'student_id',
        'teacher_id',
        'demerit_id',
        'subject',
        'intervention_type',
        'date_added',
        'comment',
        'matched_teacher',
        'matched_class',
        'sync_status',
        'arbor_point_id',
        'arbor_behaviour_id',
        'arbor_behaviour_student_id',
        'arbor_action_id',
        'arbor_detention_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'synced_at' => 'datetime',
        'date_added' => 'datetime',
        'record_id' => 'integer',
        'student_id' => 'integer',
        'teacher_id' => 'integer',
        'demerit_id' => 'integer',
        'sync_status' => 'integer',
        'subject' => 'string',
        'intervention_type' => 'string',
        'comment' => 'string',
        'matched_teacher' => 'boolean',
        'matched_class' => 'boolean',
        'sync_status' => 'integer',
        'arbor_point_id' => 'string',
        'arbor_behaviour_id' => 'string',
        'arbor_behaviour_student_id' => 'string',
        'arbor_action_id' => 'json',
        'arbor_detention_id' => 'string',
    ];
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'record_id');
    }
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'record_id');
    }

    public function stillLive(): HasOne
    {
        return $this->hasOne(CheckIntervention::class, 'record_id', 'record_id');
    }
}

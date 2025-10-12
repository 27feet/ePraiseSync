<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehaviourNotificationLog extends Model
{
    public $timestamps = true;

    protected $table = 'behaviour_notification_logs';

    protected $fillable = [
        'rule_id',
        'student_id',

        // What was counted
        'source',              // 'demerit' | 'intervention'
        'category',            // e.g. 'Uniform', 'Skirt'

        // Threshold reached
        'hit_threshold',

        // Window details
        'window_type',                   // 'term' | 'rolling_school_days' | 'today' (nullable)
        'window_start_date',
        'window_end_date',
        'window_term_id',
        'window_school_days_lookback',

        // Snapshots
        'source_snapshot',
        'recipients_snapshot',
    ];

    protected $casts = [
        'rule_id'                     => 'integer',
        'student_id'                  => 'integer',
        'hit_threshold'               => 'integer',
        'window_term_id'              => 'integer',
        'window_school_days_lookback' => 'integer',

        'window_start_date'           => 'date',
        'window_end_date'             => 'date',

        'source_snapshot'             => 'array',
        'recipients_snapshot'         => 'array',

        'created_at'                  => 'datetime',
        'updated_at'                  => 'datetime',
    ];

    /* Relationships */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(BehaviourNotificationRule::class, 'rule_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /* Scopes */
    public function scopeForRuleAndStudent(Builder $q, int $ruleId, int $studentId): Builder
    {
        return $q->where('rule_id', $ruleId)
            ->where('student_id', $studentId);
    }

    public function scopeAtThreshold(Builder $q, int $threshold): Builder
    {
        return $q->where('hit_threshold', $threshold);
    }

    public function scopeInWindow(Builder $q, \DateTimeInterface $start, \DateTimeInterface $end): Builder
    {
        return $q->whereDate('window_start_date', $start)
            ->whereDate('window_end_date', $end);
    }

    public function scopeTodayWindow(Builder $q): Builder
    {
        return $q->where('window_type', 'today');
    }
}

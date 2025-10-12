<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property int         $id
 * @property string      $student_record_id   ePraise student id (maps to students.record_id)
 * @property string|null $student_arbor_id    Arbor/UPN (maps to students.arbor_id)
 * @property string|null $tutor_names_raw
 * @property string|null $hoy_names_raw
 * @property array|null  $tutor_normalized_names
 * @property array|null  $hoy_normalized_names
 * @property array|null  $tutor_staff_ids
 * @property array|null  $hoy_staff_ids
 * @property array|null  $tutor_emails
 * @property array|null  $hoy_emails
 * @property array|null  $extra_staff_emails
 */
class BehaviourNotificationStudentRouting extends Model
{
    public $timestamps = true;

    protected $table = 'behaviour_notification_student_routing';

    protected $fillable = [
        'student_record_id',
        'student_arbor_id',

        'tutor_names_raw',
        'hoy_names_raw',

        'tutor_normalized_names',
        'hoy_normalized_names',

        'tutor_staff_ids',
        'hoy_staff_ids',

        'tutor_emails',
        'hoy_emails',

        'extra_staff_emails',
    ];

    protected $casts = [
        'tutor_normalized_names' => 'array',
        'hoy_normalized_names'   => 'array',
        'tutor_staff_ids'        => 'array',
        'hoy_staff_ids'          => 'array',
        'tutor_emails'           => 'array',
        'hoy_emails'             => 'array',
        'extra_staff_emails'     => 'array',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
    ];

    /* ----------------------------- Scopes ----------------------------- */

    /**
     * Locate by local ePraise student id (students.record_id).
     */
    public function scopeForStudentRecord(Builder $q, string $recordId): Builder
    {
        return $q->where('student_record_id', $recordId);
    }

    /**
     * Locate by Arbor/UPN (students.arbor_id).
     */
    public function scopeForStudentArbor(Builder $q, string $arborId): Builder
    {
        return $q->where('student_arbor_id', $arborId);
    }

    /* --------------------------- Helpers ----------------------------- */

    /**
     * Combined staff recipient list for this student (unique, lowercased).
     * Useful if you just want “all staff” regardless of role.
     */
    public function allStaffEmails(): array
    {
        $lists = [
            $this->tutor_emails ?? [],
            $this->hoy_emails ?? [],
            $this->extra_staff_emails ?? [],
        ];

        $flat = array_merge(...$lists);
        $flat = array_map(fn($e) => is_string($e) ? mb_strtolower($e) : $e, $flat);

        return array_values(array_unique(array_filter($flat)));
    }
}

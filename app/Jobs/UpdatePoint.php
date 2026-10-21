<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Point;
use App\Models\Course;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\ArborClass;
use App\Models\ArborStaff;
use App\Models\ArborPointCategory;
use App\Models\EpraisePointCategory;
use App\Jobs\Concerns\HandlesArborWriteback;
use App\Support\Checksum;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdatePoint implements ShouldQueue
{
    use Queueable;
    use HandlesArborWriteback;

    protected Point $point;
    protected ?int $lastErrorStatus = null;
    protected ?string $lastErrorBody = null;

    public function __construct(string $pointID)
    {
        $this->point = Point::where('id', $pointID)->firstOrFail();
    }

    private function computeChecksum(): string
    {
        $fields = [
            'student_id' => $this->point->student_id,
            'teacher_id' => $this->point->teacher_id,
            'class_id'   => $this->point->class_id,
            'subject'    => $this->point->subject,
            'category'   => $this->point->category,
            'date'       => $this->point->date,
            'points'     => $this->point->points,
        ];
        return Checksum::fromFields($fields);
    }

    protected function performWriteback(): int
    {
        $writeCount = 0;

        // Only proceed when explicitly ready to update
        if ($this->point->sync_status !== Point::STATUS_READY_TO_UPDATE) {
            return 0;
        }

        // Must have a remote ID to update
        if (!$this->point->arbor_id) {
            $this->rememberError(null, 'Missing Arbor point id for update');
            Log::warning('UpdatePoint: missing remote arbor_id; cannot update', [
                'point_id' => $this->point->id,
            ]);
            $this->point->forceFill([
                'synced_at'   => now(),
                'sync_status' => Point::STATUS_FAILED_UPDATE,
                'sync_next_retry_at' => null,
                'sync_last_error_status' => $this->lastErrorStatus,
                'sync_last_error_body' => $this->lastErrorBody,
            ])->save();
            return 0;
        }

        // Pre-mark as failed (attempt in progress)
        $this->point->forceFill([
            'sync_status' => Point::STATUS_FAILED_UPDATE,
        ])->save();

        $client = $this->arborClient();

        $matchedTeacher = true;
        $matchedClass   = true;

        // Resolve references
        $stuID = Student::where('record_id', $this->point->student_id)->value('arbor_id');

        $teacherEmail = Teacher::where('record_id', $this->point->teacher_id)->value('email');
        $teacherEmail = $teacherEmail ? mb_strtolower($teacherEmail) : null;
        $teacherID = $teacherEmail
            ? ArborStaff::whereRaw('LOWER(email) = ?', [$teacherEmail])->value('arbor_id')
            : null;

        // Mapping (required)
        $internalReferenceId = EpraisePointCategory::where('epraise_name', $this->point->category)
            ->value('arbor_point_category_id');
        $pointCategory = ArborPointCategory::findOrFail($internalReferenceId);

        // Early guard: missing student
        if (!$stuID) {
            $this->rememberError(null, 'Missing student Arbor ID');
            Log::warning('UpdatePoint: missing student Arbor ID; aborting', [
                'point_id'          => $this->point->id,
                'student_record_id' => $this->point->student_id,
            ]);
            $this->point->forceFill([
                'synced_at'       => now(),
                'sync_status'     => Point::STATUS_FAILED_UPDATE,
                'matched_teacher' => false,
                'matched_class'   => false,
                'sync_next_retry_at' => null,
                'sync_last_error_status' => $this->lastErrorStatus,
                'sync_last_error_body' => $this->lastErrorBody,
            ])->save();
            return $writeCount;
        }

        // Optional event
        $className = Course::where('course_id', $this->point->class_id)->value('course_name');
        $eventID   = ArborClass::where('name', $className)
            ->where('start_date_time', '<=', Carbon::parse($this->point->date))
            ->orderBy('start_date_time', 'desc')
            ->value('arbor_id');

        // Payload
        $pointScale      = $pointCategory->ArborPointScale()->value('arbor_id');
        $awardedDatetime = Carbon::parse($this->point->date)->toDateTimeString();

        $payload = [
            'request' => [
                'pointAward' => [
                    'student' => [
                        'entityType' => 'Student',
                        'href'       => '/rest-v2/students/' . $stuID
                    ],
                    'pointAwardScale' => [
                        'entityType' => 'PointAwardScale',
                        'href'       => '/rest-v2/point-award-scales/' . $pointScale
                    ],
                    'pointAwardCategory' => [
                        'entityType' => 'PointAwardCategory',
                        'href'       => '/rest-v2/point-award-categories/' . $pointCategory->arbor_id
                    ],
                    'event' => [
                        'entityType' => 'Session',
                        'href'       => '/rest-v2/sessions/' . $eventID
                    ],
                    'awardedByStaff' => [
                        'entityType' => 'Staff',
                        'href'       => '/rest-v2/staffs/' . $teacherID
                    ],
                    'points'          => (int) $this->point->points,
                    'awardedDatetime' => $awardedDatetime,
                ]
            ]
        ];

        if (!$eventID) {
            unset($payload['request']['pointAward']['event']);
            $matchedClass = false;
        }
        if (!$teacherID) {
            unset($payload['request']['pointAward']['awardedByStaff']);
            $matchedTeacher = false;
        }

        // PUT update (plural endpoint)
        $response = $client->put('/rest-v2/point-awards/' . $this->point->arbor_id, $payload);
        $writeCount++;

        if ($response->successful()) {
            $checksum = $this->computeChecksum();
            $this->point->forceFill([
                'synced_at'       => now(),
                'sync_status'     => Point::STATUS_PROCESSED_SUCCESSFULLY,
                'matched_teacher' => $matchedTeacher,
                'matched_class'   => $matchedClass,
                'checksum'        => $checksum,
                'synced_checksum' => $checksum,
                'sync_attempts'   => 0,
                'sync_last_failed_at' => null,
                'sync_next_retry_at' => null,
                'sync_last_error_status' => null,
                'sync_last_error_body' => null,
            ])->save();
        } else {
            $this->rememberError($response->status(), $response->body());
            Log::warning('UpdatePoint: point-awards update failed', [
                'point_id' => $this->point->id,
                'arbor_id' => $this->point->arbor_id,
                'status'   => $response->status(),
                'body'     => $response->body(),
                'payload'  => $payload,
            ]);
            $this->point->forceFill([
                'synced_at'   => now(),
                'sync_status' => Point::STATUS_FAILED_UPDATE,
                'sync_next_retry_at' => null,
                'sync_last_error_status' => $this->lastErrorStatus,
                'sync_last_error_body' => $this->lastErrorBody,
            ])->save();
        }

        return $writeCount;
    }

    private function rememberError(?int $status, string $body): void
    {
        $this->lastErrorStatus = $status;
        $this->lastErrorBody = substr($body, 0, 4000);
    }
}

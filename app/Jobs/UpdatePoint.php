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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdatePoint implements ShouldQueue
{
    use Queueable;

    protected Point $point;

    public function __construct(string $pointID)
    {
        $this->point = Point::where('id', $pointID)->firstOrFail();
    }

    public function handle(): int
    {
        $writeCount = 0;

        // Only proceed when explicitly ready to update
        if ($this->point->sync_status !== Point::STATUS_READY_TO_UPDATE) {
            return 0;
        }

        // Must have a remote ID to update
        if (!$this->point->arbor_id) {
            Log::warning('UpdatePoint: missing remote arbor_id; cannot update', [
                'point_id' => $this->point->id,
            ]);
            $this->point->forceFill([
                'synced_at'   => now(),
                'sync_status' => Point::STATUS_FAILED_UPDATE,
            ])->save();
            return 0;
        }

        // Pre-mark as failed (attempt in progress)
        $this->point->forceFill([
            'sync_status' => Point::STATUS_FAILED_UPDATE,
        ])->save();

        $client = Http::baseUrl(config('services.arbor.base_url'))
            ->withBasicAuth(config('services.arbor.username'), config('services.arbor.password'))
            ->acceptJson()
            ->asJson();

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
            Log::warning('UpdatePoint: missing student Arbor ID; aborting', [
                'point_id'          => $this->point->id,
                'student_record_id' => $this->point->student_id,
            ]);
            $this->point->forceFill([
                'synced_at'       => now(),
                'sync_status'     => Point::STATUS_FAILED_UPDATE,
                'matched_teacher' => false,
                'matched_class'   => false,
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
            $this->point->forceFill([
                'synced_at'       => now(),
                'sync_status'     => Point::STATUS_PROCESSED_SUCCESSFULLY,
                'matched_teacher' => $matchedTeacher,
                'matched_class'   => $matchedClass,
            ])->save();
        } else {
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
            ])->save();
        }

        return $writeCount;
    }
}

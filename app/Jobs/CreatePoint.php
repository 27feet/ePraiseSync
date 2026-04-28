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
use App\Support\ArborHttp;
use App\Support\Checksum;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreatePoint implements ShouldQueue
{
    use Queueable;

    protected Point $point;
    protected ?int $lastErrorStatus = null;
    protected ?string $lastErrorBody = null;

    public function __construct(string $pointID)
    {
        $this->point = Point::where('id', $pointID)->firstOrFail();
    }

    public function handle(): int
    {
        $writeCount = 0;

        // Idempotency: already synced?
        if ($this->point->arbor_id) {
            return 0;
        }

        if ($this->point->sync_status == Point::STATUS_READY_TO_CREATE) {
            // Pre-mark as failed (attempt signal)
            $this->point['sync_status'] = Point::STATUS_FAILED_CREATION;
            $this->point->save();

            $client = ArborHttp::client();

            $matchedTeacher = true;
            $matchedClass   = true;

            // Resolve core refs
            $stuID = Student::where('record_id', $this->point->student_id)->value('arbor_id');

            $teacherEmail = Teacher::where('record_id', $this->point->teacher_id)->value('email');
            $teacherEmail = $teacherEmail ? mb_strtolower($teacherEmail) : null;
            $teacherID = $teacherEmail
                ? ArborStaff::whereRaw('LOWER(email) = ?', [$teacherEmail])->value('arbor_id')
                : null;

            // Category mapping (required)
            $internalReferenceId = EpraisePointCategory::where('epraise_name', $this->point->category)
                ->value('arbor_point_category_id');
            // If mapping is mandatory, fail loudly:
            $pointCategory = ArborPointCategory::findOrFail($internalReferenceId);

            // Early guard: missing student
            if (!$stuID) {
                $this->rememberError(null, 'Missing student Arbor ID');
                $this->point->forceFill([
                    'sync_status'     => Point::STATUS_FAILED_CREATION,
                    'synced_at'       => now(),
                    'matched_teacher' => false,
                    'matched_class'   => false,
                    'sync_next_retry_at' => null,
                    'sync_last_error_status' => $this->lastErrorStatus,
                    'sync_last_error_body' => $this->lastErrorBody,
                ])->save();
                return $writeCount;
            }

            // Session/event (optional)
            $className = Course::where('course_id', $this->point->class_id)->value('course_name');
            $eventID   = ArborClass::where('name', $className)
                ->where('start_date_time', '<=', Carbon::parse($this->point->date))
                ->orderBy('start_date_time', 'desc')
                ->value('arbor_id');

            // Build point award
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

            // POST create point award (plural endpoint)
            $response = $client->post('/rest-v2/point-awards', $payload);
            $writeCount++;

            if ($response->successful()) {
                $json = $response->json();
                $arborPointId = str($json['pointAward']['href'])
                    ->after('/rest-v2/point-awards/')
                    ->toInteger();
                $checksum = $this->computeChecksum();

                // Success
                $this->point->forceFill([
                    'arbor_id'        => $arborPointId,
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
                // Log failure with context
                Log::warning('CreatePoint: point-awards failed', [
                    'point_id'  => $this->point->id,
                    'status'    => $response->status(),
                    'body'      => $response->body(),
                    'payload'   => $payload,
                ]);

                // Finalize as failed
                $this->point->forceFill([
                    'synced_at'   => now(),
                    'sync_status' => Point::STATUS_FAILED_CREATION,
                    'sync_next_retry_at' => null,
                    'sync_last_error_status' => $this->lastErrorStatus,
                    'sync_last_error_body' => $this->lastErrorBody,
                ])->save();
            }
        }

        return $writeCount;
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

    private function rememberError(?int $status, string $body): void
    {
        $this->lastErrorStatus = $status;
        $this->lastErrorBody = substr($body, 0, 4000);
    }
}

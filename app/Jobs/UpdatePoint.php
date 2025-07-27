<?php

namespace App\Jobs;

use DateTime;
use Carbon\Carbon;
use App\Models\Point;
use App\Models\Course;
use App\Models\Student;
use App\Models\Teacher;
use Arbor\Model\Hydrator;
use App\Models\ArborClass;
use App\Models\ArborStaff;
use Arbor\Model\ModelBase;
use App\Models\ArborPointCategory;
use App\Models\EpraisePointCategory;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdatePoint implements ShouldQueue
{
    use Queueable;

    protected Point $point;

    /**
     * Create a new job instance.
     */
    public function __construct(string $pointID)
    {
        $this->point = Point::where('id', $pointID)->first();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {

        $matchedTeacher = true;
        $matchedClass = true;
        date_default_timezone_set('Europe/London');

        $data = Point::where('record_id', $this->point->record_id)
            ->firstOrFail()
            ->toArray();
        $stuID = Student::where('record_id', $data['student_id'])
            ->pluck('arbor_id')
            ->first();
        $teacherEmail = Teacher::where('record_id', $data['teacher_id'])
            ->pluck('email')
            ->first();
        $teacherID = ArborStaff::where('email', $teacherEmail)
            ->pluck('arbor_id')
            ->first();
        $arborID = $this->point->arbor_id;

        if (!$stuID || !$arborID) {
        } else {

            $internalReference = EpraisePointCategory::where('epraise_name', $data['category'])
                ->pluck('arbor_point_category_id')
                ->first();

            $pointCategory = ArborPointCategory::where('id', $internalReference)
                ->pluck('arbor_id')
                ->first();

            if (!$pointCategory) {
            } else {

                $pointScale = ArborPointCategory::where('id', $internalReference)
                    ->first()
                    ->ArborPointScale()
                    ->pluck('arbor_id')
                    ->first();

                $className = Course::where('course_id', $data['class_id'])
                    ->pluck('course_name')
                    ->first();

                $eventID = ArborClass::where('name', $className)
                    ->where('start_date_time', '<=', Carbon::parse($data['date'])->setTimezone('Europe/London'))
                    ->orderBy('start_date_time', 'desc')
                    ->pluck('arbor_id')
                    ->first();

                $correctDateTime = Carbon::parse(new DateTime($data['date']))->setTimezone('Europe/London')->toDateTimeString();

                $restArray = [
                    'request' => [
                        'pointAward' => [
                            'student' => [
                                'entityType' => 'Student',
                                'href' => '/rest-v2/students/' . $stuID
                            ],
                            'pointAwardScale' => [
                                'entityType' => 'PointAwardScale',
                                'href' => '/rest-v2/point-award-scales/' . $pointScale
                            ],
                            'pointAwardCategory' => [
                                'entityType' => 'PointAwardCategory',
                                'href' => '/rest-v2/point-award-categories/' . $pointCategory
                            ],
                            'event' => [
                                'entityType' => 'Session',
                                'href' => '/rest-v2/sessions/' . $eventID
                            ],
                            'awardedByStaff' => [
                                'entityType' => 'Staff',
                                'href' => '/rest-v2/staffs/' . $teacherID
                            ],
                            'points' => $data['points'],
                            'awardedDatetime' => $correctDateTime
                        ]
                    ]
                ];
                if (!$eventID) {
                    unset($restArray['request']['pointAward']['event']);
                    $matchedClass = false;
                }
                if (!$teacherID) {

                    unset($restArray['request']['pointAward']['awardedByStaff']);
                    $matchedTeacher = false;
                }

                $authtmp = env('ARBOR_APP_USERNAME') . ":" . env('ARBOR_APP_PASSWORD');
                $authstring = base64_encode($authtmp);
                $response = Http::withHeaders([
                    'Authorization' => 'Basic ' . $authstring,
                    'Content-Type' => 'application/json',
                ])->put(
                    'https://api-sandbox2.uk.arbor.sc/rest-v2/Point-Award/' . $arborID,
                    $restArray
                );

                if ($response->successful()) {
                    $data = $response->json();
                    $this->point['sync_status'] = 1;
                    $this->point['synced_at'] = Carbon::parse(now())->toDateTimeString();
                    $this->point['matched_teacher'] = $matchedTeacher;
                    $this->point['matched_class'] = $matchedClass;
                    $this->point->save();
                } else {
                    $statusCode = $response->status();
                    $errorMessage = $response->body();
                }
            }
        }
    }
}

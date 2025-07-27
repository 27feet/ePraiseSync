<?php

namespace App\Jobs;

use DateTime;
use Carbon\Carbon;
use App\Models\Point;
use App\Models\Course;
use App\Models\Demerit;
use App\Models\Student;
use App\Models\Teacher;
use Arbor\Model\Hydrator;
use App\Models\ArborClass;
use App\Models\ArborStaff;
use Arbor\Model\ModelBase;
use App\Models\ArborPointCategory;
use App\Models\EpraisePointCategory;
use Illuminate\Support\Facades\Http;
use App\Models\ArborBehaviourCategory;
use App\Models\EpraiseDemeritCategory;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdateDemerit implements ShouldQueue
{
    use Queueable;

    protected Demerit $demerit;

    /**
     * Create a new job instance.
     */
    public function __construct(string $demeritID)
    {
        $this->demerit = Demerit::where('id', $demeritID)->first();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {


        $behaviourActionID = null;
        $behaviourID = null;
        $pointID = null;
        $matchedTeacher = true;
        $matchedClass = true;
        date_default_timezone_set('Europe/London');
        $data = Demerit::where('record_id', $this->demerit->record_id)
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
        $internalReference = EpraiseDemeritCategory::where('epraise_name', $data['category'])
            ->first();
        $pointCategory = ArborPointCategory::where('id', $internalReference->arbor_point_category_id)
            ->pluck('arbor_id')
            ->first();
        $behaviourCategory = ArborBehaviourCategory::where('id', $internalReference->arbor_behaviour_category_id)
            ->first();
        $className = Course::where('course_id', $data['class_id'])
            ->pluck('course_name')
            ->first();
        $eventID = ArborClass::where('name', $className)
            ->where('start_date_time', '<=', Carbon::parse($data['date'])->setTimezone('Europe/London'))
            ->orderBy('start_date_time', 'desc')
            ->pluck('arbor_id')
            ->first();

        $authtmp = env('ARBOR_APP_USERNAME') . ":" . env('ARBOR_APP_PASSWORD');
        $authstring = base64_encode($authtmp);

        if (!$stuID) {
        } else {
            if (!$pointCategory) {
            } else {
                $pointScale = ArborPointCategory::where('id', $internalReference->arbor_point_category_id)
                    ->first()
                    ->ArborPointScale()
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

                if (!$this->demerit->arbor_point_id) {
                    $pointResponse = Http::withHeaders([
                        'Authorization' => 'Basic ' . $authstring,
                        'Content-Type' => 'application/json',
                    ])->post(
                        'https://api-sandbox2.uk.arbor.sc/rest-v2/Point-Award',
                        $restArray
                    );
                } else {
                    $pointResponse = Http::withHeaders([
                        'Authorization' => 'Basic ' . $authstring,
                        'Content-Type' => 'application/json',
                    ])->put(
                        'https://api-sandbox2.uk.arbor.sc/rest-v2/Point-Award/' . $this->demerit->arbor_point_id,
                        $restArray
                    );
                }

                if ($pointResponse->successful()) {
                    $pointData = $pointResponse->json();
                    $pointID = str($pointData['pointAward']['href'])
                        ->after('/rest-v2/point-awards/')
                        ->toInteger();
                    $this->demerit['arbor_point_id'] = $pointID;
                    // Process the successful response data
                } else {
                    $statusCode = $pointResponse->status();
                    $errorMessage = $pointResponse->body();
                    // Handle the error
                }
            }


            if ((is_null($internalReference->arbor_behaviour_category_id) || !$internalReference->arbor_behaviour_category_id)) {
            } else {
                $behaviourArray = [
                    'request' => [
                        'behaviouralIncident' => [
                            'behaviour' => [
                                'entityType' => 'Behaviour',
                                'href' => '/rest-v2/behaviour/' . $behaviourCategory->arbor_id,
                            ],
                            'loggedByStaff' => [
                                'entityType' => 'Staff',
                                'href' => '/rest-v2/staffs/' . $teacherID,
                            ],
                            'event' => [
                                'entityType' => 'Session',
                                'href' => '/rest-v2/sessions/' . $eventID
                            ],
                            'narrative' => $data['comment'],
                            'incidentDatetime' => Carbon::parse($data['date'])->setTimezone('Europe/London')->toDateTimeString(),
                            'resolvedDatetime' => Carbon::parse($data['date'])->setTimezone('Europe/London')->toDateTimeString(),
                            'severity' => $behaviourCategory->severity,
                        ]
                    ]
                ];


                if (!$eventID) {
                    unset($behaviourArray['request']['behaviouralIncident']['event']);
                    $matchedClass = false;
                }
                if (!$teacherID) {
                    unset($behaviourArray['request']['behaviouralIncident']['loggedByStaff']);
                    $matchedTeacher = false;
                }

                if (!$this->demerit->arbor_behaviour_id) {
                    $behaviourResponse = Http::withHeaders([
                        'Authorization' => 'Basic ' . $authstring,
                        'Content-Type' => 'application/json',
                    ])->post(
                        'https://api-sandbox2.uk.arbor.sc/rest-v2/Behavioural-Incident',
                        $behaviourArray
                    );
                } else {
                    $behaviourResponse = Http::withHeaders([
                        'Authorization' => 'Basic ' . $authstring,
                        'Content-Type' => 'application/json',
                    ])->put(
                        'https://api-sandbox2.uk.arbor.sc/rest-v2/Behavioural-Incident/' . $this->demerit->arbor_behaviour_id,
                        $behaviourArray
                    );
                }

                if ($behaviourResponse->successful()) {
                    $behaviourData = $behaviourResponse->json();
                    $behaviourID = str($behaviourData['behaviouralIncident']['href'])
                        ->after('/rest-v2/behavioural-incidents/')
                        ->toInteger();
                    $this->demerit['arbor_behaviour_id'] = $behaviourID;

                    $behaviourStuArray = [
                        'request' => [
                            'behaviouralIncidentStudentInvolvement' => [
                                'behaviouralIncident' => [
                                    'entityType' => 'BehaviouralIncident',
                                    'href' => '/rest-v2/behavioural-incident/' . $behaviourID
                                ],
                                'student' => [
                                    'entityType' => 'Student',
                                    'href' => '/rest-v2/students/' . $stuID
                                ],
                                'assignee' => [
                                    'entityType' => 'Staff',
                                    'href' => '/rest-v2/staffs/' . $teacherID,
                                ],
                                'resolvedDatetime' => Carbon::parse($data['date'])->setTimezone('Europe/London')->toDateTimeString(),
                                'severity' => $behaviourCategory->severity,
                            ]
                        ]
                    ];

                    if (!$teacherID) {
                        unset($behaviourStuArray['request']['behaviouralIncidentStudentInvolvement']['assignee']);
                        $matchedTeacher = false;
                    }

                    if (!$this->demerit->arbor_behaviour_student_id) {
                        $behaviourStuResponse = Http::withHeaders([
                            'Authorization' => 'Basic ' . $authstring,
                            'Content-Type' => 'application/json',
                        ])->post(
                            'https://api-sandbox2.uk.arbor.sc/rest-v2/Behavioural-Incident-Student-Involvement',
                            $behaviourStuArray
                        );
                    } else {
                        $behaviourStuResponse = Http::withHeaders([
                            'Authorization' => 'Basic ' . $authstring,
                            'Content-Type' => 'application/json',
                        ])->put(
                            'https://api-sandbox2.uk.arbor.sc/rest-v2/Behavioural-Incident-Student-Involvement/' . $this->demerit->arbor_behaviour_student_id,
                            $behaviourStuArray
                        );
                    }

                    if ($behaviourStuResponse->successful()) {
                        $behaviourStuData = $behaviourStuResponse->json();
                        $this->demerit['arbor_behaviour_student_id'] = str($behaviourStuData['behaviouralIncidentStudentInvolvement']['href'])
                            ->after('/rest-v2/behavioural-incident-student-involvements/')
                            ->toInteger();

                        $action1 = true;
                        $action2 = true;
                        if (!$this->demerit->arbor_action_id) {
                            $action1 = false;
                            $action2 = false;
                        } else {
                            if (is_null($this->demerit->arbor_action_id['behaviourAction1'])) {
                                $action1 = false;
                            }
                            if (is_null($this->demerit->arbor_action_id['behaviourAction2'])) {
                                $action2  = false;
                            }
                        }

                        if (!$pointID) {
                        } else {

                            $behaviourAction2Array = [
                                'request' => [
                                    'behaviouralIncidentAction' => [
                                        'behaviouralIncident' => [
                                            'entityType' => 'BehaviouralIncident',
                                            'href' => '/rest-v2/behavioural-incident/' . $behaviourID
                                        ],
                                        'action' => [
                                            'entityType' => 'PointAward',
                                            'href' => '/rest-v2/point-awards/' . $pointID,
                                        ],
                                        'student' => [
                                            'entityType' => 'Student',
                                            'href' => '/rest-v2/students/' . $stuID
                                        ],
                                        'isAutomatic' => 'true',
                                    ]
                                ]
                            ];

                            if ($action2) {
                                $behaviourAction2Response = Http::withHeaders([
                                    'Authorization' => 'Basic ' . $authstring,
                                    'Content-Type' => 'application/json',
                                ])->put(
                                    'https://api-sandbox2.uk.arbor.sc/rest-v2/Behavioural-Incident-Action/' . $this->demerit->arbor_action_id['behaviourAction2'],
                                    $behaviourAction2Array
                                );
                            } else {
                                $behaviourAction2Response = Http::withHeaders([
                                    'Authorization' => 'Basic ' . $authstring,
                                    'Content-Type' => 'application/json',
                                ])->post(
                                    'https://api-sandbox2.uk.arbor.sc/rest-v2/Behavioural-Incident-Action',
                                    $behaviourAction2Array
                                );
                            }

                            if ($behaviourAction2Response->successful()) {
                                $behaviourStuData = $behaviourStuResponse->json();
                                $behaviourAction2Data = $behaviourAction2Response->json();
                                $behaviourAction2ID = str($behaviourAction2Data['behaviouralIncidentAction']['href'])
                                    ->after('/rest-v2/behavioural-incident-actions/')
                                    ->toInteger();
                            }
                        }
                        if (!$teacherID) {
                        } else {

                            $behaviourAction1Array = [
                                'request' => [
                                    'behaviouralIncidentAction' => [
                                        'behaviouralIncident' => [
                                            'entityType' => 'BehaviouralIncident',
                                            'href' => '/rest-v2/behavioural-incident/' . $behaviourID
                                        ],
                                        'action' => [
                                            'entityType' => 'Staff',
                                            'href' => '/rest-v2/staffs/' . $teacherID,
                                        ],
                                        'student' => [
                                            'entityType' => 'Student',
                                            'href' => '/rest-v2/students/' . $stuID
                                        ],
                                        'isAutomatic' => 'true',
                                    ]
                                ]
                            ];

                            if ($action1) {
                                $behaviourAction1Response = Http::withHeaders([
                                    'Authorization' => 'Basic ' . $authstring,
                                    'Content-Type' => 'application/json',
                                ])->put(
                                    'https://api-sandbox2.uk.arbor.sc/rest-v2/Behavioural-Incident-Action/' . $this->demerit->arbor_action_id['behaviourAction1'],
                                    $behaviourAction1Array
                                );
                            } else {
                                $behaviourAction1Response = Http::withHeaders([
                                    'Authorization' => 'Basic ' . $authstring,
                                    'Content-Type' => 'application/json',
                                ])->post(
                                    'https://api-sandbox2.uk.arbor.sc/rest-v2/Behavioural-Incident-Action',
                                    $behaviourAction1Array
                                );
                            }

                            if ($behaviourAction1Response->successful()) {
                                $behaviourStuData = $behaviourStuResponse->json();
                                $behaviourAction1Data = $behaviourAction1Response->json();
                                $behaviourAction1ID = str($behaviourAction1Data['behaviouralIncidentAction']['href'])
                                    ->after('/rest-v2/behavioural-incident-actions/')
                                    ->toInteger();
                            }
                        }
                        $behaviourActionID = [
                            'behaviourAction1' => $behaviourAction1ID ?? null,
                            'behaviourAction2' => $behaviourAction2ID ?? null,
                        ];
                        $this->demerit['arbor_action_id'] = $behaviourActionID;
                    } else {
                        $this->demerit['arbor_behaviour_id'] = null;
                        $response = Http::withHeaders([
                            'Authorization' => 'Basic ' . $authstring,
                            'Content-Type' => 'application/json',
                        ])->delete(
                            'https://api-sandbox2.uk.arbor.sc/rest-v2/Behavioural-Incident/' . $behaviourID,
                        );
                        $behaviourID = null;
                    }
                }
            }

            if (!$behaviourID && !$pointCategory) {
            } else {

                $this->demerit['synced_at'] = Carbon::parse(now())->toDateTimeString();
                $this->demerit['sync_status'] = 1;
                $this->demerit['matched_teacher'] = $matchedTeacher;
                $this->demerit['matched_class'] = $matchedClass;
                $this->demerit->save();
            }
        }
    }
}

<?php

namespace App\Jobs;

use DateTime;
use Carbon\Carbon;
use App\Models\Course;
use App\Models\Demerit;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\ArborClass;
use App\Models\ArborStaff;
use App\Models\ArborPointCategory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\ArborBehaviourCategory;
use App\Models\EpraiseDemeritCategory;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CreateDemerit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    protected Demerit $demerit;

    public function __construct(string $demeritID)
    {
        $this->demerit = Demerit::where('id', $demeritID)->firstOrFail();
    }

    public function handle(): int
    {
        $writeCount = 0;

        // Idempotency
        if ($this->demerit->arbor_point_id || $this->demerit->arbor_behaviour_id) {
            return 0; // already synced
        }

        if ($this->demerit->sync_status == Demerit::STATUS_READY_TO_CREATE) {
            // pre-mark as FAILED (your choice kept)
            $this->demerit['sync_status'] = Demerit::STATUS_FAILED_CREATION;
            $this->demerit->save();

            $client = Http::baseUrl(config('services.arbor.base_url'))
                ->withBasicAuth(config('services.arbor.username'), config('services.arbor.password'))
                ->acceptJson()
                ->asJson();

            $behaviourAction1ID = null;
            $behaviourAction2ID = null;
            $behaviourID = null;
            $pointID = null;
            $matchedTeacher = true;
            $matchedClass = true;

            // Resolve refs
            $stuID = Student::where('record_id', $this->demerit->student_id)->value('arbor_id');

            $teacherEmail = Teacher::where('record_id', $this->demerit->teacher_id)->value('email');
            $teacherEmail = $teacherEmail ? mb_strtolower($teacherEmail) : null;
            $teacherID = $teacherEmail
                ? ArborStaff::whereRaw('LOWER(email) = ?', [$teacherEmail])->value('arbor_id')
                : null;

            $internalReference = EpraiseDemeritCategory::where('epraise_name', $this->demerit->category)->firstOrFail();
            $pointCategory     = ArborPointCategory::findOrFail($internalReference->arbor_point_category_id);

            $className = Course::where('course_id', $this->demerit->class_id)->value('course_name');
            $eventID   = ArborClass::where('name', $className)
                ->where('start_date_time', '<=', Carbon::parse($this->demerit->date))
                ->orderBy('start_date_time', 'desc')
                ->value('arbor_id');

            // Early guard: missing student
            if (!$stuID) {
                Log::warning('CreateDemerit: missing student Arbor ID; aborting', [
                    'demerit_id' => $this->demerit->id,
                    'student_record_id' => $this->demerit->student_id,
                ]);

                $this->demerit->forceFill([
                    'sync_status'     => Demerit::STATUS_FAILED_CREATION,
                    'synced_at'       => now(),
                    'matched_teacher' => false,
                    'matched_class'   => false,
                ])->save();
                return $writeCount;
            }

            // --- Point award ---
            $pointScale = $pointCategory->ArborPointScale()->value('arbor_id');
            $correctDateTime = Carbon::parse(new DateTime($this->demerit->date))->toDateTimeString();

            $restArray = [
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
                        'points'          => abs((int) $this->demerit->points),
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

            $pointResponse = $client->post('/rest-v2/point-awards', $restArray);
            $writeCount++;

            if ($pointResponse->successful()) {
                $pointData = $pointResponse->json();
                $pointID = str($pointData['pointAward']['href'])
                    ->after('/rest-v2/point-awards/')
                    ->toInteger();
                $this->demerit['arbor_point_id'] = $pointID;
            } else {
                Log::warning('CreateDemerit: point-awards failed', [
                    'demerit_id' => $this->demerit->id,
                    'status'     => $pointResponse->status(),
                    'body'       => $pointResponse->body(),
                    'payload'    => $restArray,
                ]);
            }

            // --- Behaviour (only if mapped) ---
            if ($internalReference->arbor_behaviour_category_id) {
                $behaviourCategory = ArborBehaviourCategory::findOrFail($internalReference->arbor_behaviour_category_id);

                $behaviourArray = [
                    'request' => [
                        'behaviouralIncident' => [
                            'behaviour' => [
                                'entityType' => 'Behaviour',
                                'href'       => '/rest-v2/behaviours/' . $behaviourCategory->arbor_id,
                            ],
                            'loggedByStaff' => [
                                'entityType' => 'Staff',
                                'href'       => '/rest-v2/staffs/' . $teacherID,
                            ],
                            'event' => [
                                'entityType' => 'Session',
                                'href'       => '/rest-v2/sessions/' . $eventID
                            ],
                            'narrative'        => $this->demerit->comment,
                            'incidentDatetime' => Carbon::parse($this->demerit->date)->toDateTimeString(),
                            'resolvedDatetime' => Carbon::parse($this->demerit->date)->toDateTimeString(),
                            'severity'         => $behaviourCategory->severity,
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

                $behaviourResponse = $client->post('/rest-v2/behavioural-incidents', $behaviourArray);
                $writeCount++;

                if ($behaviourResponse->successful()) {
                    $behaviourData = $behaviourResponse->json();
                    $behaviourID = str($behaviourData['behaviouralIncident']['href'])
                        ->after('/rest-v2/behavioural-incidents/')
                        ->toInteger();
                    $this->demerit['arbor_behaviour_id'] = $behaviourID;

                    // Student involvement
                    $behaviourStuArray = [
                        'request' => [
                            'behaviouralIncidentStudentInvolvement' => [
                                'behaviouralIncident' => [
                                    'entityType' => 'BehaviouralIncident',
                                    'href'       => '/rest-v2/behavioural-incidents/' . $behaviourID
                                ],
                                'student' => [
                                    'entityType' => 'Student',
                                    'href'       => '/rest-v2/students/' . $stuID
                                ],
                                'assignee' => [
                                    'entityType' => 'Staff',
                                    'href'       => '/rest-v2/staffs/' . $teacherID,
                                ],
                                'resolvedDatetime' => Carbon::parse($this->demerit->date)->toDateTimeString(),
                                'severity'         => $behaviourCategory->severity,
                            ]
                        ]
                    ];
                    if (!$teacherID) {
                        unset($behaviourStuArray['request']['behaviouralIncidentStudentInvolvement']['assignee']);
                        $matchedTeacher = false;
                    }

                    $behaviourStuResponse = $client->post('/rest-v2/behavioural-incident-student-involvements', $behaviourStuArray);
                    $writeCount++;

                    if ($behaviourStuResponse->successful()) {
                        $behaviourStuData = $behaviourStuResponse->json();
                        $this->demerit['arbor_behaviour_student_id'] = str($behaviourStuData['behaviouralIncidentStudentInvolvement']['href'])
                            ->after('/rest-v2/behavioural-incident-student-involvements/')
                            ->toInteger();

                        // Action linking to PointAward
                        if ($pointID) {
                            $behaviourAction2Array = [
                                'request' => [
                                    'behaviouralIncidentAction' => [
                                        'behaviouralIncident' => [
                                            'entityType' => 'BehaviouralIncident',
                                            'href'       => '/rest-v2/behavioural-incidents/' . $behaviourID
                                        ],
                                        'action' => [
                                            'entityType' => 'PointAward',
                                            'href'       => '/rest-v2/point-awards/' . $pointID,
                                        ],
                                        'student' => [
                                            'entityType' => 'Student',
                                            'href'       => '/rest-v2/students/' . $stuID
                                        ],
                                        'isAutomatic' => 'true',
                                    ]
                                ]
                            ];

                            $behaviourAction2Response = $client->post('/rest-v2/behavioural-incident-actions', $behaviourAction2Array);
                            $writeCount++;

                            if ($behaviourAction2Response->successful()) {
                                $behaviourAction2Data = $behaviourAction2Response->json();
                                $behaviourAction2ID = str($behaviourAction2Data['behaviouralIncidentAction']['href'])
                                    ->after('/rest-v2/behavioural-incident-actions/')
                                    ->toInteger();
                            } else {
                                Log::warning('CreateDemerit: incident-action (PointAward) failed', [
                                    'demerit_id' => $this->demerit->id,
                                    'status'     => $behaviourAction2Response->status(),
                                    'body'       => $behaviourAction2Response->body(),
                                    'payload'    => $behaviourAction2Array,
                                ]);
                            }
                        }

                        // Action linking to Staff
                        if ($teacherID) {
                            $behaviourAction1Array = [
                                'request' => [
                                    'behaviouralIncidentAction' => [
                                        'behaviouralIncident' => [
                                            'entityType' => 'BehaviouralIncident',
                                            'href'       => '/rest-v2/behavioural-incidents/' . $behaviourID
                                        ],
                                        'action' => [
                                            'entityType' => 'Staff',
                                            'href'       => '/rest-v2/staffs/' . $teacherID,
                                        ],
                                        'student' => [
                                            'entityType' => 'Student',
                                            'href'       => '/rest-v2/students/' . $stuID
                                        ],
                                        'isAutomatic' => 'true',
                                    ]
                                ]
                            ];

                            $behaviourAction1Response = $client->post('/rest-v2/behavioural-incident-actions', $behaviourAction1Array);
                            $writeCount++;

                            if ($behaviourAction1Response->successful()) {
                                $behaviourAction1Data = $behaviourAction1Response->json();
                                $behaviourAction1ID = str($behaviourAction1Data['behaviouralIncidentAction']['href'])
                                    ->after('/rest-v2/behavioural-incident-actions/')
                                    ->toInteger();
                            } else {
                                Log::warning('CreateDemerit: incident-action (Staff) failed', [
                                    'demerit_id' => $this->demerit->id,
                                    'status'     => $behaviourAction1Response->status(),
                                    'body'       => $behaviourAction1Response->body(),
                                    'payload'    => $behaviourAction1Array,
                                ]);
                            }
                        }

                        $this->demerit['arbor_action_id'] = [
                            'behaviourAction1' => $behaviourAction1ID,
                            'behaviourAction2' => $behaviourAction2ID,
                        ];
                    } else {
                        $this->demerit['arbor_behaviour_id'] = null;

                        Log::warning('CreateDemerit: student involvement failed; deleting incident', [
                            'demerit_id' => $this->demerit->id,
                            'status'     => $behaviourStuResponse->status(),
                            'body'       => $behaviourStuResponse->body(),
                            'payload'    => $behaviourStuArray,
                        ]);

                        $response = $client->delete('/rest-v2/behavioural-incidents/' . $behaviourID);
                        if (!$response->successful()) {
                            Log::warning('CreateDemerit: delete behavioural-incident failed', [
                                'demerit_id' => $this->demerit->id,
                                'status'     => $response->status(),
                                'body'       => $response->body(),
                            ]);
                        }
                        $behaviourID = null;
                    }
                } else {
                    Log::warning('CreateDemerit: behavioural-incidents failed', [
                        'demerit_id' => $this->demerit->id,
                        'status'     => $behaviourResponse->status(),
                        'body'       => $behaviourResponse->body(),
                        'payload'    => $behaviourArray,
                    ]);
                }
            }

            // --- Finalisation ---
            if ($behaviourID || $pointID) {
                $this->demerit->forceFill([
                    'synced_at'       => now(),
                    'sync_status'     => Demerit::STATUS_PROCESSED_SUCCESSFULLY,
                    'matched_teacher' => $matchedTeacher,
                    'matched_class'   => $matchedClass,
                ])->save();
            } else {
                $this->demerit->forceFill([
                    'synced_at'   => now(),
                    'sync_status' => Demerit::STATUS_FAILED_CREATION,
                ])->save();

                Log::warning('CreateDemerit: finished FAILED (no point or behaviour created)', [
                    'demerit_id' => $this->demerit->id,
                ]);
            }
        }

        return $writeCount;
    }
}

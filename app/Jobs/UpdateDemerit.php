<?php

namespace App\Jobs;

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
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdateDemerit implements ShouldQueue
{
    use Queueable;

    protected Demerit $demerit;

    public function __construct(string $demeritID)
    {
        $this->demerit = Demerit::where('id', $demeritID)->firstOrFail();
    }

    public function handle(): int
    {
        $writeCount = 0;

        // --- Status gate ---
        if ($this->demerit->sync_status !== Demerit::STATUS_READY_TO_UPDATE) {
            return 0;
        }

        // --- Pre-mark as FAILED_UPDATE before attempting (attempt signal) ---
        $this->demerit->forceFill([
            'sync_status' => Demerit::STATUS_FAILED_UPDATE,
        ])->save();

        $client = Http::baseUrl(config('services.arbor.base_url'))
            ->withBasicAuth(config('services.arbor.username'), config('services.arbor.password'))
            ->acceptJson()
            ->asJson();

        $matchedTeacher = true;
        $matchedClass   = true;

        // Resolve core references
        $stuID = Student::where('record_id', $this->demerit->student_id)->value('arbor_id');

        $teacherEmail = Teacher::where('record_id', $this->demerit->teacher_id)->value('email');
        $teacherEmail = $teacherEmail ? mb_strtolower($teacherEmail) : null;
        $teacherID = $teacherEmail
            ? ArborStaff::whereRaw('LOWER(email) = ?', [$teacherEmail])->value('arbor_id')
            : null;

        // Mapping (required)
        $internalReference = EpraiseDemeritCategory::where('epraise_name', $this->demerit->category)->firstOrFail();
        $pointCategory     = ArborPointCategory::findOrFail($internalReference->arbor_point_category_id);

        // Early guard: missing student
        if (!$stuID) {
            Log::warning('UpdateDemerit: missing student Arbor ID; aborting', [
                'demerit_id'         => $this->demerit->id,
                'student_record_id'  => $this->demerit->student_id,
            ]);
            $this->demerit->forceFill([
                'synced_at'       => now(),
                'sync_status'     => Demerit::STATUS_FAILED_UPDATE,
                'matched_teacher' => false,
                'matched_class'   => false,
            ])->save();
            return $writeCount;
        }

        // Optional event (class/session)
        $className = Course::where('course_id', $this->demerit->class_id)->value('course_name');
        $eventID   = ArborClass::where('name', $className)
            ->where('start_date_time', '<=', Carbon::parse($this->demerit->date))
            ->orderBy('start_date_time', 'desc')
            ->value('arbor_id');

        // ----------------- POINT AWARD (create or update) -----------------
        $pointScale      = $pointCategory->ArborPointScale()->value('arbor_id');
        $awardedDatetime = Carbon::parse($this->demerit->date)->toDateTimeString();

        $pointPayload = [
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
                    'points'          => (int) $this->demerit->points,
                    'awardedDatetime' => $awardedDatetime,
                ]
            ]
        ];

        if (!$eventID) {
            unset($pointPayload['request']['pointAward']['event']);
            $matchedClass = false;
        }
        if (!$teacherID) {
            unset($pointPayload['request']['pointAward']['awardedByStaff']);
            $matchedTeacher = false;
        }

        if ($this->demerit->arbor_point_id) {
            $pointResponse = $client->put('/rest-v2/point-awards/' . $this->demerit->arbor_point_id, $pointPayload);
            $writeCount++;
        } else {
            $pointResponse = $client->post('/rest-v2/point-awards', $pointPayload);
            $writeCount++;
        }

        $pointID = null;
        if ($pointResponse->successful()) {
            $pointData = $pointResponse->json();
            $pointID = str($pointData['pointAward']['href'])->after('/rest-v2/point-awards/')->toInteger();
            $this->demerit['arbor_point_id'] = $pointID;
        } else {
            Log::warning('UpdateDemerit: point-awards upsert failed', [
                'demerit_id' => $this->demerit->id,
                'status'     => $pointResponse->status(),
                'body'       => $pointResponse->body(),
                'payload'    => $pointPayload,
            ]);
        }

        // ----------------- BEHAVIOUR / INCIDENT (create or update) -----------------
        $behaviourID = null;
        if ($internalReference->arbor_behaviour_category_id) {
            $behaviourCategory = ArborBehaviourCategory::findOrFail($internalReference->arbor_behaviour_category_id);

            $behaviourPayload = [
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
                unset($behaviourPayload['request']['behaviouralIncident']['event']);
                $matchedClass = false;
            }
            if (!$teacherID) {
                unset($behaviourPayload['request']['behaviouralIncident']['loggedByStaff']);
                $matchedTeacher = false;
            }

            if ($this->demerit->arbor_behaviour_id) {
                $behaviourResponse = $client->put('/rest-v2/behavioural-incidents/' . $this->demerit->arbor_behaviour_id, $behaviourPayload);
                $writeCount++;
            } else {
                $behaviourResponse = $client->post('/rest-v2/behavioural-incidents', $behaviourPayload);
                $writeCount++;
            }

            if ($behaviourResponse->successful()) {
                $behaviourData = $behaviourResponse->json();
                $behaviourID = str($behaviourData['behaviouralIncident']['href'])
                    ->after('/rest-v2/behavioural-incidents/')
                    ->toInteger();
                $this->demerit['arbor_behaviour_id'] = $behaviourID;

                // -------- Student involvement (create or update) --------
                $involvementPayload = [
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
                    unset($involvementPayload['request']['behaviouralIncidentStudentInvolvement']['assignee']);
                    $matchedTeacher = false;
                }

                if ($this->demerit->arbor_behaviour_student_id) {
                    $behaviourStuResponse = $client->put(
                        '/rest-v2/behavioural-incident-student-involvements/' . $this->demerit->arbor_behaviour_student_id,
                        $involvementPayload
                    );
                    $writeCount++;
                } else {
                    $behaviourStuResponse = $client->post(
                        '/rest-v2/behavioural-incident-student-involvements',
                        $involvementPayload
                    );
                    $writeCount++;
                }

                if ($behaviourStuResponse->successful()) {
                    $behaviourStuData = $behaviourStuResponse->json();
                    $this->demerit['arbor_behaviour_student_id'] = str($behaviourStuData['behaviouralIncidentStudentInvolvement']['href'])
                        ->after('/rest-v2/behavioural-incident-student-involvements/')
                        ->toInteger();

                    // -------- Actions (create or update) --------
                    $existingActions = $this->demerit->arbor_action_id;
                    if (is_string($existingActions)) {
                        $decoded = json_decode($existingActions, true);
                        $existingActions = is_array($decoded) ? $decoded : [];
                    } elseif (!is_array($existingActions)) {
                        $existingActions = [];
                    }

                    $action1Id = $existingActions['behaviourAction1'] ?? null; // Staff
                    $action2Id = $existingActions['behaviourAction2'] ?? null; // PointAward

                    // Link to PointAward if present
                    if ($pointID) {
                        $action2Payload = [
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

                        if ($action2Id) {
                            $behaviourAction2Response = $client->put('/rest-v2/behavioural-incident-actions/' . $action2Id, $action2Payload);
                            $writeCount++;
                        } else {
                            $behaviourAction2Response = $client->post('/rest-v2/behavioural-incident-actions', $action2Payload);
                            $writeCount++;
                        }

                        if ($behaviourAction2Response->successful()) {
                            $action2Data = $behaviourAction2Response->json();
                            $action2Id = str($action2Data['behaviouralIncidentAction']['href'])
                                ->after('/rest-v2/behavioural-incident-actions/')
                                ->toInteger();
                        } else {
                            Log::warning('UpdateDemerit: behavioural-incident-action (PointAward) upsert failed', [
                                'demerit_id' => $this->demerit->id,
                                'status'     => $behaviourAction2Response->status(),
                                'body'       => $behaviourAction2Response->body(),
                                'payload'    => $action2Payload,
                            ]);
                        }
                    }

                    // Link to Staff if present
                    if ($teacherID) {
                        $action1Payload = [
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

                        if ($action1Id) {
                            $behaviourAction1Response = $client->put('/rest-v2/behavioural-incident-actions/' . $action1Id, $action1Payload);
                            $writeCount++;
                        } else {
                            $behaviourAction1Response = $client->post('/rest-v2/behavioural-incident-actions', $action1Payload);
                            $writeCount++;
                        }

                        if ($behaviourAction1Response->successful()) {
                            $action1Data = $behaviourAction1Response->json();
                            $action1Id = str($action1Data['behaviouralIncidentAction']['href'])
                                ->after('/rest-v2/behavioural-incident-actions/')
                                ->toInteger();
                        } else {
                            Log::warning('UpdateDemerit: behavioural-incident-action (Staff) upsert failed', [
                                'demerit_id' => $this->demerit->id,
                                'status'     => $behaviourAction1Response->status(),
                                'body'       => $behaviourAction1Response->body(),
                                'payload'    => $action1Payload,
                            ]);
                        }
                    }

                    $this->demerit['arbor_action_id'] = [
                        'behaviourAction1' => $action1Id,
                        'behaviourAction2' => $action2Id,
                    ];
                } else {
                    // involvement failed -> roll back newly created incident
                    $this->demerit['arbor_behaviour_id'] = null;

                    Log::warning('UpdateDemerit: behavioural-incident-student-involvement upsert failed', [
                        'demerit_id' => $this->demerit->id,
                        'status'     => $behaviourStuResponse->status(),
                        'body'       => $behaviourStuResponse->body(),
                    ]);

                    $cleanup = $client->delete('/rest-v2/behavioural-incidents/' . $behaviourID);
                    $writeCount++;
                    if (!$cleanup->successful() && !in_array($cleanup->status(), [404, 410], true)) {
                        Log::warning('UpdateDemerit: cleanup delete behavioural-incident failed', [
                            'demerit_id'  => $this->demerit->id,
                            'behaviour_id' => $behaviourID,
                            'status'      => $cleanup->status(),
                            'body'        => $cleanup->body(),
                        ]);
                    }
                    $behaviourID = null;
                }
            } else {
                Log::warning('UpdateDemerit: behavioural-incidents upsert failed', [
                    'demerit_id' => $this->demerit->id,
                    'status'     => $behaviourResponse->status(),
                    'body'       => $behaviourResponse->body(),
                    'payload'    => $behaviourPayload,
                ]);
            }
        }

        // ----------------- Finalisation -----------------
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
                'sync_status' => Demerit::STATUS_FAILED_UPDATE,
            ])->save();
        }

        return $writeCount;
    }
}

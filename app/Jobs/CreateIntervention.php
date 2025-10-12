<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\ArborStaff;
use App\Models\Intervention;
use App\Models\ArborPointCategory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\ArborBehaviourCategory;
use App\Models\EpraiseInterventionCategory;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CreateIntervention implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    protected Intervention $intervention;

    public function __construct(string $interventionID)
    {
        $this->intervention = Intervention::where('id', $interventionID)->firstOrFail();
    }

    public function handle(): int
    {
        $writeCount = 0;

        // Idempotency: if already synced, bail out
        if ($this->intervention->arbor_point_id || $this->intervention->arbor_behaviour_id) {
            return 0;
        }

        // Proceed only when "ready" (mirrors your Demerit pattern)
        if ($this->intervention->sync_status == Intervention::STATUS_READY_TO_CREATE) {
            // Pre-mark as failed as your signal "attempting" (kept)
            $this->intervention['sync_status'] = Intervention::STATUS_FAILED_CREATION;
            $this->intervention->save();

            $client = Http::baseUrl(config('services.arbor.base_url'))
                ->withBasicAuth(config('services.arbor.username'), config('services.arbor.password'))
                ->acceptJson()
                ->asJson();

            $behaviourAction1ID = null;
            $behaviourAction2ID = null;
            $behaviourID = null;
            $pointID = null;

            // Flags: no session/event in this flow, so class is never matched
            $matchedTeacher = true;
            $matchedClass = false;

            // Resolve core references
            $stuID = Student::where('record_id', $this->intervention->student_id)->value('arbor_id');

            $teacherEmail = Teacher::where('record_id', $this->intervention->teacher_id)->value('email');
            $teacherEmail = $teacherEmail ? mb_strtolower($teacherEmail) : null;
            $teacherID = $teacherEmail
                ? ArborStaff::whereRaw('LOWER(email) = ?', [$teacherEmail])->value('arbor_id')
                : null;

            // Intervention category mapping (required)
            $internalReference = EpraiseInterventionCategory::where('epraise_name', $this->intervention->intervention_type)
                ->firstOrFail();

            // Early guard: missing student
            if (!$stuID) {
                $this->intervention->forceFill([
                    'sync_status'     => Intervention::STATUS_FAILED_CREATION,
                    'synced_at'       => now(),
                    'matched_teacher' => false,
                    'matched_class'   => false,
                ])->save();
                return $writeCount;
            }

            // =========================
            // Point Award (if mapped)
            // =========================
            if ($internalReference->arbor_point_category_id) {
                $pointCategory = ArborPointCategory::findOrFail($internalReference->arbor_point_category_id);
                $pointScale = $pointCategory->ArborPointScale()->value('arbor_id');

                $awardedAt = Carbon::parse($this->intervention->date_added)->toDateTimeString();

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
                            // No event in interventions
                            'awardedByStaff' => $teacherID ? [
                                'entityType' => 'Staff',
                                'href'       => '/rest-v2/staffs/' . $teacherID
                            ] : null,
                            'points'          => $internalReference->default_points,
                            'awardedDatetime' => $awardedAt,
                        ]
                    ]
                ];

                // if no teacher, drop awardedByStaff and mark flag
                if (!$teacherID) {
                    unset($pointPayload['request']['pointAward']['awardedByStaff']);
                    $matchedTeacher = false;
                }

                $pointResponse = $client->post('/rest-v2/point-awards', $pointPayload);
                $writeCount++;

                if ($pointResponse->successful()) {
                    $pointData = $pointResponse->json();
                    $pointID = str($pointData['pointAward']['href'])
                        ->after('/rest-v2/point-awards/')
                        ->toInteger();
                    $this->intervention['arbor_point_id'] = $pointID;
                } else {
                    Log::warning('Arbor point-awards failed (interventions)', [
                        'intervention_id' => $this->intervention->id,
                        'status'          => $pointResponse->status(),
                        'body'            => $pointResponse->body(),
                        'payload'         => $pointPayload,
                    ]);
                }
            }

            // ==================================
            // Behavioural Incident (if mapped)
            // ==================================
            if ($internalReference->arbor_behaviour_category_id) {
                $behaviourCategory = ArborBehaviourCategory::findOrFail($internalReference->arbor_behaviour_category_id);

                $incidentAt = Carbon::parse($this->intervention->date_added)->toDateTimeString();

                $behaviourPayload = [
                    'request' => [
                        'behaviouralIncident' => [
                            'behaviour' => [
                                'entityType' => 'Behaviour',
                                // If Arbor expects singular (/behaviour/{id}), change here
                                'href'       => '/rest-v2/behaviours/' . $behaviourCategory->arbor_id,
                            ],
                            'loggedByStaff' => $teacherID ? [
                                'entityType' => 'Staff',
                                'href'       => '/rest-v2/staffs/' . $teacherID,
                            ] : null,
                            'narrative'        => $this->intervention->comment,
                            'incidentDatetime' => $incidentAt,
                            'resolvedDatetime' => $incidentAt,
                            'severity'         => $behaviourCategory->severity,
                        ]
                    ]
                ];

                if (!$teacherID) {
                    $matchedTeacher = false;
                    unset($behaviourPayload['request']['behaviouralIncident']['loggedByStaff']);
                }

                $behaviourResponse = $client->post('/rest-v2/behavioural-incidents', $behaviourPayload);
                $writeCount++;

                if ($behaviourResponse->successful()) {
                    $behaviourData = $behaviourResponse->json();
                    $behaviourID = str($behaviourData['behaviouralIncident']['href'])
                        ->after('/rest-v2/behavioural-incidents/')
                        ->toInteger();
                    $this->intervention['arbor_behaviour_id'] = $behaviourID;

                    // -----------------------------------
                    // Student involvement (required link)
                    // -----------------------------------
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
                                'assignee' => $teacherID ? [
                                    'entityType' => 'Staff',
                                    'href'       => '/rest-v2/staffs/' . $teacherID,
                                ] : null,
                                'resolvedDatetime' => $incidentAt,
                                'severity'         => $behaviourCategory->severity,
                            ]
                        ]
                    ];
                    if (!$teacherID) {
                        unset($involvementPayload['request']['behaviouralIncidentStudentInvolvement']['assignee']);
                    }

                    $behaviourStuResponse = $client->post('/rest-v2/behavioural-incident-student-involvements', $involvementPayload);
                    $writeCount++;

                    if ($behaviourStuResponse->successful()) {
                        $behaviourStuData = $behaviourStuResponse->json();
                        $this->intervention['arbor_behaviour_student_id'] = str($behaviourStuData['behaviouralIncidentStudentInvolvement']['href'])
                            ->after('/rest-v2/behavioural-incident-student-involvements/')
                            ->toInteger();

                        // -----------------------------
                        // Actions (save their IDs)
                        // -----------------------------

                        // Link incident -> PointAward (if we created one)
                        if ($pointID) {
                            $actionPointPayload = [
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

                            $behaviourAction2Response = $client->post('/rest-v2/behavioural-incident-actions', $actionPointPayload);
                            $writeCount++;

                            if ($behaviourAction2Response->successful()) {
                                $behaviourAction2Data = $behaviourAction2Response->json();
                                $behaviourAction2ID = str($behaviourAction2Data['behaviouralIncidentAction']['href'])
                                    ->after('/rest-v2/behavioural-incident-actions/')
                                    ->toInteger();
                            } else {
                                Log::warning('Arbor behavioural-incident-action (PointAward) failed (interventions)', [
                                    'intervention_id' => $this->intervention->id,
                                    'status'          => $behaviourAction2Response->status(),
                                    'body'            => $behaviourAction2Response->body(),
                                    'payload'         => $actionPointPayload,
                                ]);
                            }
                        }

                        // Link incident -> Staff (if we have a teacher)
                        if ($teacherID) {
                            $actionStaffPayload = [
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

                            $behaviourAction1Response = $client->post('/rest-v2/behavioural-incident-actions', $actionStaffPayload);
                            $writeCount++;

                            if ($behaviourAction1Response->successful()) {
                                $behaviourAction1Data = $behaviourAction1Response->json();
                                $behaviourAction1ID = str($behaviourAction1Data['behaviouralIncidentAction']['href'])
                                    ->after('/rest-v2/behavioural-incident-actions/')
                                    ->toInteger();
                            } else {
                                Log::warning('Arbor behavioural-incident-action (Staff) failed (interventions)', [
                                    'intervention_id' => $this->intervention->id,
                                    'status'          => $behaviourAction1Response->status(),
                                    'body'            => $behaviourAction1Response->body(),
                                    'payload'         => $actionStaffPayload,
                                ]);
                            }
                        }

                        // Persist action IDs (can be null if not created)
                        $this->intervention['arbor_action_id'] = [
                            'behaviourAction1' => $behaviourAction1ID,
                            'behaviourAction2' => $behaviourAction2ID,
                        ];
                    } else {
                        // Failed to link student -> clean up incident
                        $this->intervention['arbor_behaviour_id'] = null;

                        Log::warning('Arbor behavioural-incident-student-involvements failed (interventions)', [
                            'intervention_id' => $this->intervention->id,
                            'status'          => $behaviourStuResponse->status(),
                            'body'            => $behaviourStuResponse->body(),
                            'payload'         => $involvementPayload,
                        ]);

                        $response = $client->delete('/rest-v2/behavioural-incidents/' . $behaviourID);
                        if (!$response->successful()) {
                            Log::warning('Arbor delete behavioural-incident failed (interventions)', [
                                'intervention_id' => $this->intervention->id,
                                'status'          => $response->status(),
                                'body'            => $response->body(),
                            ]);
                        }
                        $behaviourID = null;
                    }
                } else {
                    Log::warning('Arbor behavioural-incidents failed (interventions)', [
                        'intervention_id' => $this->intervention->id,
                        'status'          => $behaviourResponse->status(),
                        'body'            => $behaviourResponse->body(),
                        'payload'         => $behaviourPayload,
                    ]);
                }
            }

            // ========================
            // Finalisation (always)
            // ========================
            if ($behaviourID || $pointID) {
                $this->intervention->forceFill([
                    'synced_at'       => now(),
                    'sync_status'     => Intervention::STATUS_PROCESSED_SUCCESSFULLY,
                    'matched_teacher' => (bool) $teacherID,
                    'matched_class'   => false,
                ])->save();
            } else {
                $this->intervention->forceFill([
                    'synced_at'       => now(),
                    'sync_status'     => Intervention::STATUS_FAILED_CREATION,
                    'matched_teacher' => (bool) $teacherID,
                    'matched_class'   => false,
                ])->save();
            }
        }

        return $writeCount;
    }
}

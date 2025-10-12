<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Course;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\ArborClass;
use App\Models\ArborStaff;
use App\Models\Intervention;
use App\Models\ArborPointCategory;
use App\Models\EpraiseInterventionCategory;
use App\Models\ArborBehaviourCategory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdateIntervention implements ShouldQueue
{
    use Queueable;

    protected Intervention $intervention;

    public function __construct(string $interventionID)
    {
        $this->intervention = Intervention::where('id', $interventionID)->firstOrFail();
    }

    public function handle(): int
    {
        $writeCount = 0;

        if ($this->intervention->sync_status !== Intervention::STATUS_READY_TO_UPDATE) {
            return 0;
        }

        // Pre-mark as FAILED_UPDATE (attempt signal)
        $this->intervention->forceFill([
            'sync_status' => Intervention::STATUS_FAILED_UPDATE,
        ])->save();

        $client = Http::baseUrl(config('services.arbor.base_url'))
            ->withBasicAuth(config('services.arbor.username'), config('services.arbor.password'))
            ->acceptJson()
            ->asJson();

        $matchedTeacher = true;
        $matchedClass   = true;

        $stuID = Student::where('record_id', $this->intervention->student_id)->value('arbor_id');

        $teacherEmail = Teacher::where('record_id', $this->intervention->teacher_id)->value('email');
        $teacherEmail = $teacherEmail ? mb_strtolower($teacherEmail) : null;
        $teacherID = $teacherEmail
            ? ArborStaff::whereRaw('LOWER(email) = ?', [$teacherEmail])->value('arbor_id')
            : null;

        if (!$stuID) {
            Log::warning('UpdateIntervention: missing student Arbor ID; aborting', [
                'intervention_id'   => $this->intervention->id,
                'student_record_id' => $this->intervention->student_id,
            ]);
            $this->intervention->forceFill([
                'synced_at'       => now(),
                'sync_status'     => Intervention::STATUS_FAILED_UPDATE,
                'matched_teacher' => false,
                'matched_class'   => false,
            ])->save();
            return $writeCount;
        }

        // Mapping (no mapping is acceptable: treat as success/no-op)
        $mappingKey = $this->intervention->intervention_type ?: $this->intervention->category;
        $internal   = $mappingKey
            ? EpraiseInterventionCategory::where('epraise_name', $mappingKey)->first()
            : null;

        $pointCategory = ($internal && $internal->arbor_point_category_id)
            ? ArborPointCategory::find($internal->arbor_point_category_id)
            : null;

        $hasPointMapping     = (bool) $pointCategory;
        $hasBehaviourMapping = (bool) ($internal && $internal->arbor_behaviour_category_id);

        if (!$hasPointMapping && !$hasBehaviourMapping) {
            // nothing to update remotely; count as successful no-op
            $this->intervention->forceFill([
                'synced_at'       => now(),
                'sync_status'     => Intervention::STATUS_PROCESSED_SUCCESSFULLY,
                'matched_teacher' => $matchedTeacher,
                'matched_class'   => $matchedClass,
            ])->save();
            return $writeCount;
        }

        $activityDate = Carbon::parse($this->intervention->date_added)->toDateTimeString();

        $className = $this->intervention->class_id
            ? Course::where('course_id', $this->intervention->class_id)->value('course_name')
            : null;

        $eventID   = $className
            ? ArborClass::where('name', $className)
            ->where('start_date_time', '<=', Carbon::parse($this->intervention->date_added))
            ->orderBy('start_date_time', 'desc')
            ->value('arbor_id')
            : null;

        // ---------- POINT AWARD (if mapped) ----------
        $pointID = null;
        if ($hasPointMapping) {
            $pointScale = $pointCategory->ArborPointScale()->value('arbor_id');
            $points     = (int) ($this->intervention->points ?? $internal->default_points ?? 0);

            if ($points > 0) {
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
                            'points'          => $points,
                            'awardedDatetime' => $activityDate,
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

                $pointRes = $this->intervention->arbor_point_id
                    ? $client->put('/rest-v2/point-awards/' . $this->intervention->arbor_point_id, $pointPayload)
                    : $client->post('/rest-v2/point-awards', $pointPayload);
                $writeCount++;

                if ($pointRes->successful()) {
                    $pointJson = $pointRes->json();
                    $pointID = str($pointJson['pointAward']['href'])->after('/rest-v2/point-awards/')->toInteger();
                    $this->intervention['arbor_point_id'] = $pointID;
                } else {
                    Log::warning('UpdateIntervention: point-awards upsert failed', [
                        'intervention_id' => $this->intervention->id,
                        'status'          => $pointRes->status(),
                        'body'            => $pointRes->body(),
                    ]);
                }
            }
        }

        // ---------- BEHAVIOUR (if mapped) ----------
        $behaviourID = null;
        if ($hasBehaviourMapping) {
            $behaviourCategory = ArborBehaviourCategory::find($internal->arbor_behaviour_category_id);
            if (!$behaviourCategory) {
                Log::warning('UpdateIntervention: mapped behaviour category not found locally', [
                    'intervention_id' => $this->intervention->id,
                    'behaviour_cat'   => $internal->arbor_behaviour_category_id,
                ]);
            } else {
                $incidentPayload = [
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
                            'narrative'        => $this->intervention->comment,
                            'incidentDatetime' => $activityDate,
                            'resolvedDatetime' => $activityDate,
                            'severity'         => $behaviourCategory->severity,
                        ]
                    ]
                ];

                if (!$eventID) {
                    unset($incidentPayload['request']['behaviouralIncident']['event']);
                    $matchedClass = false;
                }
                if (!$teacherID) {
                    unset($incidentPayload['request']['behaviouralIncident']['loggedByStaff']);
                    $matchedTeacher = false;
                }

                $behRes = $this->intervention->arbor_behaviour_id
                    ? $client->put('/rest-v2/behavioural-incidents/' . $this->intervention->arbor_behaviour_id, $incidentPayload)
                    : $client->post('/rest-v2/behavioural-incidents', $incidentPayload);
                $writeCount++;

                if ($behRes->successful()) {
                    $behJson = $behRes->json();
                    $behaviourID = str($behJson['behaviouralIncident']['href'])
                        ->after('/rest-v2/behavioural-incidents/')
                        ->toInteger();
                    $this->intervention['arbor_behaviour_id'] = $behaviourID;

                    // Student involvement
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
                                'resolvedDatetime' => $activityDate,
                                'severity'         => $behaviourCategory->severity,
                            ]
                        ]
                    ];
                    if (!$teacherID) {
                        unset($involvementPayload['request']['behaviouralIncidentStudentInvolvement']['assignee']);
                        $matchedTeacher = false;
                    }

                    $involRes = $this->intervention->arbor_behaviour_student_id
                        ? $client->put(
                            '/rest-v2/behavioural-incident-student-involvements/' . $this->intervention->arbor_behaviour_student_id,
                            $involvementPayload
                        )
                        : $client->post(
                            '/rest-v2/behavioural-incident-student-involvements',
                            $involvementPayload
                        );
                    $writeCount++;

                    if ($involRes->successful()) {
                        $involJson = $involRes->json();
                        $this->intervention['arbor_behaviour_student_id'] = str($involJson['behaviouralIncidentStudentInvolvement']['href'])
                            ->after('/rest-v2/behavioural-incident-student-involvements/')
                            ->toInteger();

                        // Actions
                        $existingActions = $this->intervention->arbor_action_id;
                        if (is_string($existingActions)) {
                            $decoded = json_decode($existingActions, true);
                            $existingActions = is_array($decoded) ? $decoded : [];
                        } elseif (!is_array($existingActions)) {
                            $existingActions = [];
                        }
                        $action1Id = $existingActions['behaviourAction1'] ?? null; // Staff
                        $action2Id = $existingActions['behaviourAction2'] ?? null; // PointAward

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

                            $act2Res = $action2Id
                                ? $client->put('/rest-v2/behavioural-incident-actions/' . $action2Id, $action2Payload)
                                : $client->post('/rest-v2/behavioural-incident-actions', $action2Payload);
                            $writeCount++;

                            if ($act2Res->successful()) {
                                $act2Json = $act2Res->json();
                                $action2Id = str($act2Json['behaviouralIncidentAction']['href'])
                                    ->after('/rest-v2/behavioural-incident-actions/')
                                    ->toInteger();
                            } else {
                                Log::warning('UpdateIntervention: behavioural-incident-action (PointAward) upsert failed', [
                                    'intervention_id' => $this->intervention->id,
                                    'status'          => $act2Res->status(),
                                    'body'            => $act2Res->body(),
                                ]);
                            }
                        }

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

                            $act1Res = $action1Id
                                ? $client->put('/rest-v2/behavioural-incident-actions/' . $action1Id, $action1Payload)
                                : $client->post('/rest-v2/behavioural-incident-actions', $action1Payload);
                            $writeCount++;

                            if ($act1Res->successful()) {
                                $act1Json = $act1Res->json();
                                $action1Id = str($act1Json['behaviouralIncidentAction']['href'])
                                    ->after('/rest-v2/behavioural-incident-actions/')
                                    ->toInteger();
                            } else {
                                Log::warning('UpdateIntervention: behavioural-incident-action (Staff) upsert failed', [
                                    'intervention_id' => $this->intervention->id,
                                    'status'          => $act1Res->status(),
                                    'body'            => $act1Res->body(),
                                ]);
                            }
                        }

                        $this->intervention['arbor_action_id'] = [
                            'behaviourAction1' => $action1Id,
                            'behaviourAction2' => $action2Id,
                        ];
                    } else {
                        $this->intervention['arbor_behaviour_id'] = null;

                        Log::warning('UpdateIntervention: behavioural-incident-student-involvement upsert failed', [
                            'intervention_id' => $this->intervention->id,
                            'status'          => $involRes->status(),
                            'body'            => $involRes->body(),
                        ]);

                        $cleanup = $client->delete('/rest-v2/behavioural-incidents/' . $behaviourID);
                        $writeCount++;
                        if (!$cleanup->successful() && !in_array($cleanup->status(), [404, 410], true)) {
                            Log::warning('UpdateIntervention: cleanup delete behavioural-incident failed', [
                                'intervention_id' => $this->intervention->id,
                                'behaviour_id'    => $behaviourID,
                                'status'          => $cleanup->status(),
                                'body'            => $cleanup->body(),
                            ]);
                        }
                        $behaviourID = null;
                    }
                } else {
                    Log::warning('UpdateIntervention: behavioural-incidents upsert failed', [
                        'intervention_id' => $this->intervention->id,
                        'status'          => $behRes->status(),
                        'body'            => $behRes->body(),
                    ]);
                }
            }
        }

        // Finalize
        if ($behaviourID || $pointID || (!$hasPointMapping && !$hasBehaviourMapping)) {
            $this->intervention->forceFill([
                'synced_at'       => now(),
                'sync_status'     => Intervention::STATUS_PROCESSED_SUCCESSFULLY,
                'matched_teacher' => $matchedTeacher,
                'matched_class'   => $matchedClass,
            ])->save();
        } else {
            $this->intervention->forceFill([
                'synced_at'   => now(),
                'sync_status' => Intervention::STATUS_FAILED_UPDATE,
            ])->save();
        }

        return $writeCount;
    }
}

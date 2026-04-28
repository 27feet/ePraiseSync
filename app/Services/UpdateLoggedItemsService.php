<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Point;
use App\Models\Demerit;
use App\Models\CheckPoint;
use App\Models\CheckDemerit;
use App\Models\Intervention;
use App\Models\CheckIntervention;
use App\Models\EpraiseInterventionCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use App\Support\EpraiseUrl;
use App\Support\Checksum;

class UpdateLoggedItemsService
{
    public function fetchInterventions(Request $request, string $fromDate, bool $includeAmendments, ?string $toDate = null, bool $forceProcessEmpty = false): void
    {
        $fromDate = Carbon::parse($fromDate)->toDateString();
        $toDate = $toDate ? Carbon::parse($toDate)->toDateString() : null;
        $runId = (string) Str::uuid();
        $startedAt = now();

        $query = EpraiseUrl::endpoint('interventions', $fromDate);
        $response = Http::acceptJson()->retry(2, 200)->get($query);

        if (!$response->successful()) {
            Log::warning('fetchInterventions: request failed', ['status' => $response->status(), 'body' => $response->body()]);
            return;
        }

        $interventions = $response->json();
        if (!is_array($interventions)) {
            Log::warning('fetchInterventions: unexpected payload', ['payload' => $interventions]);
            return;
        }

        $currentCount = count($interventions);
        $this->alertOnDrop('interventions', $currentCount);

        if ($currentCount === 0 && !$forceProcessEmpty) {
            Log::info('fetchInterventions: empty payload; skipping amendment scan', ['from' => $fromDate, 'to' => $toDate]);
            return;
        }

        $seenIds = [];

        foreach ($interventions as $intervention) {
            if (!isset($intervention['id'])) {
                continue;
            }

            $id = (int) $intervention['id'];
            $seenIds[] = $id;

            CheckIntervention::updateOrCreate(
                ['record_id' => $id],
                [
                    'date'          => Carbon::parse($intervention['date_added']),
                    'last_seen_at'  => $startedAt,
                    'last_seen_run' => $runId,
                ]
            );

            $business = [
                'student_id'        => $intervention['student'],
                'teacher_id'        => $intervention['teacher'],
                'demerit_id'        => $intervention['demerit_id'],
                'subject'           => $intervention['subject'],
                'intervention_type' => $intervention['intervention_type'],
                'date_added'        => Carbon::parse($intervention['date_added'])->timezone('Europe/London'),
                'comment'           => $intervention['comment'] ?? null,
            ];
            $meta = [
                'last_seen_at'  => $startedAt,
                'last_seen_run' => $runId,
                'missing_runs'  => 0,
            ];

            $this->upsertWithChecksum(Intervention::class, $id, $business, $meta);
        }

        if (!$includeAmendments || (empty($seenIds) && !$forceProcessEmpty)) {
            return;
        }

        $records = Intervention::where('date_added', '>=', $fromDate)
            ->when($toDate, fn ($q) => $q->where('date_added', '<=', $toDate))
            ->get();

        foreach ($records as $r) {
            if (in_array($r->sync_status, [
                Intervention::STATUS_READY_TO_CREATE,
                Intervention::STATUS_READY_TO_UPDATE,
                Intervention::STATUS_READY_TO_DELETE,
            ], true)) {
                continue;
            }

            $seenNow = in_array((int) $r->record_id, $seenIds, true);

            if ($seenNow) {
                $statusChanged = false;

                if ($r->sync_status === Intervention::STATUS_PROCESSED_SUCCESSFULLY && $r->synced_at) {
                    $businessKeys = [
                        'student_id',
                        'teacher_id',
                        'demerit_id',
                        'subject',
                        'intervention_type',
                        'date_added',
                        'comment',
                    ];
                    $currentChecksum = $this->currentBusinessChecksum($r, $businessKeys);
                    $remotePresent = $this->interventionRemotePresent($r);
                    $mappingPresent = $remotePresent ? false : $this->interventionMappingPresent($r);

                    if ($r->checksum !== $currentChecksum) {
                        $r->checksum = $currentChecksum;
                    }
                    $needsSync = false;
                    if (!empty($r->synced_checksum)) {
                        if ($r->synced_checksum !== $currentChecksum) {
                            $legacyChecksum = $this->legacyLondonChecksum($r, $businessKeys);
                            if ($r->synced_checksum === $legacyChecksum) {
                                $r->synced_checksum = $currentChecksum;
                            } else {
                                $needsSync = true;
                            }
                        }
                    } elseif ($r->updated_at > $r->synced_at) {
                        $needsSync = true;
                    } else {
                        $r->synced_checksum = $currentChecksum;
                    }

                    if ($needsSync) {
                        if ($remotePresent || $mappingPresent) {
                            $r->sync_status = $remotePresent
                                ? Intervention::STATUS_REQUIRES_UPDATE
                                : Intervention::STATUS_REQUIRES_CREATE;
                            $statusChanged = true;
                        }
                    } elseif (!$remotePresent && $mappingPresent) {
                        $r->sync_status = Intervention::STATUS_REQUIRES_CREATE;
                        $statusChanged = true;
                    }
                }

                if ($r->missing_runs !== 0 || $r->last_seen_run !== $runId) {
                    $r->missing_runs = 0;
                    $r->last_seen_run = $runId;
                    $r->last_seen_at = $startedAt;
                }

                if ($r->isDirty()) {
                    if ($statusChanged) {
                        $r->save();
                    } else {
                        $this->saveWithoutTimestamps($r);
                    }
                }
                continue;
            }

            $r->missing_runs = (int) $r->missing_runs + 1;

            if ($r->missing_runs < 2) {
                $this->saveWithoutTimestamps($r);
                continue;
            }

            $remotePresent  = $this->interventionRemotePresent($r);
            $alreadyDeleted = $this->interventionFullyDeleted($r);

            if ($alreadyDeleted) {
                $this->saveWithoutTimestamps($r);
                continue;
            }

            $r->sync_status = $remotePresent
                ? Intervention::STATUS_REQUIRES_DELETION
                : Intervention::STATUS_DELETED;
            $r->save();
        }
    }

    public function fetchDemerits(Request $request, string $fromDate, bool $includeAmendments, ?string $toDate = null, bool $forceProcessEmpty = false): void
    {
        $fromDate = Carbon::parse($fromDate)->toDateString();
        $toDate = $toDate ? Carbon::parse($toDate)->toDateString() : null;
        $runId = (string) Str::uuid();
        $startedAt = now();

        $query = EpraiseUrl::endpoint('demerits', $fromDate);
        $response = Http::acceptJson()->retry(2, 200)->get($query);

        if (!$response->successful()) {
            Log::warning('fetchDemerits: request failed', ['status' => $response->status(), 'body' => $response->body()]);
            return;
        }

        $demerits = $response->json();
        if (!is_array($demerits)) {
            Log::warning('fetchDemerits: unexpected payload', ['payload' => $demerits]);
            return;
        }

        $currentCount = count($demerits);
        $this->alertOnDrop('demerits', $currentCount);

        if ($currentCount === 0 && !$forceProcessEmpty) {
            Log::info('fetchDemerits: empty payload; skipping amendment scan', ['from' => $fromDate, 'to' => $toDate]);
            return;
        }

        $seenIds = [];

        foreach ($demerits as $demerit) {
            if (!isset($demerit['id'])) {
                continue;
            }

            $id = (int) $demerit['id'];
            $seenIds[] = $id;

            CheckDemerit::updateOrCreate(
                ['record_id' => $id],
                [
                    'date'          => Carbon::parse($demerit['date']),
                    'last_seen_at'  => $startedAt,
                    'last_seen_run' => $runId,
                ]
            );

            $business = [
                'student_id' => $demerit['student'],
                'teacher_id' => $demerit['teacher'],
                'class_id'   => $demerit['class'],
                'subject'    => $demerit['subject'],
                'category'   => $demerit['category'],
                'reason'     => $demerit['reason'],
                'date'       => Carbon::parse($demerit['date'])->timezone('Europe/London'),
                'points'     => $demerit['value'],
                'comment'    => $demerit['comment'] ?? null,
            ];
            $meta = [
                'last_seen_at'  => $startedAt,
                'last_seen_run' => $runId,
                'missing_runs'  => 0,
            ];

            $this->upsertWithChecksum(Demerit::class, $id, $business, $meta);
        }

        if (!$includeAmendments || (empty($seenIds) && !$forceProcessEmpty)) {
            return;
        }

        $records = Demerit::where('date', '>=', $fromDate)
            ->when($toDate, fn ($q) => $q->where('date', '<=', $toDate))
            ->get();
        foreach ($records as $r) {
            if (in_array($r->sync_status, [
                Demerit::STATUS_READY_TO_CREATE,
                Demerit::STATUS_READY_TO_UPDATE,
                Demerit::STATUS_READY_TO_DELETE,
            ], true)) {
                continue;
            }

            $seenNow = in_array((int) $r->record_id, $seenIds, true);

            if ($seenNow) {
                $statusChanged = false;

                if ($r->sync_status === Demerit::STATUS_PROCESSED_SUCCESSFULLY && $r->synced_at) {
                    $businessKeys = [
                        'student_id',
                        'teacher_id',
                        'class_id',
                        'subject',
                        'category',
                        'reason',
                        'date',
                        'points',
                        'comment',
                    ];
                    $currentChecksum = $this->currentBusinessChecksum($r, $businessKeys);
                    $remotePresent = $this->demeritRemotePresent($r);

                    if ($r->checksum !== $currentChecksum) {
                        $r->checksum = $currentChecksum;
                    }
                    $needsSync = false;
                    if (!empty($r->synced_checksum)) {
                        if ($r->synced_checksum !== $currentChecksum) {
                            $legacyChecksum = $this->legacyLondonChecksum($r, $businessKeys);
                            if ($r->synced_checksum === $legacyChecksum) {
                                $r->synced_checksum = $currentChecksum;
                            } else {
                                $needsSync = true;
                            }
                        }
                    } elseif ($r->updated_at > $r->synced_at) {
                        $needsSync = true;
                    } else {
                        $r->synced_checksum = $currentChecksum;
                    }

                    if ($needsSync) {
                        $r->sync_status = $remotePresent
                            ? Demerit::STATUS_REQUIRES_UPDATE
                            : Demerit::STATUS_REQUIRES_CREATE;
                        $statusChanged = true;
                    }
                }

                if ($r->missing_runs !== 0 || $r->last_seen_run !== $runId) {
                    $r->missing_runs = 0;
                    $r->last_seen_run = $runId;
                    $r->last_seen_at = $startedAt;
                }

                if ($r->isDirty()) {
                    if ($statusChanged) {
                        $r->save();
                    } else {
                        $this->saveWithoutTimestamps($r);
                    }
                }
                continue;
            }

            $r->missing_runs = (int) $r->missing_runs + 1;

            if ($r->missing_runs < 2) {
                $this->saveWithoutTimestamps($r);
                continue;
            }

            $remotePresent  = $this->demeritRemotePresent($r);
            $alreadyDeleted = $this->demeritFullyDeleted($r);

            if ($alreadyDeleted) {
                $this->saveWithoutTimestamps($r);
                continue;
            }

            $r->sync_status = $remotePresent
                ? Demerit::STATUS_REQUIRES_DELETION
                : Demerit::STATUS_DELETED;
            $r->save();
        }
    }

    public function fetchPoints(Request $request, string $fromDate, bool $includeAmendments, ?string $toDate = null, bool $forceProcessEmpty = false): void
    {
        $fromDate = Carbon::parse($fromDate)->toDateString();
        $toDate = $toDate ? Carbon::parse($toDate)->toDateString() : null;
        $runId = (string) Str::uuid();
        $startedAt = now();

        $query  = EpraiseUrl::endpoint('points', $fromDate);
        $response = Http::acceptJson()->retry(2, 200)->get($query);

        if (!$response->successful()) {
            Log::warning('fetchPoints: request failed', ['status' => $response->status(), 'body' => $response->body()]);
            return;
        }

        $points = $response->json();
        if (!is_array($points)) {
            Log::warning('fetchPoints: unexpected payload', ['payload' => $points]);
            return;
        }

        $currentCount = count($points);
        $this->alertOnDrop('points', $currentCount);

        if ($currentCount === 0 && !$forceProcessEmpty) {
            Log::info('fetchPoints: empty payload; skipping amendment scan', ['from' => $fromDate, 'to' => $toDate]);
            return;
        }

        $seenIds = [];

        foreach ($points as $point) {
            if (!isset($point['id'])) {
                continue;
            }

            $id = (int) $point['id'];
            $seenIds[] = $id;

            CheckPoint::updateOrCreate(
                ['record_id' => $id],
                [
                    'date'          => Carbon::parse($point['date']),
                    'last_seen_at'  => $startedAt,
                    'last_seen_run' => $runId,
                ]
            );

            $business = [
                'student_id' => $point['student'],
                'teacher_id' => $point['teacher'],
                'class_id'   => $point['class'],
                'subject'    => $point['subject'],
                'category'   => $point['category'],
                'date'       => Carbon::parse($point['date'])->timezone('Europe/London'),
                'points'     => $point['value'],
            ];
            $meta = [
                'last_seen_at'  => $startedAt,
                'last_seen_run' => $runId,
                'missing_runs'  => 0,
            ];

            $this->upsertWithChecksum(Point::class, $id, $business, $meta);
        }

        if (!$includeAmendments || (empty($seenIds) && !$forceProcessEmpty)) {
            return;
        }

        $records = Point::where('date', '>=', $fromDate)
            ->when($toDate, fn ($q) => $q->where('date', '<=', $toDate))
            ->get();
        foreach ($records as $r) {
            if (in_array($r->sync_status, [
                Point::STATUS_READY_TO_CREATE,
                Point::STATUS_READY_TO_UPDATE,
                Point::STATUS_READY_TO_DELETE,
            ], true)) {
                continue;
            }

            $seenNow = in_array((int) $r->record_id, $seenIds, true);

            if ($seenNow) {
                $statusChanged = false;

                if ($r->sync_status === Point::STATUS_PROCESSED_SUCCESSFULLY && $r->synced_at) {
                    $businessKeys = [
                        'student_id',
                        'teacher_id',
                        'class_id',
                        'subject',
                        'category',
                        'date',
                        'points',
                    ];
                    $currentChecksum = $this->currentBusinessChecksum($r, $businessKeys);
                    $remotePresent = $this->pointRemotePresent($r);

                    if ($r->checksum !== $currentChecksum) {
                        $r->checksum = $currentChecksum;
                    }
                    $needsSync = false;
                    if (!empty($r->synced_checksum)) {
                        if ($r->synced_checksum !== $currentChecksum) {
                            $legacyChecksum = $this->legacyLondonChecksum($r, $businessKeys);
                            if ($r->synced_checksum === $legacyChecksum) {
                                $r->synced_checksum = $currentChecksum;
                            } else {
                                $needsSync = true;
                            }
                        }
                    } elseif ($r->updated_at > $r->synced_at) {
                        $needsSync = true;
                    } else {
                        $r->synced_checksum = $currentChecksum;
                    }

                    if ($needsSync) {
                        $r->sync_status = $remotePresent
                            ? Point::STATUS_REQUIRES_UPDATE
                            : Point::STATUS_REQUIRES_CREATE;
                        $statusChanged = true;
                    }
                }

                if ($r->missing_runs !== 0 || $r->last_seen_run !== $runId) {
                    $r->missing_runs = 0;
                    $r->last_seen_run = $runId;
                    $r->last_seen_at = $startedAt;
                }

                if ($r->isDirty()) {
                    if ($statusChanged) {
                        $r->save();
                    } else {
                        $this->saveWithoutTimestamps($r);
                    }
                }
                continue;
            }

            $r->missing_runs = (int) $r->missing_runs + 1;

            if ($r->missing_runs < 2) {
                $this->saveWithoutTimestamps($r);
                continue;
            }

            $remotePresent  = $this->pointRemotePresent($r);
            $alreadyDeleted = $this->pointFullyDeleted($r);

            if ($alreadyDeleted) {
                $this->saveWithoutTimestamps($r);
                continue;
            }

            $r->sync_status = $remotePresent
                ? Point::STATUS_REQUIRES_DELETION
                : Point::STATUS_DELETED;
            $r->save();
        }
    }

    /**
     * Basic alerting for unexpected drops between runs.
     */
    protected function alertOnDrop(string $key, int $currentCount): void
    {
        $cacheKey = "fetch_{$key}_last_count";
        $previous = Cache::get($cacheKey);

        if (is_numeric($previous)) {
            $threshold = max(5, (int) ($previous * 0.5));
            if ($currentCount < $threshold) {
                Log::warning("{$key} fetch: significant drop detected", [
                    'previous' => (int) $previous,
                    'current'  => $currentCount,
                ]);
            }
        }

        Cache::put($cacheKey, $currentCount, now()->addHours(12));
    }

    /* ========= Helpers: “remote present” and “fully deleted” checks ========= */

    protected function interventionRemotePresent(Intervention $r): bool
    {
        // Any of the created Arbor IDs implies the intervention exists remotely
        if (!empty($r->arbor_point_id)) return true;
        if (!empty($r->arbor_behaviour_id)) return true;
        if (!empty($r->arbor_behaviour_student_id)) return true;

        $a = $r->arbor_action_id;
        if (is_string($a)) {
            $a = json_decode($a, true);
        }
        if (is_array($a)) {
            $vals = array_filter(array_values($a));
            if (!empty($vals)) return true;
        }
        return false;
    }

    protected function interventionFullyDeleted(Intervention $r): bool
    {
        // Your delete jobs set STATUS_DELETED when everything is gone
        return $r->sync_status === Intervention::STATUS_DELETED;
    }

    protected function demeritRemotePresent(Demerit $r): bool
    {
        if (!empty($r->arbor_point_id)) return true;
        if (!empty($r->arbor_behaviour_id)) return true;
        if (!empty($r->arbor_behaviour_student_id)) return true;

        $a = $r->arbor_action_id;
        if (is_string($a)) {
            $a = json_decode($a, true);
        }
        if (is_array($a)) {
            $vals = array_filter(array_values($a));
            if (!empty($vals)) return true;
        }
        return false;
    }

    protected function demeritFullyDeleted(Demerit $r): bool
    {
        return $r->sync_status === Demerit::STATUS_DELETED;
    }

    protected function pointRemotePresent(Point $r): bool
    {
        return !empty($r->arbor_id);
    }

    protected function pointFullyDeleted(Point $r): bool
    {
        return $r->sync_status === Point::STATUS_DELETED;
    }

    private function interventionMappingPresent(Intervention $r): bool
    {
        $key = $r->intervention_type ?: $r->category;
        if (!$key) {
            return false;
        }

        static $cache = [];
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $internal = EpraiseInterventionCategory::where('epraise_name', $key)->first();
        $cache[$key] = (bool) ($internal && ($internal->arbor_point_category_id || $internal->arbor_behaviour_category_id));

        return $cache[$key];
    }

    private function currentBusinessChecksum(Model $model, array $keys): string
    {
        return $this->computeChecksum($this->businessSnapshot($model, $keys));
    }

    private function legacyLondonChecksum(Model $model, array $keys): string
    {
        return Checksum::fromFieldsWithTimezone($this->businessSnapshot($model, $keys), 'Europe/London');
    }

    private function saveWithoutTimestamps(Model $model): void
    {
        $orig = $model->timestamps;
        $model->timestamps = false;
        $model->save();
        $model->timestamps = $orig;
    }

    /**
     * Upsert a record without letting housekeeping fields bump updated_at when nothing else changed.
     *
     * @param class-string<Model> $modelClass
     */
    private function upsertWithChecksum(string $modelClass, int $recordId, array $businessFields, array $metaFields): void
    {
        /** @var Model $model */
        $model = $modelClass::where('record_id', $recordId)->first();

        $checksum = $this->computeChecksum($businessFields);

        if (!$model) {
            $modelClass::create(array_merge(
                ['record_id' => $recordId],
                $businessFields,
                $metaFields,
                ['checksum' => $checksum]
            ));
            return;
        }

        $currentChecksum = $model->checksum ?: $this->computeChecksum($this->businessSnapshot($model, array_keys($businessFields)));
        $businessChanged = ($checksum !== $currentChecksum);

        if ($businessChanged) {
            $model->fill($businessFields);
            $model->checksum = $checksum;
            $model->missing_runs = 0;
            $model->last_seen_at = $metaFields['last_seen_at'] ?? null;
            $model->last_seen_run = $metaFields['last_seen_run'] ?? null;
            $model->save();
        } else {
            // Only update housekeeping/checksum without touching updated_at
            $orig = $model->timestamps;
            $model->timestamps = false;
            $model->forceFill([
                'missing_runs'  => 0,
                'last_seen_at'  => $metaFields['last_seen_at'] ?? null,
                'last_seen_run' => $metaFields['last_seen_run'] ?? null,
                'checksum'      => $model->checksum ?: $checksum,
            ]);
            $model->save();
            $model->timestamps = $orig;
        }
    }

    private function computeChecksum(array $fields): string
    {
        return Checksum::fromFields($fields);
    }

    private function businessSnapshot(Model $model, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $model->$k;
        }
        return $out;
    }
}

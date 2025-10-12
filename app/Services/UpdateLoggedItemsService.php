<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Point;
use App\Models\Demerit;
use App\Models\CheckPoint;
use App\Models\CheckDemerit;
use App\Models\Intervention;
use App\Models\CheckIntervention;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Support\EpraiseUrl;

class UpdateLoggedItemsService
{
    public function fetchInterventions(Request $request, string $fromDate, bool $includeAmendments): void
    {
        $query = EpraiseUrl::endpoint('interventions', $fromDate);
        $interventions = Http::acceptJson()->retry(2, 200)->get($query)->json();

        CheckIntervention::truncate();

        foreach ($interventions as $intervention) {
            CheckIntervention::updateOrCreate(
                ['record_id' => $intervention['id']],
                ['date' => Carbon::parse($intervention['date_added'])]
            );

            Intervention::updateOrCreate(
                ['record_id' => $intervention['id']],
                [
                    'student_id'        => $intervention['student'],
                    'teacher_id'        => $intervention['teacher'],
                    'demerit_id'        => $intervention['demerit_id'],
                    'subject'           => $intervention['subject'],
                    'intervention_type' => $intervention['intervention_type'],
                    'date_added'        => Carbon::parse($intervention['date_added'])->timezone('Europe/London'),
                ]
            );
        }

        if (!$includeAmendments) {
            return;
        }

        $records = Intervention::where('date_added', '>=', $fromDate)->get();
        foreach ($records as $r) {
            // Skip in-flight
            if (in_array($r->sync_status, [
                Intervention::STATUS_READY_TO_CREATE,
                Intervention::STATUS_READY_TO_UPDATE,
                Intervention::STATUS_READY_TO_DELETE,
            ], true)) {
                continue;
            }

            $stillLive      = (bool) $r->stillLive;
            $remotePresent  = $this->interventionRemotePresent($r);
            $alreadyDeleted = $this->interventionFullyDeleted($r);

            if (!$stillLive) {
                if ($alreadyDeleted) {
                    continue; // already fully deleted
                }
                // Deleted in source: delete if remote exists, else mark locally deleted
                $r->sync_status = $remotePresent
                    ? Intervention::STATUS_REQUIRES_DELETION
                    : Intervention::STATUS_DELETED;
                $r->save();
                continue;
            }

            // Only from a clean successful state AND if data changed since last sync
            if (
                $r->sync_status === Intervention::STATUS_PROCESSED_SUCCESSFULLY
                && $r->synced_at
                && $r->updated_at > $r->synced_at
            ) {

                $r->sync_status = $remotePresent
                    ? Intervention::STATUS_REQUIRES_UPDATE
                    : Intervention::STATUS_REQUIRES_CREATE;
                $r->save();
            }
        }
    }

    public function fetchDemerits(Request $request, string $fromDate, bool $includeAmendments): void
    {
        $query = EpraiseUrl::endpoint('demerits', $fromDate);
        $demerits = Http::acceptJson()->retry(2, 200)->get($query)->json();

        CheckDemerit::truncate();

        foreach ($demerits as $demerit) {
            CheckDemerit::updateOrCreate(
                ['record_id' => $demerit['id']],
                ['date' => Carbon::parse($demerit['date'])]
            );

            Demerit::updateOrCreate(
                ['record_id' => $demerit['id']],
                [
                    'student_id' => $demerit['student'],
                    'teacher_id' => $demerit['teacher'],
                    'class_id'   => $demerit['class'],
                    'subject'    => $demerit['subject'],
                    'category'   => $demerit['category'],
                    'reason'     => $demerit['reason'],
                    'date'       => Carbon::parse($demerit['date'])->timezone('Europe/London'),
                    'points'     => $demerit['value'],
                ]
            );
        }

        if (!$includeAmendments) {
            return;
        }

        $records = Demerit::where('date', '>=', $fromDate)->get();
        foreach ($records as $r) {
            // Skip in-flight
            if (in_array($r->sync_status, [
                Demerit::STATUS_READY_TO_CREATE,
                Demerit::STATUS_READY_TO_UPDATE,
                Demerit::STATUS_READY_TO_DELETE,
            ], true)) {
                continue;
            }

            $stillLive      = (bool) $r->stillLive;
            $remotePresent  = $this->demeritRemotePresent($r);
            $alreadyDeleted = $this->demeritFullyDeleted($r);

            if (!$stillLive) {
                if ($alreadyDeleted) {
                    continue;
                }
                $r->sync_status = $remotePresent
                    ? Demerit::STATUS_REQUIRES_DELETION
                    : Demerit::STATUS_DELETED;
                $r->save();
                continue;
            }

            if (
                $r->sync_status === Demerit::STATUS_PROCESSED_SUCCESSFULLY
                && $r->synced_at
                && $r->updated_at > $r->synced_at
            ) {

                $r->sync_status = $remotePresent
                    ? Demerit::STATUS_REQUIRES_UPDATE
                    : Demerit::STATUS_REQUIRES_CREATE;
                $r->save();
            }
        }
    }

    public function fetchPoints(Request $request, string $fromDate, bool $includeAmendments): void
    {
        $query  = EpraiseUrl::endpoint('points', $fromDate);
        $points = Http::acceptJson()->retry(2, 200)->get($query)->json();

        CheckPoint::truncate();

        foreach ($points as $point) {
            CheckPoint::updateOrCreate(
                ['record_id' => $point['id']],
                ['date' => Carbon::parse($point['date'])]
            );

            Point::updateOrCreate(
                ['record_id' => $point['id']],
                [
                    'student_id' => $point['student'],
                    'teacher_id' => $point['teacher'],
                    'class_id'   => $point['class'],
                    'subject'    => $point['subject'],
                    'category'   => $point['category'],
                    'date'       => Carbon::parse($point['date'])->timezone('Europe/London'),
                    'points'     => $point['value'],
                ]
            );
        }

        if (!$includeAmendments) {
            return;
        }

        $records = Point::where('date', '>=', $fromDate)->get();
        foreach ($records as $r) {
            // Skip in-flight
            if (in_array($r->sync_status, [
                Point::STATUS_READY_TO_CREATE,
                Point::STATUS_READY_TO_UPDATE,
                Point::STATUS_READY_TO_DELETE,
            ], true)) {
                continue;
            }

            $stillLive      = (bool) $r->stillLive;
            $remotePresent  = $this->pointRemotePresent($r);
            $alreadyDeleted = $this->pointFullyDeleted($r);

            if (!$stillLive) {
                if ($alreadyDeleted) {
                    continue;
                }
                $r->sync_status = $remotePresent
                    ? Point::STATUS_REQUIRES_DELETION
                    : Point::STATUS_DELETED;
                $r->save();
                continue;
            }

            if (
                $r->sync_status === Point::STATUS_PROCESSED_SUCCESSFULLY
                && $r->synced_at
                && $r->updated_at > $r->synced_at
            ) {

                $r->sync_status = $remotePresent
                    ? Point::STATUS_REQUIRES_UPDATE
                    : Point::STATUS_REQUIRES_CREATE;
                $r->save();
            }
        }
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
}

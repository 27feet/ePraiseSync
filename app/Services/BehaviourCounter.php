<?php

namespace App\Services;

use App\Models\Demerit;
use App\Models\Intervention;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BehaviourCounter
{
    public function __construct(private readonly SchoolDateWindow $window) {}

    /**
     * Generic counter across a given set of school dates.
     * @param 'demerit'|'intervention' $source
     * @param array<string> $categories
     * @param array<int|string> $studentIds (local student record_ids)
     * @param Collection<Carbon> $schoolDates (ascending)
     * @return array [student_id => [category => count]]
     */
    public function countsForDates(string $source, array $categories, array $studentIds, Collection $schoolDates): array
    {
        if ($schoolDates->isEmpty() || empty($categories) || empty($studentIds)) {
            return [];
        }

        $start = $schoolDates->first();
        $end   = $schoolDates->last();

        $excludeStatuses = [
            // Do not count items that were deleted (fully or partially) in your local DB
            // (we’re counting local behaviour)
            // Adjust to your constants if needed:
            // e.g. Demerit::STATUS_DELETED, Demerit::STATUS_PARTIALLY_DELETED, etc.
            // For safety we fetch dynamically per model below.
        ];

        if ($source === 'demerit') {
            $deleted = [Demerit::STATUS_DELETED ?? -9999, Demerit::STATUS_PARTIALLY_DELETED ?? -9998];
            $excludeStatuses = array_filter($deleted, fn($v) => is_numeric($v));
            $rows = Demerit::query()
                ->select(['student_id', 'category', DB::raw('COUNT(*) as c')])
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->whereIn('student_id', $studentIds)
                ->whereIn('category', $categories)
                ->when(!empty($excludeStatuses), fn($q) => $q->whereNotIn('sync_status', $excludeStatuses))
                ->groupBy('student_id', 'category')
                ->get();
        } else { // 'intervention'
            $deleted = [Intervention::STATUS_DELETED ?? -9999, Intervention::STATUS_PARTIALLY_DELETED ?? -9998];
            $excludeStatuses = array_filter($deleted, fn($v) => is_numeric($v));
            $rows = Intervention::query()
                ->select(['student_id', 'intervention_type as category', DB::raw('COUNT(*) as c')])
                ->whereBetween('date_added', [$start->toDateString(), $end->toDateString()])
                ->whereIn('student_id', $studentIds)
                ->whereIn('intervention_type', $categories)
                ->when(!empty($excludeStatuses), fn($q) => $q->whereNotIn('sync_status', $excludeStatuses))
                ->groupBy('student_id', 'intervention_type')
                ->get();
        }

        $out = [];
        foreach ($rows as $r) {
            $sid = $r->student_id;
            $cat = $r->category;
            $out[$sid][$cat] = (int) $r->c;
        }
        // Ensure missing pairs are zeroed
        foreach ($studentIds as $sid) {
            foreach ($categories as $cat) {
                $out[$sid][$cat] = $out[$sid][$cat] ?? 0;
            }
        }
        return $out;
    }

    /** Convenience wrappers expected by some callers */

    public function demeritCountsForDates(array $categories, array $studentIds, Collection $schoolDates): array
    {
        return $this->countsForDates('demerit', $categories, $studentIds, $schoolDates);
    }

    public function interventionCountsForDates(array $categories, array $studentIds, Collection $schoolDates): array
    {
        return $this->countsForDates('intervention', $categories, $studentIds, $schoolDates);
    }

    /**
     * Count last N school days ending at (and including) $asOf.
     */
    public function demeritCountsForLastSchoolDays(Carbon $asOf, int $days, array $categories, array $studentIds): array
    {
        $dates = $this->window->lastSchoolDays($asOf, $days);
        return $this->demeritCountsForDates($categories, $studentIds, $dates);
    }

    public function interventionCountsForLastSchoolDays(Carbon $asOf, int $days, array $categories, array $studentIds): array
    {
        $dates = $this->window->lastSchoolDays($asOf, $days);
        return $this->interventionCountsForDates($categories, $studentIds, $dates);
    }
}

<?php

namespace App\Services;

use App\Models\AcademicCalendarDate;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SchoolDateWindow
{
    /**
     * Return the last N school days (as Carbon dates), ending at (and including) $endAt.
     * Results are sorted ascending (oldest → newest).
     * (Kept from your original contract.)
     */
    public function lastSchoolDays(Carbon $endAt, int $days): Collection
    {
        $dates = AcademicCalendarDate::query()
            ->where('is_school_day', true)
            ->whereDate('date', '<=', $endAt->toDateString())
            ->orderBy('date', 'desc')
            ->limit($days)
            ->pluck('date');

        return $dates->filter()->values()->reverse()->values();
    }

    /**
     * NEW: Convenience alias (same as lastSchoolDays) to match earlier references.
     */
    public function lastNSchoolDays(Carbon $endAt, int $days): Collection
    {
        return $this->lastSchoolDays($endAt, $days);
    }

    /**
     * NEW: Return all school-day Carbon dates between $start and $end inclusive.
     * Results sorted ascending.
     */
    public function schoolDaysBetween(Carbon $start, Carbon $end): Collection
    {
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        return AcademicCalendarDate::query()
            ->where('is_school_day', true)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->orderBy('date', 'asc')
            ->pluck('date')
            ->filter()
            ->values();
    }

    /**
     * NEW: Return [dates, termId] for the term that contains $pivot.
     * dates: Collection<Carbon> (school days in that term, ascending)
     * termId: int|null
     */
    public function termWindow(Carbon $pivot): array
    {
        $row = AcademicCalendarDate::query()
            ->whereDate('date', $pivot->toDateString())
            ->first();

        $termId = $row?->term_id;

        if (!$termId) {
            return [collect(), null];
        }

        $dates = AcademicCalendarDate::query()
            ->where('term_id', $termId)
            ->where('is_school_day', true)
            ->orderBy('date', 'asc')
            ->pluck('date')
            ->filter()
            ->values();

        return [$dates, $termId];
    }
}

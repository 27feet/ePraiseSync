<?php

namespace App\Support;

use App\Models\AcademicCalendarDate;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SchoolCalendar
{
    /**
     * Return the term_id that contains the given date (or null if none).
     */
    public static function currentTermId(Carbon|string $date): ?int
    {
        $d = $date instanceof Carbon ? $date->toDateString() : (string) $date;

        return AcademicCalendarDate::query()
            ->whereDate('date', $d)
            ->value('term_id');
    }

    /**
     * Is this date a school day? (based on AcademicCalendarDate.is_school_day)
     */
    public static function isSchoolDay(Carbon|string $date): bool
    {
        $d = $date instanceof Carbon ? $date->toDateString() : (string) $date;

        return (bool) AcademicCalendarDate::query()
            ->whereDate('date', $d)
            ->value('is_school_day');
    }

    /**
     * Get the last N school-day dates on/before $asOf.
     * If $termId is provided, constrain to that term.
     *
     * @return \Illuminate\Support\Collection<Carbon>
     */
    public static function recentSchoolDays(int $n, Carbon|string|null $asOf = null, ?int $termId = null): Collection
    {
        $asOfDate = $asOf
            ? ($asOf instanceof Carbon ? $asOf->toDateString() : (string) $asOf)
            : now()->toDateString();

        $q = AcademicCalendarDate::query()
            ->whereDate('date', '<=', $asOfDate)
            ->where('is_school_day', true);

        if ($termId !== null) {
            $q->where('term_id', $termId);
        }

        // Get the last N school dates (descending), then return ascending
        $dates = $q->orderBy('date', 'desc')
            ->limit($n)
            ->pluck('date')
            ->map(fn($d) => Carbon::parse($d))
            ->reverse()
            ->values();

        return $dates;
    }

    /**
     * Get the start/end (first/last school day) of the term containing $date.
     * Returns ['term_id' => int, 'start' => Carbon, 'end' => Carbon] or null.
     */
    public static function termWindow(Carbon|string $date): ?array
    {
        $d = $date instanceof Carbon ? $date->toDateString() : (string) $date;

        $row = AcademicCalendarDate::query()
            ->whereDate('date', $d)
            ->first(['term_id']);

        if (!$row || !$row->term_id) {
            return null;
        }

        $termId = (int) $row->term_id;

        $bounds = AcademicCalendarDate::query()
            ->where('term_id', $termId)
            ->where('is_school_day', true)
            ->selectRaw('MIN(date) as start_date, MAX(date) as end_date')
            ->first();

        if (!$bounds || !$bounds->start_date || !$bounds->end_date) {
            return null;
        }

        return [
            'term_id' => $termId,
            'start'   => Carbon::parse($bounds->start_date),
            'end'     => Carbon::parse($bounds->end_date),
        ];
    }
}

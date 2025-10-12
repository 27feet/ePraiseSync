<?php

namespace App\Console\Commands;

use App\Support\SchoolCalendar;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CalendarSmokeCommand extends Command
{
    protected $signature = 'calendar:smoke 
        {--date= : ISO date like 2025-09-01 (defaults to today)} 
        {--days=10 : How many recent school days to list} 
        {--term= : Force a specific term_id (optional)}';

    protected $description = 'Quick smoke test for SchoolCalendar helper';

    public function handle(): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        $asOf = Carbon::parse($date);

        $this->info("As of: {$asOf->toDateString()}");

        $termId = $this->option('term');
        if ($termId === null) {
            $termId = SchoolCalendar::currentTermId($asOf);
            $this->line("currentTermId: " . var_export($termId, true));
        } else {
            $this->line("forced term_id: {$termId}");
        }

        $isSchool = SchoolCalendar::isSchoolDay($asOf) ? 'yes' : 'no';
        $this->line("isSchoolDay: {$isSchool}");

        $window = SchoolCalendar::termWindow($asOf);
        if ($window) {
            $this->line("termWindow: term_id={$window['term_id']} start={$window['start']->toDateString()} end={$window['end']->toDateString()}");
        } else {
            $this->line("termWindow: (none)");
        }

        $n = (int) $this->option('days');
        $recent = SchoolCalendar::recentSchoolDays($n, $asOf, $termId ? (int)$termId : null);
        $this->line("recentSchoolDays({$n}): " . $recent->map->toDateString()->implode(', '));

        // basic assertions
        if ($recent->isNotEmpty()) {
            $sorted = $recent->sort()->values();
            if ($sorted->toJson() !== $recent->toJson()) {
                $this->warn('Warning: recentSchoolDays are not in ascending order.');
            }
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}

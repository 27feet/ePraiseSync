<?php

namespace App\Console\Commands;

use App\Services\BehaviourCounter;
use App\Services\SchoolDateWindow;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BehaviourCounterDryRun extends Command
{
    protected $signature = 'behaviour:test-counts 
                            {--as-of= : YYYY-MM-DD (defaults to yesterday local)} 
                            {--days=10 : Last N school days} 
                            {--source=demerit : demerit|intervention}
                            {--categories=* : Categories to include}
                            {--students=* : Student record_ids to include}';

    protected $description = 'Dry-run counter for behaviour over last N school days';

    public function handle(BehaviourCounter $counter, SchoolDateWindow $window): int
    {
        $asOfOpt = $this->option('as-of');
        $asOf = $asOfOpt ? Carbon::parse($asOfOpt)->startOfDay() : Carbon::today('Europe/London')->subDay();

        $days       = (int) $this->option('days');
        $source     = (string) $this->option('source');
        $categories = (array) $this->option('categories');
        $students   = (array) $this->option('students');

        if ($days < 1) {
            $this->error('Days must be >= 1');
            return 1;
        }
        if (!in_array($source, ['demerit', 'intervention'], true)) {
            $this->error('Invalid --source');
            return 1;
        }
        if (empty($categories)) {
            $this->error('Provide at least one --categories=');
            return 1;
        }
        if (empty($students)) {
            $this->error('Provide at least one --students=');
            return 1;
        }

        $dates = $window->lastSchoolDays($asOf, $days);

        $method = $source === 'demerit'
            ? 'demeritCountsForDates'
            : 'interventionCountsForDates';

        $counts = $counter->$method($categories, $students, $dates);

        $this->info("As of {$asOf->toDateString()} (last {$days} school days):");
        foreach ($counts as $studentId => $byCat) {
            $line = "Student {$studentId}: ";
            $parts = [];
            foreach ($byCat as $cat => $c) {
                $parts[] = "{$cat}={$c}";
            }
            $this->line($line . implode(', ', $parts));
        }

        return 0;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\BehaviourNotificationEvaluator;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BehaviourEvaluateCommand extends Command
{
    /**
     * Run the behaviour notification evaluator.
     *
     * Usage:
     *  php artisan behaviour:evaluate
     *  php artisan behaviour:evaluate --date=2025-09-06
     */
    protected $signature = 'behaviour:evaluate
                            {--date= : YYYY-MM-DD to evaluate (defaults to yesterday)}
                            {--dry-run : Evaluate without creating notification logs}';

    protected $description = 'Evaluate behaviour notification rules and create logs for newly-triggered thresholds.';

    public function handle(BehaviourNotificationEvaluator $evaluator): int
    {
        // Resolve the as-of date (default: yesterday, as discussed)
        $dateOpt = $this->option('date');
        $asOf = $dateOpt
            ? Carbon::parse($dateOpt)->startOfDay()
            : Carbon::yesterday()->startOfDay();
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Evaluating rules as of {$asOf->toDateString()}" . ($dryRun ? ' (dry-run)' : '') . " …");

        $created = $evaluator->evaluateAndLog($asOf, !$dryRun);

        if ($dryRun) {
            $this->info("Dry run complete. Would create {$created} notification log(s).");
        } else {
            $this->info("Done. Created {$created} notification log(s).");
        }

        return self::SUCCESS;
    }
}

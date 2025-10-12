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
    protected $signature = 'behaviour:evaluate {--date= : YYYY-MM-DD to evaluate (defaults to yesterday)}';

    protected $description = 'Evaluate behaviour notification rules and create logs for newly-triggered thresholds.';

    public function handle(BehaviourNotificationEvaluator $evaluator): int
    {
        // Resolve the as-of date (default: yesterday, as discussed)
        $dateOpt = $this->option('date');
        $asOf = $dateOpt
            ? Carbon::parse($dateOpt)->startOfDay()
            : Carbon::yesterday()->startOfDay();

        $this->info("Evaluating rules as of {$asOf->toDateString()} …");

        $created = $evaluator->evaluateAndLog($asOf);

        $this->info("Done. Created {$created} notification log(s).");

        return self::SUCCESS;
    }
}

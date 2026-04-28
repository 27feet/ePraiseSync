<?php


namespace App\Console\Commands;

use App\Models\Point;
use App\Models\Demerit;
use App\Jobs\CreatePoint;
use App\Jobs\DeletePoint;
use App\Jobs\UpdatePoint;
use App\Jobs\CreateDemerit;
use App\Jobs\DeleteDemerit;
use App\Jobs\UpdateDemerit;
use App\Models\Intervention;
use Illuminate\Support\Sleep;
use Illuminate\Console\Command;
use App\Jobs\CreateIntervention;
use App\Jobs\DeleteIntervention;
use App\Jobs\UpdateIntervention;
use App\Support\TaskMonitor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessWritebackCommand extends Command
{

    protected $signature = 'writeback:processAll';
    protected $description = 'Update Arbor from Database';
    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        if (TaskMonitor::disabled('writeback:processAll')) {
            $this->info('writeback:processAll is disabled via TaskMonitor.');
            return 0;
        }

        TaskMonitor::heartbeat('writeback:processAll', 'running');

        $this->scheduleAutoRetries(Point::class);
        $this->scheduleAutoRetries(Demerit::class);
        $this->scheduleAutoRetries(Intervention::class);
        $this->warnOnExhaustedRetries(Point::class, 'points');
        $this->warnOnExhaustedRetries(Demerit::class, 'demerits');
        $this->warnOnExhaustedRetries(Intervention::class, 'interventions');

        // Credit model: 180 credits/min. Points cost 1, demerits/interventions cost 6.
        $credits = 180;
        $costs = [
            'points'        => 1,
            'demerits'      => 6,
            'interventions' => 6,
        ];

        $credits = $this->dispatchBatch(
            Intervention::class,
            [Intervention::STATUS_REQUIRES_DELETION, Intervention::STATUS_PARTIALLY_DELETED],
            Intervention::STATUS_READY_TO_DELETE,
            $costs['interventions'],
            1400,
            static fn (int $id) => DeleteIntervention::dispatch($id)->afterCommit(),
            $credits
        );

        $credits = $this->dispatchBatch(
            Demerit::class,
            [Demerit::STATUS_REQUIRES_DELETION, Demerit::STATUS_PARTIALLY_DELETED],
            Demerit::STATUS_READY_TO_DELETE,
            $costs['demerits'],
            1400,
            static fn (int $id) => DeleteDemerit::dispatch($id)->afterCommit(),
            $credits
        );

        $credits = $this->dispatchBatch(
            Point::class,
            [Point::STATUS_REQUIRES_DELETION, Point::STATUS_PARTIALLY_DELETED],
            Point::STATUS_READY_TO_DELETE,
            $costs['points'],
            280,
            static fn (int $id) => DeletePoint::dispatch($id)->afterCommit(),
            $credits
        );

        $credits = $this->dispatchBatch(
            Intervention::class,
            [Intervention::STATUS_REQUIRES_UPDATE],
            Intervention::STATUS_READY_TO_UPDATE,
            $costs['interventions'],
            1400,
            static fn (int $id) => UpdateIntervention::dispatch($id)->afterCommit(),
            $credits
        );

        $credits = $this->dispatchBatch(
            Demerit::class,
            [Demerit::STATUS_REQUIRES_UPDATE],
            Demerit::STATUS_READY_TO_UPDATE,
            $costs['demerits'],
            1400,
            static fn (int $id) => UpdateDemerit::dispatch($id)->afterCommit(),
            $credits
        );

        $credits = $this->dispatchBatch(
            Point::class,
            [Point::STATUS_REQUIRES_UPDATE],
            Point::STATUS_READY_TO_UPDATE,
            $costs['points'],
            280,
            static fn (int $id) => UpdatePoint::dispatch($id)->afterCommit(),
            $credits
        );

        $credits = $this->dispatchBatch(
            Intervention::class,
            [Intervention::STATUS_REQUIRES_CREATE],
            Intervention::STATUS_READY_TO_CREATE,
            $costs['interventions'],
            1400,
            static fn (int $id) => CreateIntervention::dispatch($id)->afterCommit(),
            $credits
        );

        $credits = $this->dispatchBatch(
            Demerit::class,
            [Demerit::STATUS_REQUIRES_CREATE],
            Demerit::STATUS_READY_TO_CREATE,
            $costs['demerits'],
            1400,
            static fn (int $id) => CreateDemerit::dispatch($id)->afterCommit(),
            $credits
        );

        $credits = $this->dispatchBatch(
            Point::class,
            [Point::STATUS_REQUIRES_CREATE],
            Point::STATUS_READY_TO_CREATE,
            $costs['points'],
            280,
            static fn (int $id) => CreatePoint::dispatch($id)->afterCommit(),
            $credits
        );

        TaskMonitor::heartbeat('writeback:processAll', 'ok');
        return 0;
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private function dispatchBatch(
        string $modelClass,
        array $eligibleStatuses,
        int $readyStatus,
        int $costPerRecord,
        int $sleepMs,
        callable $dispatcher,
        int $credits
    ): int {
        if ($credits < $costPerRecord) {
            return $credits;
        }

        $limit = intdiv($credits, $costPerRecord);
        $records = $modelClass::query()
            ->whereIn('sync_status', $eligibleStatuses)
            ->where(function ($query) {
                $query->whereNull('sync_next_retry_at')
                    ->orWhere('sync_next_retry_at', '<=', now());
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        foreach ($records as $record) {
            if (!in_array((int) $record->sync_status, $eligibleStatuses, true) || $credits < $costPerRecord) {
                break;
            }

            $transitioned = $modelClass::query()
                ->whereKey($record->id)
                ->where('sync_status', (int) $record->sync_status)
                ->where(function ($query) {
                    $query->whereNull('sync_next_retry_at')
                        ->orWhere('sync_next_retry_at', '<=', now());
                })
                ->update([
                    'sync_status' => $readyStatus,
                ]);

            if ($transitioned !== 1) {
                continue;
            }

            Sleep::for($sleepMs)->milliseconds();
            $dispatcher((int) $record->id);

            $credits -= $costPerRecord;
        }

        return $credits;
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private function scheduleAutoRetries(string $modelClass): void
    {
        $maxAttempts = max(1, (int) config('services.arbor.sync.max_attempts', 10));

        $failedToRequires = [
            $modelClass::STATUS_FAILED_CREATION => $modelClass::STATUS_REQUIRES_CREATE,
            $modelClass::STATUS_FAILED_UPDATE => $modelClass::STATUS_REQUIRES_UPDATE,
            $modelClass::STATUS_FAILED_DELETION => $modelClass::STATUS_REQUIRES_DELETION,
        ];

        $records = $modelClass::query()
            ->whereIn('sync_status', array_keys($failedToRequires))
            ->orderBy('id')
            ->limit(1000)
            ->get();

        foreach ($records as $record) {
            $failedStatus = (int) $record->sync_status;
            $retryStatus = $failedToRequires[$failedStatus] ?? null;
            if ($retryStatus === null) {
                continue;
            }

            if ($record->sync_next_retry_at === null) {
                if ((int) $record->sync_attempts >= $maxAttempts) {
                    continue;
                }

                $attempt = ((int) $record->sync_attempts) + 1;
                $updated = $modelClass::query()
                    ->whereKey($record->id)
                    ->where('sync_status', $failedStatus)
                    ->whereNull('sync_next_retry_at')
                    ->where('sync_attempts', (int) $record->sync_attempts)
                    ->update([
                    'sync_attempts'       => $attempt,
                    'sync_last_failed_at' => now(),
                    'sync_next_retry_at'  => now()->addSeconds($this->retryDelaySeconds($attempt)),
                ]);

                if ($updated !== 1) {
                    continue;
                }
                continue;
            }

            if ($record->sync_next_retry_at->isFuture()) {
                continue;
            }

            $modelClass::query()
                ->whereKey($record->id)
                ->where('sync_status', $failedStatus)
                ->whereNotNull('sync_next_retry_at')
                ->where('sync_next_retry_at', '<=', now())
                ->update([
                    'sync_status'        => $retryStatus,
                    'synced_at'          => null,
                    'sync_next_retry_at' => null,
                ]);
        }
    }

    private function retryDelaySeconds(int $attempt): int
    {
        $delays = array_values(array_filter(
            array_map(
                static fn ($value) => (int) $value,
                (array) config('services.arbor.sync.retry_delays_seconds', [60, 180, 600, 1800, 3600, 7200, 14400, 21600, 28800, 43200])
            ),
            static fn (int $value) => $value > 0
        ));

        if (empty($delays)) {
            return 60;
        }

        $index = min(max($attempt, 1) - 1, count($delays) - 1);
        $baseDelay = $delays[$index];
        $jitterPercent = max(0.0, min(0.5, (float) config('services.arbor.sync.retry_jitter_percent', 0.15)));
        $jitterAmount = (int) round($baseDelay * $jitterPercent);

        if ($jitterAmount <= 0) {
            return $baseDelay;
        }

        return max(1, $baseDelay + random_int(-$jitterAmount, $jitterAmount));
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private function warnOnExhaustedRetries(string $modelClass, string $label): void
    {
        $maxAttempts = max(1, (int) config('services.arbor.sync.max_attempts', 10));
        $count = $modelClass::query()
            ->whereIn('sync_status', [
                $modelClass::STATUS_FAILED_CREATION,
                $modelClass::STATUS_FAILED_UPDATE,
                $modelClass::STATUS_FAILED_DELETION,
            ])
            ->where('sync_attempts', '>=', $maxAttempts)
            ->count();

        if ($count < 1) {
            return;
        }

        $cacheKey = "sync_exhausted_alert:{$label}";
        if (!Cache::add($cacheKey, now()->timestamp, now()->addMinutes(30))) {
            return;
        }

        Log::warning('Arbor sync max attempts reached', [
            'model' => $label,
            'count' => $count,
            'max_attempts' => $maxAttempts,
        ]);
    }
}

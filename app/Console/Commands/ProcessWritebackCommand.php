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
use App\Support\ArborSyncRetry;
use Illuminate\Support\Facades\Bus;
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

    private bool $dispatchFailed = false;

    protected $signature = 'writeback:processAll';
    protected $description = 'Update Arbor from Database';
    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->dispatchFailed = false;
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

        // Per-pass dispatch budget (not an API rate limit): 180 credits. Points cost 1, demerits/interventions cost 6.
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
            static fn (int $id) => Bus::dispatch(new DeleteIntervention($id)),
            $credits
        );

        $credits = $this->dispatchBatch(
            Demerit::class,
            [Demerit::STATUS_REQUIRES_DELETION, Demerit::STATUS_PARTIALLY_DELETED],
            Demerit::STATUS_READY_TO_DELETE,
            $costs['demerits'],
            static fn (int $id) => Bus::dispatch(new DeleteDemerit($id)),
            $credits
        );

        $credits = $this->dispatchBatch(
            Point::class,
            [Point::STATUS_REQUIRES_DELETION, Point::STATUS_PARTIALLY_DELETED],
            Point::STATUS_READY_TO_DELETE,
            $costs['points'],
            static fn (int $id) => Bus::dispatch(new DeletePoint($id)),
            $credits
        );

        $credits = $this->dispatchBatch(
            Intervention::class,
            [Intervention::STATUS_REQUIRES_UPDATE],
            Intervention::STATUS_READY_TO_UPDATE,
            $costs['interventions'],
            static fn (int $id) => Bus::dispatch(new UpdateIntervention($id)),
            $credits
        );

        $credits = $this->dispatchBatch(
            Demerit::class,
            [Demerit::STATUS_REQUIRES_UPDATE],
            Demerit::STATUS_READY_TO_UPDATE,
            $costs['demerits'],
            static fn (int $id) => Bus::dispatch(new UpdateDemerit($id)),
            $credits
        );

        $credits = $this->dispatchBatch(
            Point::class,
            [Point::STATUS_REQUIRES_UPDATE],
            Point::STATUS_READY_TO_UPDATE,
            $costs['points'],
            static fn (int $id) => Bus::dispatch(new UpdatePoint($id)),
            $credits
        );

        $credits = $this->dispatchBatch(
            Intervention::class,
            [Intervention::STATUS_REQUIRES_CREATE],
            Intervention::STATUS_READY_TO_CREATE,
            $costs['interventions'],
            static fn (int $id) => Bus::dispatch(new CreateIntervention($id)),
            $credits
        );

        $credits = $this->dispatchBatch(
            Demerit::class,
            [Demerit::STATUS_REQUIRES_CREATE],
            Demerit::STATUS_READY_TO_CREATE,
            $costs['demerits'],
            static fn (int $id) => Bus::dispatch(new CreateDemerit($id)),
            $credits
        );

        $credits = $this->dispatchBatch(
            Point::class,
            [Point::STATUS_REQUIRES_CREATE],
            Point::STATUS_READY_TO_CREATE,
            $costs['points'],
            static fn (int $id) => Bus::dispatch(new CreatePoint($id)),
            $credits
        );

        TaskMonitor::heartbeat('writeback:processAll', $this->dispatchFailed ? 'failed' : 'ok');
        return $this->dispatchFailed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private function dispatchBatch(
        string $modelClass,
        array $eligibleStatuses,
        int $readyStatus,
        int $costPerRecord,
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

            try {
                $dispatcher((int) $record->id);
            } catch (\Throwable $e) {
                // A lost Redis acknowledgement is ambiguous: do not automatically enqueue again.
                $modelClass::whereKey($record->id)->where('sync_status', $readyStatus)
                    ->whereNull('sync_last_error_status')->whereNull('sync_last_error_body')->update([
                    'sync_last_error_body' => 'Queue dispatch failed; delivery uncertain. Use writeback:recover-ready after draining the queue and stopping workers.',
                ]);
                Log::error('Writeback queue dispatch failed after READY transition; manual recovery required', [
                    'model' => $modelClass, 'id' => $record->id, 'ready_status' => $readyStatus,
                    'exception' => $e::class,
                ]);
                $this->dispatchFailed = true;
                return 0;
            }

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
                    'sync_next_retry_at'  => now()->addSeconds(ArborSyncRetry::delaySeconds($attempt)),
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

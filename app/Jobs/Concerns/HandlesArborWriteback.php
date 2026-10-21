<?php

namespace App\Jobs\Concerns;

use App\Models\Point;
use App\Support\ArborHttp;
use App\Support\ArborSyncRetry;
use App\Support\ArborThrottledException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

trait HandlesArborWriteback
{
    private float $arborDeadline;

    public function handle(): int
    {
        $record = $this->writebackRecord();
        // Refresh serialized jobs, then claim READY atomically. A duplicate cannot run the same operation.
        if (! $record->exists || ! $record->fresh()) {
            return 0;
        }
        $record->refresh();
        $this->lastErrorStatus = null;
        $this->lastErrorBody = null;
        $name = class_basename($this);
        $ready = str_starts_with($name, 'Create') ? 10 : (str_starts_with($name, 'Update') ? -20 : -10);
        $failed = [10 => 11, -20 => -21, -10 => -11][$ready];
        if ($record->newQuery()->whereKey($record->getKey())->where('sync_status', $ready)
            ->update(['sync_status' => $failed]) !== 1) {
            return 0;
        }
        if ($ready === 10 && $this->hasArborProgress($record)) {
            $record->forceFill(['sync_status' => -2])->save();

            return 0;
        }

        $this->arborDeadline = microtime(true) + max(
            (int) config('services.arbor.rate_limit.request_timeout_seconds', 15) + 1,
            (int) config('services.arbor.rate_limit.job_budget_seconds', 45),
        );
        try {
            return $this->performWriteback();
        } catch (ArborThrottledException $e) {
            $this->checkpointArborProgress();
            // A partly created record resumes through Update, which already fills missing resources.
            if ($ready === 10 && $this->hasArborProgress($record)) {
                $failed = -21;
            }
            // A prior HTTP failure in this multi-request job must not be hidden by a later local deferral.
            $httpStatus = $e->httpStatus ?? $this->lastErrorStatus;
            $delay = max(1, $e->retryAfterSeconds);
            if ($httpStatus === null) {
                // No failed HTTP request: keep retry allowance and last real error intact, even at the cap.
                $requires = [11 => 1, -21 => -2, -11 => -1][$failed];
                $record->forceFill([
                    'sync_status' => $requires,
                    'sync_next_retry_at' => now()->addSeconds($delay),
                ])->save();
            } else {
                $attempt = (int) $record->sync_attempts + 1;
                $exhausted = $attempt >= max(1, (int) config('services.arbor.sync.max_attempts', 10));
                $delay = max($delay, ArborSyncRetry::delaySeconds($attempt));
                $record->forceFill([
                    'sync_status' => $failed,
                    'sync_attempts' => $attempt,
                    'sync_last_failed_at' => now(),
                    'sync_next_retry_at' => $exhausted ? null : now()->addSeconds($delay),
                    'sync_last_error_status' => $httpStatus,
                    'sync_last_error_body' => substr($e->httpStatus !== null ? $e->getMessage() : $this->lastErrorBody, 0, 4000),
                ])->save();
            }
            // One summary per minute for local admission/cooldown deferrals, not one per request.
            try {
                $shouldLog = $e->httpStatus === null && \Illuminate\Support\Facades\Cache::add('arbor_limiter_deferred_log', true, 60);
            } catch (\Throwable) {
                // Logging throttling must not break saved retry state if the cache shares failed Redis.
                $shouldLog = true;
            }
            if ($shouldLog) {
                Log::notice('Arbor writeback deferred; existing sync retry scheduler will resume it', [
                    'retry_after_seconds' => $delay, 'limiter_unavailable' => $e->getPrevious() !== null,
                ]);
            }

            return 0;
        } finally {
            $this->checkpointArborProgress();
        }
    }

    protected function arborClient(): \Illuminate\Http\Client\PendingRequest
    {
        return ArborHttp::client()->beforeSending(function () {
            $this->checkpointArborProgress();
            if (microtime(true) + (int) config('services.arbor.rate_limit.request_timeout_seconds', 15) >= $this->arborDeadline) {
                throw new ArborThrottledException(1, null, 'Arbor writeback yielded before exceeding its request time budget.');
            }
        });
    }

    private function writebackRecord(): Model
    {
        return $this->point ?? $this->demerit ?? $this->intervention;
    }

    /** Persist known IDs before another call can fail, without overwriting the operation's status. */
    private function checkpointArborProgress(): void
    {
        $record = $this->writebackRecord();
        if (! $record->exists) {
            return;
        }
        $dirty = array_filter($record->getDirty(), fn ($key) => str_starts_with($key, 'arbor_'), ARRAY_FILTER_USE_KEY);
        if ($dirty) {
            $record->newQuery()->whereKey($record->getKey())->update($dirty);
            $record->syncOriginalAttributes(array_keys($dirty));
        }
    }

    private function hasArborProgress(Model $record): bool
    {
        if ($record instanceof Point) {
            return ! empty($record->arbor_id);
        }

        return $record->arbor_point_id || $record->arbor_behaviour_id || $record->arbor_behaviour_student_id
            || ! empty(array_filter((array) $record->arbor_action_id));
    }
}

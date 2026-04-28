<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Contracts\Cache\LockTimeoutException;

class ArborRateLimiter
{
    /**
     * Consume $weight API-call tokens from the per-minute bucket.
     * Enforces both: (a) <= 240 calls/min and (b) >= 250ms spacing between calls.
     */
    public static function consume(int $weight = 1): void
    {
        $weight = max(1, $weight);

        while (true) {
            $waitMs = 0;
            try {
                $ok = Cache::lock('arbor_rate_lock', 5)->block(5, function () use ($weight, &$waitMs) {
                    $nowMs   = (int) floor(microtime(true) * 1000);
                    $lastMs  = (int) Cache::get('arbor_last_request_ms', 0);
                    $sinceMs = $nowMs - $lastMs;

                    // Enforce hard gap between requests
                    if ($sinceMs < 250) {
                        $waitMs = 250 - $sinceMs;
                        return false;
                    }

                    $minuteKey = 'arbor_api_calls:' . now()->format('YmdHi');
                    $ttl       = 70 - now()->second; // a little past the minute boundary

                    $current = Cache::increment($minuteKey, $weight);
                    Cache::put($minuteKey, $current, $ttl);

                    if ($current <= 240) {
                        Cache::put('arbor_last_request_ms', $nowMs, 120);
                        return true; // within budget and spacing
                    }

                    // Roll back the attempted increment and wait before retrying
                    Cache::decrement($minuteKey, $weight);
                    $waitMs = 250;
                    return false;
                });

                if ($ok === true) {
                    return;
                }
            } catch (LockTimeoutException $e) {
                // Could not obtain the lock quickly; back off briefly
                $waitMs = max($waitMs, 250);
            }

            if ($waitMs > 0) {
                Sleep::for($waitMs)->milliseconds();
            }
        }
    }
}

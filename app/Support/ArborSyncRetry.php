<?php

namespace App\Support;

class ArborSyncRetry
{
    public static function delaySeconds(int $attempt): int
    {
        $delays = array_values(array_filter(array_map('intval', (array) config(
            'services.arbor.sync.retry_delays_seconds', [60, 180, 600, 1800, 3600, 7200, 14400, 21600, 28800, 43200]
        )), static fn (int $value) => $value > 0));
        $base = $delays ? $delays[min(max($attempt, 1) - 1, count($delays) - 1)] : 60;
        $jitter = (int) round($base * max(0.0, min(0.5, (float) config('services.arbor.sync.retry_jitter_percent', 0.15))));

        return max(1, $base + ($jitter ? random_int(-$jitter, $jitter) : 0));
    }

    public static function retryAfter(?string $header): int
    {
        $fallback = max(1, (int) config('services.arbor.rate_limit.cooldown_seconds', 60));
        $header = trim($header ?? '');
        if (preg_match('/^\d+$/D', $header)) {
            return max(1, (int) $header);
        }
        $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $header, new \DateTimeZone('GMT'));

        return $date ? max(1, $date->getTimestamp() - now()->timestamp) : $fallback;
    }
}

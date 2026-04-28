<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class TaskMonitor
{
    /**
     * Simple cache-backed flags and heartbeats for scheduled tasks.
     */
    public static function key(string $task, string $suffix): string
    {
        return "task_monitor:{$task}:{$suffix}";
    }

    public static function disabled(string $task): bool
    {
        return Cache::get(self::key($task, 'disabled'), false) ? true : false;
    }

    public static function setDisabled(string $task, bool $disabled): void
    {
        Cache::put(self::key($task, 'disabled'), $disabled, now()->addDays(30));
    }

    public static function heartbeat(string $task, string $status): void
    {
        Cache::put(self::key($task, 'last_status'), $status, now()->addDays(7));
        Cache::put(self::key($task, 'last_seen_at'), now()->toDateTimeString(), now()->addDays(7));
    }

    public static function lastStatus(string $task): ?array
    {
        $status = Cache::get(self::key($task, 'last_status'));
        $at = Cache::get(self::key($task, 'last_seen_at'));
        return $status ? ['status' => $status, 'at' => $at] : null;
    }

    public static function runArtisan(string $command): string
    {
        try {
            Artisan::call($command);
            return Artisan::output();
        } catch (Throwable $e) {
            report($e);
            return $e->getMessage();
        }
    }
}

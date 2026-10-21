<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

class ArborRateLimiter
{
    /** Redis owns the clock and atomically admits calls across every worker. */
    public function waitMilliseconds(int $weight = 1): int
    {
        $limit = max(1, (int) config('services.arbor.rate_limit.requests', 240));
        if ($weight < 1 || $weight > $limit) {
            throw new \InvalidArgumentException('Arbor request weight must fit the rate-limit window.');
        }

        return (int) $this->connection()->eval(<<<'LUA'
local time = redis.call('TIME')
local now = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000)
local window = tonumber(ARGV[1])
local gap = tonumber(ARGV[2])
local limit = tonumber(ARGV[3])
local weight = tonumber(ARGV[4])
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - window)
local wait = math.max(0, tonumber(redis.call('GET', KEYS[2]) or '0') - now)
local last = tonumber(redis.call('GET', KEYS[3]) or '0')
wait = math.max(wait, last + gap - now)
local count = redis.call('ZCARD', KEYS[1])
if count + weight > limit then
    local oldest = redis.call('ZRANGE', KEYS[1], count + weight - limit - 1, count + weight - limit - 1, 'WITHSCORES')
    wait = math.max(wait, tonumber(oldest[2]) + window - now)
end
if wait > 0 then return math.ceil(wait) end
for i = 1, weight do redis.call('ZADD', KEYS[1], now, ARGV[5] .. ':' .. i) end
redis.call('PEXPIRE', KEYS[1], window + gap + 1000)
redis.call('SET', KEYS[3], now, 'PX', math.max(window, gap) + 1000)
return 0
LUA, 3, ...array_merge($this->keys(), [
            max(1, (int) config('services.arbor.rate_limit.window_ms', 60000)),
            max(0, (int) config('services.arbor.rate_limit.min_interval_ms', 250)),
            $limit, $weight, (string) Str::uuid(),
        ]));
    }

    public static function consume(int $weight = 1): void
    {
        $limiter = app(self::class);
        $deadline = hrtime(true) / 1000000 + max(0, (int) config('services.arbor.rate_limit.max_wait_ms', 1000));
        while (true) {
            try {
                $wait = $limiter->waitMilliseconds($weight);
            } catch (\Throwable $e) {
                if ($e instanceof \InvalidArgumentException) {
                    throw $e;
                }
                throw new ArborThrottledException(60, null, 'Arbor limiter unavailable; no request sent.', $e);
            }
            if ($wait === 0) {
                return;
            }
            // Include Redis round trips in the budget, not just requested sleep duration.
            $remaining = max(0, $deadline - hrtime(true) / 1000000);
            if ($wait > $remaining) {
                throw new ArborThrottledException((int) ceil($wait / 1000), null, 'Arbor request deferred by the shared limiter.');
            }
            Sleep::for($wait)->milliseconds();
        }
    }

    public function cooldown(int $seconds): void
    {
        $this->connection()->eval(<<<'LUA'
local time = redis.call('TIME')
local now = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000)
local untilAt = now + tonumber(ARGV[1])
local previous = tonumber(redis.call('GET', KEYS[1]) or '0')
if untilAt > previous then redis.call('SET', KEYS[1], untilAt, 'PX', ARGV[1]) end
return 1
LUA, 1, $this->keys()[1], max(1, $seconds) * 1000);
    }

    private function connection()
    {
        return Redis::connection(config('services.arbor.rate_limit.redis_connection', 'arbor_limiter'));
    }

    private function keys(): array
    {
        $key = (string) config('services.arbor.rate_limit.key', 'arbor:writeback');
        $scope = '{'.hash('sha256', $key).'}';

        return ["{$scope}:calls", "{$scope}:cooldown", "{$scope}:last"];
    }
}

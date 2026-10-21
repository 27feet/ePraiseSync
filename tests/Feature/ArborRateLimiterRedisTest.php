<?php

namespace Tests\Feature;

use App\Support\ArborHttp;
use App\Support\ArborRateLimiter;
use App\Support\ArborThrottledException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ArborWritebackFixtures;
use Tests\TestCase;

/** Opt-in real Redis tests: use an isolated local instance, never production. */
class ArborRateLimiterRedisTest extends TestCase
{
    use ArborWritebackFixtures, RefreshDatabase;

    private array $redisConfig;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('ARBOR_TEST_REDIS_PORT')) {
            $this->markTestSkipped('Set ARBOR_TEST_REDIS_PORT to an isolated local Redis instance.');
        }
        $this->redisConfig = [
            'database.redis.client' => 'predis',
            'database.redis.options.prefix' => 'epraise_writeback_test:',
            'database.redis.arbor_test' => ['host' => '127.0.0.1', 'port' => (int) getenv('ARBOR_TEST_REDIS_PORT'), 'database' => 0, 'timeout' => 2],
            'services.arbor.rate_limit.redis_connection' => 'arbor_test',
            'services.arbor.rate_limit.key' => (string) Str::uuid(),
            'services.arbor.rate_limit.min_interval_ms' => 0,
            'services.arbor.rate_limit.max_wait_ms' => 0,
        ];
        config($this->redisConfig);
        config(['services.arbor.base_url' => 'https://arbor.test']);
        Http::preventStrayRequests();
    }

    public function test_preexisting_mixed_job_backlog_is_limited_to_actual_http_calls(): void
    {
        Http::fake(['arbor.test/*' => Http::response('', 204)]);
        $queue = 'writeback-test-'.Str::uuid();
        // Exercise Laravel's native Redis connector, without Horizon's optional instrumentation.
        \Illuminate\Support\Facades\Queue::addConnector('native_redis_test', fn () => new \Illuminate\Queue\Connectors\RedisConnector(app('redis')));
        config(['queue.connections.arbor_test' => [
            'driver' => 'native_redis_test', 'connection' => 'arbor_test', 'queue' => $queue,
            'retry_after' => 120, 'block_for' => null, 'after_commit' => false,
        ]]);
        for ($i = 0; $i < 300; $i++) {
            if ($i % 3 === 0) {
                $record = $this->point(['record_id' => $i + 1, 'sync_status' => -10, 'arbor_id' => (string) ($i + 1)]);
                $job = new \App\Jobs\DeletePoint($record->id);
            } else {
                $class = $i % 3 === 1 ? \App\Models\Demerit::class : \App\Models\Intervention::class;
                $record = $this->complexRecord($class, ['record_id' => $i + 1, 'sync_status' => -10, 'arbor_point_id' => (string) ($i + 1)]);
                $job = $i % 3 === 1 ? new \App\Jobs\DeleteDemerit($record->id) : new \App\Jobs\DeleteIntervention($record->id);
            }
            \Illuminate\Support\Facades\Queue::connection('arbor_test')->push($job);
        }
        $this->assertSame(300, \Illuminate\Support\Facades\Queue::connection('arbor_test')->size($queue));
        // Start the standard Laravel worker only after all 300 jobs are already in Redis.
        $this->artisan('queue:work', [
            'connection' => 'arbor_test', '--queue' => $queue, '--stop-when-empty' => true,
            '--sleep' => 0, '--tries' => 1, '--memory' => 512,
        ])->assertSuccessful();
        $this->assertSame(0, \Illuminate\Support\Facades\Queue::connection('arbor_test')->size($queue));
        Http::assertSentCount(240);
        $remaining = \App\Models\Point::count() + \App\Models\Demerit::count() + \App\Models\Intervention::count();
        $this->assertSame(60, $remaining);
        $this->assertSame(20, \App\Models\Point::where('sync_status', -1)->count());
        $this->assertSame(20, \App\Models\Demerit::where('sync_status', -1)->count());
        $this->assertSame(20, \App\Models\Intervention::where('sync_status', -1)->count());
    }

    public function test_separate_php_workers_compete_for_one_atomic_budget(): void
    {
        $configuration = array_merge($this->redisConfig, ['services.arbor.rate_limit.requests' => 3]);
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(json_decode($argv[1], true));
echo (new App\Support\ArborRateLimiter)->waitMilliseconds();
PHP;
        $workers = [];
        for ($i = 0; $i < 8; $i++) {
            $workers[] = $process = new Process([PHP_BINARY, '-r', $code, json_encode($configuration)], base_path(), ['APP_ENV' => 'testing']);
            $process->start();
        }
        $admitted = 0;
        foreach ($workers as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            if (trim($process->getOutput()) === '0') {
                $admitted++;
            }
        }
        $this->assertSame(3, $admitted);
    }

    public function test_spacing_and_rolling_window_are_shared_between_limiter_instances(): void
    {
        config(['services.arbor.rate_limit.min_interval_ms' => 250]);
        $this->assertSame(0, (new ArborRateLimiter)->waitMilliseconds());
        $this->assertGreaterThan(0, (new ArborRateLimiter)->waitMilliseconds());
        config(['services.arbor.rate_limit.key' => (string) Str::uuid(), 'services.arbor.rate_limit.requests' => 2, 'services.arbor.rate_limit.window_ms' => 150, 'services.arbor.rate_limit.min_interval_ms' => 0]);
        $limiter = new ArborRateLimiter;
        $this->assertSame(0, $limiter->waitMilliseconds());
        $this->assertSame(0, $limiter->waitMilliseconds());
        $this->assertGreaterThan(0, $wait = $limiter->waitMilliseconds());
        usleep(($wait + 10) * 1000);
        $this->assertSame(0, $limiter->waitMilliseconds());
    }

    public function test_real_429_cooldown_blocks_other_clients_and_cannot_be_shortened(): void
    {
        $responses = 0;
        Http::fake(['arbor.test/*' => function () use (&$responses) {
            $responses++;

            return Http::response('Too Many Requests', 429, ['Retry-After' => '120']);
        }]);
        try {
            ArborHttp::client()->post('/rest-v2/point-awards', []);
            $this->fail('Expected 429 to interrupt the job.');
        } catch (ArborThrottledException $e) {
            $this->assertSame(429, $e->httpStatus);
            $this->assertSame(120, $e->retryAfterSeconds);
        }
        (new ArborRateLimiter)->cooldown(1);
        $this->assertGreaterThan(118000, (new ArborRateLimiter)->waitMilliseconds());
        try {
            ArborHttp::client()->delete('/rest-v2/point-awards/1');
            $this->fail('The shared cooldown must prevent another HTTP request.');
        } catch (ArborThrottledException $e) {
            $this->assertNull($e->httpStatus);
        }
        $this->assertSame(1, $responses);
        Http::assertSentCount(1);
    }

    public function test_graphql_rest_and_raw_legacy_requests_share_one_budget(): void
    {
        config(['services.arbor.rate_limit.requests' => 2, 'laravelarbor.url' => 'https://arbor.test']);
        Http::fake(['arbor.test/*' => Http::response(['data' => ['Student' => []]])]);
        $response = \ANP\LaravelArbor\Facades\ArborGraphQL::query('query { Student { id } }');
        $this->assertSame(['Student' => []], $response->getData());
        ArborHttp::client()->post('/rest-v2/point-awards', []);
        try {
            Http::withMiddleware(ArborHttp::middleware())->delete('https://arbor.test/rest-v2/point-awards/1');
            $this->fail('Legacy requests must share the same two-call budget.');
        } catch (ArborThrottledException $e) {
            $this->assertNull($e->httpStatus);
        }
        Http::assertSentCount(2);
    }

    public function test_independent_workers_cannot_admit_the_same_250ms_slot(): void
    {
        $configuration = array_merge($this->redisConfig, ['services.arbor.rate_limit.min_interval_ms' => 250]);
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(json_decode($argv[1], true));
echo (new App\Support\ArborRateLimiter)->waitMilliseconds();
PHP;
        $workers = [];
        for ($i = 0; $i < 8; $i++) {
            $workers[] = $process = new Process([PHP_BINARY, '-r', $code, json_encode($configuration)], base_path(), ['APP_ENV' => 'testing']);
            $process->start();
        }
        $admitted = 0;
        foreach ($workers as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            if (trim($process->getOutput()) === '0') {
                $admitted++;
            }
        }
        $this->assertGreaterThanOrEqual(1, $admitted);
        $key = '{'.hash('sha256', $configuration['services.arbor.rate_limit.key']).'}:calls';
        $times = \Illuminate\Support\Facades\Redis::connection('arbor_test')->eval(
            "local rows = redis.call('ZRANGE', KEYS[1], 0, -1, 'WITHSCORES'); local times = {}; for i = 2, #rows, 2 do table.insert(times, rows[i]) end; return times", 1, $key
        );
        $this->assertCount($admitted, $times);
        for ($i = 1; $i < count($times); $i++) {
            $this->assertGreaterThanOrEqual(250, (int) $times[$i] - (int) $times[$i - 1]);
        }
    }
}

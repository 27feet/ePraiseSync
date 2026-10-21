<?php

namespace Tests\Feature;

use App\Jobs\CreatePoint;
use App\Jobs\DeletePoint;
use App\Jobs\UpdatePoint;
use App\Support\ArborRateLimiter;
use App\Support\ArborSyncRetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\ArborWritebackFixtures;
use Tests\TestCase;

class ArborWritebackTest extends TestCase
{
    use ArborWritebackFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mappingFixtures();
        $this->mock(ArborRateLimiter::class)->shouldReceive('waitMilliseconds')->andReturn(0);
        Http::preventStrayRequests();
    }

    public function test_dispatcher_preserves_credits_and_ready_states_without_sleeping(): void
    {
        Bus::fake();
        Sleep::fake();
        for ($i = 1; $i <= 32; $i++) {
            $this->complexRecord(\App\Models\Intervention::class, ['record_id' => $i, 'sync_status' => 1]);
        }
        $point = $this->point(['sync_status' => 1]);
        $this->artisan('writeback:processAll')->assertSuccessful();
        Bus::assertDispatchedTimes(\App\Jobs\CreateIntervention::class, 30);
        Bus::assertNotDispatched(CreatePoint::class);
        $this->assertSame(30, \App\Models\Intervention::where('sync_status', 10)->count());
        $this->assertSame(1, $point->fresh()->sync_status);
        Sleep::assertNeverSlept();
    }

    public function test_dispatch_failure_is_visible_and_ready_recovery_requires_an_audit(): void
    {
        $point = $this->point(['sync_status' => 1]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Redis unavailable'));
        $this->artisan('writeback:processAll')->assertFailed();
        $this->assertSame(10, $point->fresh()->sync_status);
        $this->assertStringContainsString('delivery uncertain', $point->fresh()->sync_last_error_body);
        $this->artisan('writeback:recover-ready')->assertSuccessful();
        $this->assertSame(10, $point->fresh()->sync_status);
        $this->artisan('writeback:recover-ready --apply')->assertFailed();
        $this->artisan('writeback:recover-ready --apply --workers-stopped-and-queue-empty')->assertSuccessful();
        $this->assertSame(1, $point->fresh()->sync_status);
    }

    public function test_ambiguous_publish_is_not_automatically_sent_twice(): void
    {
        $point = $this->point(['sync_status' => 1]);
        $acceptedJob = null;
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function ($job) use (&$acceptedJob) {
            $acceptedJob = $job;
            throw new \RuntimeException('Lost Redis publish acknowledgement');
        });
        $this->artisan('writeback:processAll')->assertFailed();
        $this->artisan('writeback:processAll')->assertSuccessful();
        $this->assertSame(10, $point->fresh()->sync_status);
        Http::fake(['arbor.test/*' => Http::response(['pointAward' => ['href' => '/rest-v2/point-awards/999']])]);
        $acceptedJob->handle();
        $this->assertSame(0, $point->fresh()->sync_status);
        $this->artisan('writeback:recover-ready --apply --workers-stopped-and-queue-empty')->assertSuccessful();
        $this->assertSame(0, $point->fresh()->sync_status);
        Http::assertSentCount(1);
    }

    public function test_lost_publish_acknowledgement_does_not_overwrite_a_job_already_completed_by_worker(): void
    {
        Http::fake(['arbor.test/*' => Http::response(['pointAward' => ['href' => '/rest-v2/point-awards/999']])]);
        $point = $this->point(['sync_status' => 1]);
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function ($job) {
            $job->handle();
            throw new \RuntimeException('Worker ran but publish acknowledgement was lost');
        });
        $this->artisan('writeback:processAll')->assertFailed();
        $this->assertSame(0, $point->fresh()->sync_status);
        $this->assertNull($point->fresh()->sync_last_error_body);
        $this->assertSame('999', $point->fresh()->arbor_id);
        $this->artisan('writeback:processAll')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_dispatch_failure_does_not_replace_prior_http_error_evidence(): void
    {
        $point = $this->point(['sync_status' => 1, 'sync_last_error_status' => 429, 'sync_last_error_body' => 'Historical Arbor error']);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Redis unavailable'));
        $this->artisan('writeback:processAll')->assertFailed();
        $this->assertSame(10, $point->fresh()->sync_status);
        $this->assertSame(429, $point->fresh()->sync_last_error_status);
        $this->assertSame('Historical Arbor error', $point->fresh()->sync_last_error_body);
    }

    public function test_recovery_preserves_remote_ids_and_selects_the_right_operation(): void
    {
        $point = $this->point(['arbor_id' => '900']);
        $demerit = $this->complexRecord(\App\Models\Demerit::class, ['sync_status' => -10, 'arbor_point_id' => '901']);
        $intervention = $this->complexRecord(\App\Models\Intervention::class, ['sync_status' => -20, 'arbor_behaviour_id' => '902']);
        $this->artisan('writeback:recover-ready --apply --workers-stopped-and-queue-empty')->assertSuccessful();
        $this->assertSame(-2, $point->fresh()->sync_status);
        $this->assertSame('900', $point->fresh()->arbor_id);
        $this->assertSame(-1, $demerit->fresh()->sync_status);
        $this->assertSame('901', $demerit->fresh()->arbor_point_id);
        $this->assertSame(-2, $intervention->fresh()->sync_status);
        $this->assertSame('902', $intervention->fresh()->arbor_behaviour_id);
        Http::assertNothingSent();
    }

    public function test_actual_429_stops_at_the_configured_attempt_cap(): void
    {
        $this->mock(ArborRateLimiter::class, function ($mock) {
            $mock->shouldReceive('waitMilliseconds')->andReturn(0);
            $mock->shouldReceive('cooldown')->with(60);
        });
        Http::fake(['arbor.test/*' => Http::response('Too Many Requests', 429)]);
        $point = $this->point(['sync_attempts' => 9]);
        (new CreatePoint($point->id))->handle();
        $this->assertSame(10, $point->fresh()->sync_attempts);
        $this->assertNull($point->fresh()->sync_next_retry_at);
        Bus::fake();
        $this->artisan('writeback:processAll')->assertSuccessful();
        Bus::assertNothingDispatched();
    }

    public function test_scheduling_retries_preserves_historical_failure_evidence(): void
    {
        $this->freezeTime();
        Bus::fake();
        $historicalFailure = now()->subDay()->toDateTimeString();
        $records = [];
        foreach ([\App\Models\Point::class, \App\Models\Demerit::class, \App\Models\Intervention::class] as $class) {
            foreach ([$class::STATUS_FAILED_CREATION, $class::STATUS_FAILED_UPDATE, $class::STATUS_FAILED_DELETION] as $index => $status) {
                $attributes = [
                    'record_id' => 123 + $index, 'sync_status' => $status, 'sync_attempts' => 2,
                    'sync_last_failed_at' => $historicalFailure, 'sync_next_retry_at' => null,
                    'sync_last_error_status' => 429, 'sync_last_error_body' => 'Historical 429',
                ];
                $records[] = $class === \App\Models\Point::class
                    ? $this->point($attributes) : $this->complexRecord($class, $attributes);
            }
        }

        $this->artisan('writeback:processAll')->assertSuccessful();

        foreach ($records as $record) {
            $record->refresh();
            $this->assertSame($historicalFailure, $record->sync_last_failed_at->toDateTimeString());
            $this->assertSame(429, $record->sync_last_error_status);
            $this->assertSame('Historical 429', $record->sync_last_error_body);
            $this->assertSame(3, $record->sync_attempts);
            $this->assertSame(now()->addSeconds(ArborSyncRetry::delaySeconds(3))->toDateTimeString(), $record->sync_next_retry_at->toDateTimeString());
        }
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_repeated_scheduler_passes_do_not_refresh_failure_timestamps_or_allocate_again(): void
    {
        $this->freezeTime();
        Bus::fake();
        $record = $this->complexRecord(\App\Models\Intervention::class, [
            'sync_status' => 11, 'sync_attempts' => 0, 'sync_last_failed_at' => now()->subDay(),
            'sync_last_error_status' => 429, 'sync_last_error_body' => 'Historical 429',
        ]);
        $historicalFailure = $record->sync_last_failed_at->toDateTimeString();
        $retryAt = now()->addSeconds(ArborSyncRetry::delaySeconds(1))->toDateTimeString();
        for ($i = 0; $i < 3; $i++) {
            $this->artisan('writeback:processAll')->assertSuccessful();
            $record->refresh();
            $this->assertSame($historicalFailure, $record->sync_last_failed_at->toDateTimeString());
            $this->assertSame($retryAt, $record->sync_next_retry_at->toDateTimeString());
            $this->assertSame(1, $record->sync_attempts);
            $this->assertSame(429, $record->sync_last_error_status);
            $this->assertSame('Historical 429', $record->sync_last_error_body);
            $this->travel(10)->seconds();
        }
        Bus::assertNothingDispatched();
        $this->travel(ArborSyncRetry::delaySeconds(1))->seconds();
        $this->artisan('writeback:processAll')->assertSuccessful();
        $record->refresh();
        $this->assertSame(10, $record->sync_status);
        $this->assertSame($historicalFailure, $record->sync_last_failed_at->toDateTimeString());
        $this->assertSame(1, $record->sync_attempts);
        $this->assertSame(429, $record->sync_last_error_status);
        $this->assertSame('Historical 429', $record->sync_last_error_body);
        Bus::assertDispatched(\App\Jobs\CreateIntervention::class);
        Http::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('remoteFailureStatuses')]
    public function test_subsequent_http_failure_updates_timestamp_before_retry_scheduling(int $status): void
    {
        $this->freezeTime();
        if ($status === 429) {
            $this->mock(ArborRateLimiter::class, function ($mock) {
                $mock->shouldReceive('waitMilliseconds')->once()->andReturn(0);
                $mock->shouldReceive('cooldown')->once()->with(60);
            });
        }
        Http::fake(['arbor.test/*' => Http::response('New remote failure', $status)]);
        $point = $this->point([
            'sync_attempts' => 3, 'sync_last_failed_at' => now()->subDay(),
            'sync_last_error_status' => 429, 'sync_last_error_body' => 'Historical 429',
        ]);
        $failedAt = now()->toDateTimeString();

        (new CreatePoint($point->id))->handle();

        $point->refresh();
        $this->assertSame(11, $point->sync_status);
        $this->assertSame($failedAt, $point->sync_last_failed_at->toDateTimeString());
        $this->assertSame($status, $point->sync_last_error_status);
        $this->assertSame('New remote failure', $point->sync_last_error_body);
        $this->assertSame($status === 429 ? 4 : 3, $point->sync_attempts);
        Http::assertSentCount(1);

        $this->travel(1)->seconds();
        Bus::fake();
        $this->artisan('writeback:processAll')->assertSuccessful();
        $point->refresh();
        $this->assertSame($failedAt, $point->sync_last_failed_at->toDateTimeString());
        $this->assertSame($status, $point->sync_last_error_status);
        $this->assertSame('New remote failure', $point->sync_last_error_body);
        $this->assertSame(4, $point->sync_attempts);
        $this->assertNotNull($point->sync_next_retry_at);
        Bus::assertNothingDispatched();
    }

    public static function remoteFailureStatuses(): array
    {
        return ['ordinary HTTP failure' => [500], 'HTTP 429' => [429]];
    }

    public function test_429_preserves_error_and_respects_retry_after_without_repeating_http(): void
    {
        $this->mock(ArborRateLimiter::class, function ($mock) {
            $mock->shouldReceive('waitMilliseconds')->once()->andReturn(0);
            $mock->shouldReceive('cooldown')->once()->with(180);
        });
        Http::fake(['arbor.test/*' => Http::response('Too Many Requests', 429, ['Retry-After' => '180'])]);
        $point = $this->point();
        (new CreatePoint($point->id))->handle();
        $point->refresh();
        $this->assertSame(11, $point->sync_status);
        $this->assertSame(1, $point->sync_attempts);
        $this->assertSame(429, $point->sync_last_error_status);
        $this->assertSame('Too Many Requests', $point->sync_last_error_body);
        $this->assertGreaterThanOrEqual(179, now()->diffInSeconds($point->sync_next_retry_at));
        Http::assertSentCount(1);
        Bus::fake();
        $this->artisan('writeback:processAll')->assertSuccessful();
        Bus::assertNothingDispatched();
        $this->travel(181)->seconds();
        $this->artisan('writeback:processAll')->assertSuccessful();
        Bus::assertDispatched(CreatePoint::class);
        $this->assertSame(10, $point->fresh()->sync_status);
    }

    public function test_long_local_wait_defers_without_http_or_incrementing_failure_attempts(): void
    {
        config(['services.arbor.rate_limit.max_wait_ms' => 0]);
        $this->mock(ArborRateLimiter::class)->shouldReceive('waitMilliseconds')->once()->andReturn(5000);
        $point = $this->point();
        (new CreatePoint($point->id))->handle();
        $point->refresh();
        $this->assertSame(1, $point->sync_status);
        $this->assertSame(0, $point->sync_attempts);
        $this->assertNull($point->sync_last_error_status);
        $this->assertNotNull($point->sync_next_retry_at);
        Http::assertNothingSent();
    }

    public function test_limiter_storage_failure_fails_closed(): void
    {
        $this->mock(ArborRateLimiter::class)->shouldReceive('waitMilliseconds')->andThrow(new \RuntimeException('Redis down'));
        $point = $this->point();
        (new CreatePoint($point->id))->handle();
        $this->assertSame(1, $point->fresh()->sync_status);
        $this->assertSame(0, $point->fresh()->sync_attempts);
        Http::assertNothingSent();
    }

    public function test_repeated_local_deferrals_never_exhaust_attempts_or_erase_last_real_error(): void
    {
        config(['services.arbor.rate_limit.max_wait_ms' => 0, 'services.arbor.sync.max_attempts' => 2]);
        $this->mock(ArborRateLimiter::class)->shouldReceive('waitMilliseconds')->andReturn(5000);
        $point = $this->point([
            'sync_status' => 1, 'sync_attempts' => 1, 'sync_last_failed_at' => now()->subDay(),
            'sync_last_error_status' => 429, 'sync_last_error_body' => 'Previous real 429',
        ]);
        $lastFailure = $point->sync_last_failed_at->toDateTimeString();
        Bus::fake();
        for ($i = 0; $i < 20; $i++) {
            $this->artisan('writeback:processAll')->assertSuccessful();
            $this->assertSame(10, $point->fresh()->sync_status);
            (new CreatePoint($point->id))->handle();
            $point->refresh();
            $this->assertSame(1, $point->sync_status);
            $this->assertSame(1, $point->sync_attempts);
            $this->assertSame($lastFailure, $point->sync_last_failed_at->toDateTimeString());
            $this->assertSame(429, $point->sync_last_error_status);
            $this->assertSame('Previous real 429', $point->sync_last_error_body);
            $this->assertNotNull($point->sync_next_retry_at);
            $this->travel(6)->seconds();
        }
        Http::assertNothingSent();
    }

    public function test_local_deferral_does_not_cancel_an_already_authorised_last_retry_at_cap(): void
    {
        config(['services.arbor.rate_limit.max_wait_ms' => 0, 'services.arbor.sync.max_attempts' => 2]);
        $this->mock(ArborRateLimiter::class)->shouldReceive('waitMilliseconds')->andReturn(5000);
        $point = $this->point(['sync_attempts' => 2]);
        (new CreatePoint($point->id))->handle();
        $this->assertSame(1, $point->fresh()->sync_status);
        $this->assertSame(2, $point->fresh()->sync_attempts);
        $this->assertNotNull($point->fresh()->sync_next_retry_at);
        Http::assertNothingSent();
    }

    public function test_redis_round_trip_time_counts_toward_limiter_wait_budget(): void
    {
        config(['services.arbor.rate_limit.max_wait_ms' => 10]);
        $this->mock(ArborRateLimiter::class)->shouldReceive('waitMilliseconds')->once()->andReturnUsing(function () {
            usleep(20000);

            return 1;
        });
        $point = $this->point();
        (new CreatePoint($point->id))->handle();
        $this->assertSame(1, $point->fresh()->sync_status);
        $this->assertSame(0, $point->fresh()->sync_attempts);
        Http::assertNothingSent();
    }

    public function test_prior_http_failure_is_not_hidden_by_a_later_local_deferral(): void
    {
        config(['services.arbor.rate_limit.max_wait_ms' => 0]);
        $this->mock(ArborRateLimiter::class)->shouldReceive('waitMilliseconds')->andReturn(0, 5000);
        Http::fake(['arbor.test/*' => Http::response('Real server error', 500)]);
        $record = $this->complexRecord(\App\Models\Intervention::class);
        (new \App\Jobs\CreateIntervention($record->id))->handle();
        $record->refresh();
        $this->assertSame(11, $record->sync_status);
        $this->assertSame(1, $record->sync_attempts);
        $this->assertSame(500, $record->sync_last_error_status);
        $this->assertSame('Real server error', $record->sync_last_error_body);
        $this->assertGreaterThanOrEqual(59, now()->diffInSeconds($record->sync_next_retry_at));
        Http::assertSentCount(1);
    }

    public function test_sdk_facade_and_legacy_jobs_share_the_same_fail_closed_boundary(): void
    {
        config(['laravelarbor.url' => 'https://arbor.test', 'laravelarbor.username' => 'test', 'laravelarbor.password' => 'test']);
        $this->mock(ArborRateLimiter::class)->shouldReceive('waitMilliseconds')->andThrow(new \RuntimeException('Redis unavailable'));
        try {
            \ANP\LaravelArbor\Facades\ArborGraphQL::query('query { Student { id } }');
            $this->fail('The SDK must not bypass the limiter.');
        } catch (\App\Support\ArborThrottledException $e) {
            $this->assertNull($e->httpStatus);
        }
        $point = $this->point(['arbor_id' => '900']);
        try {
            (new \App\Jobs\FixPoint($point->id))->handle();
            $this->fail('Legacy repairs must not bypass the limiter.');
        } catch (\App\Support\ArborThrottledException $e) {
            $this->assertNull($e->httpStatus);
        }
        Http::assertNothingSent();
    }

    public function test_graphql_facade_retains_response_api_and_charges_one_call_per_calendar_page(): void
    {
        config(['laravelarbor.url' => 'https://arbor.test', 'laravelarbor.username' => 'test', 'laravelarbor.password' => 'test']);
        $this->mock(ArborRateLimiter::class)->shouldReceive('waitMilliseconds')->once()->andReturn(0);
        Http::fake(['arbor.test/graphql/query' => Http::response(['data' => ['AcademicCalendarDate' => []]])]);
        (new \App\Services\FetchBackgroundDataService)->fetchAcademicCalendarDates(request());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://arbor.test/graphql/query' && $request->method() === 'POST');
    }

    public function test_background_fetch_reports_deferral_when_global_budget_is_busy(): void
    {
        config(['laravelarbor.url' => 'https://arbor.test', 'services.arbor.rate_limit.max_wait_ms' => 0]);
        $this->mock(ArborRateLimiter::class)->shouldReceive('waitMilliseconds')->andReturn(5000);
        $this->artisan('writeback:fetchSystemData')->assertFailed();
        $this->assertSame('deferred', \App\Support\TaskMonitor::lastStatus('writeback:fetchSystemData')['status']);
        Http::assertNothingSent();
    }

    public function test_ready_report_is_read_only_and_filtered_apply_preserves_error_evidence_with_audit_log(): void
    {
        $point = $this->point(['sync_last_error_status' => 429, 'sync_last_error_body' => 'Keep evidence', 'sync_attempts' => 3]);
        $other = $this->point(['record_id' => 124, 'sync_status' => -10]);
        $before = $point->fresh()->getRawOriginal();
        \Illuminate\Support\Facades\Log::spy();
        $this->artisan('writeback:recover-ready', ['--model' => ['points'], '--status' => ['10'], '--id' => [$point->id]])->assertSuccessful();
        $this->assertSame($before, $point->fresh()->getRawOriginal());
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('notice');
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
        $this->artisan('writeback:recover-ready', ['--id' => [$point->id], '--apply' => true, '--workers-stopped-and-queue-empty' => true])->assertFailed();
        \Illuminate\Support\Facades\Log::spy();
        $this->artisan('writeback:recover-ready', [
            '--model' => ['points'], '--status' => ['10'], '--id' => [$point->id],
            '--apply' => true, '--workers-stopped-and-queue-empty' => true,
        ])->assertSuccessful();
        $point->refresh();
        $this->assertSame(1, $point->sync_status);
        $this->assertSame(3, $point->sync_attempts);
        $this->assertSame(429, $point->sync_last_error_status);
        $this->assertSame('Keep evidence', $point->sync_last_error_body);
        $this->assertSame(-10, $other->fresh()->sync_status);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('notice')->once()->withArgs(fn ($message, $context) => $context['id'] === $point->id && $context['from_status'] === 10 && $context['to_status'] === 1 && isset($context['run_id'], $context['operator']));
    }

    public function test_unconfigured_legacy_consent_tool_is_disabled_before_any_http_request(): void
    {
        config(['services.arbor.legacy_consent' => ['url' => null, 'username' => null, 'password' => null]]);
        try {
            (new \App\Http\Controllers\KillConsentTest)(request());
            $this->fail('An unconfigured legacy tool must be disabled.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_standard_http_failure_still_uses_existing_retry_schedule(): void
    {
        Http::fake(['arbor.test/*' => Http::response('Server error', 500)]);
        $point = $this->point();
        (new CreatePoint($point->id))->handle();
        $this->assertSame(11, $point->fresh()->sync_status);
        $this->assertSame(500, $point->fresh()->sync_last_error_status);
        Bus::fake();
        $this->artisan('writeback:processAll')->assertSuccessful();
        $this->assertSame(1, $point->fresh()->sync_attempts);
        $this->assertGreaterThanOrEqual(59, now()->diffInSeconds($point->fresh()->sync_next_retry_at));
        Bus::assertNothingDispatched();
    }

    public function test_create_update_delete_and_duplicate_delivery_keep_status_and_idempotency(): void
    {
        Http::fake(['arbor.test/*' => Http::response(['pointAward' => ['href' => '/rest-v2/point-awards/999']])]);
        $point = $this->point();
        $first = new CreatePoint($point->id);
        $duplicate = new CreatePoint($point->id);
        $first->handle();
        $duplicate->handle();
        $this->assertSame(0, $point->fresh()->sync_status);
        $this->assertSame('999', $point->fresh()->arbor_id);
        Http::assertSentCount(1);
        $point->refresh()->forceFill(['sync_status' => -20])->save();
        (new UpdatePoint($point->id))->handle();
        $this->assertSame(0, $point->fresh()->sync_status);
        $point->refresh()->forceFill(['sync_status' => -10])->save();
        (new DeletePoint($point->id))->handle();
        $this->assertNull($point->fresh());
        Http::assertSentCount(3);
    }

    public function test_retry_header_date_fallback_backoff_jitter_and_attempt_cap(): void
    {
        $this->freezeTime();
        $this->assertSame(120, ArborSyncRetry::retryAfter(now()->addSeconds(120)->toRfc7231String()));
        $this->assertSame(60, ArborSyncRetry::retryAfter('invalid'));
        $this->assertSame(60, ArborSyncRetry::retryAfter(null));
        $this->assertSame(180, ArborSyncRetry::delaySeconds(2));
        config(['services.arbor.sync.retry_jitter_percent' => 0.15]);
        for ($i = 0; $i < 30; $i++) {
            $this->assertGreaterThanOrEqual(153, $delay = ArborSyncRetry::delaySeconds(2));
            $this->assertLessThanOrEqual(207, $delay);
        }
        $point = $this->point(['sync_status' => 11, 'sync_attempts' => 10]);
        Bus::fake();
        $this->artisan('writeback:processAll')->assertSuccessful();
        Bus::assertNothingDispatched();
        $this->assertNull($point->fresh()->sync_next_retry_at);
    }

    public static function complexJobs(): array
    {
        return [
            [\App\Models\Demerit::class, \App\Jobs\CreateDemerit::class, \App\Jobs\UpdateDemerit::class, \App\Jobs\DeleteDemerit::class],
            [\App\Models\Intervention::class, \App\Jobs\CreateIntervention::class, \App\Jobs\UpdateIntervention::class, \App\Jobs\DeleteIntervention::class],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('complexJobs')]
    public function test_partial_create_keeps_all_known_ids_and_update_does_not_recreate_resources(string $model, string $create, string $update): void
    {
        $this->mock(ArborRateLimiter::class, function ($mock) {
            $mock->shouldReceive('waitMilliseconds')->andReturn(0);
            $mock->shouldReceive('cooldown')->once()->with(120);
        });
        $calls = 0;
        Http::fake(['arbor.test/*' => function ($request) use (&$calls) {
            $calls++;
            if ($calls === 5) {
                return Http::response('Too Many Requests', 429, ['Retry-After' => '120']);
            }
            $path = parse_url($request->url(), PHP_URL_PATH);
            $map = [
                'point-awards' => 'pointAward', 'behavioural-incidents' => 'behaviouralIncident',
                'behavioural-incident-student-involvements' => 'behaviouralIncidentStudentInvolvement',
                'behavioural-incident-actions' => 'behaviouralIncidentAction',
            ];
            $resource = explode('/', trim($path, '/'))[1];

            return Http::response([$map[$resource] => ['href' => '/rest-v2/'.$resource.'/'.($resource === 'behavioural-incident-actions' ? 400 + $calls : 100)]]);
        }]);
        $record = $this->complexRecord($model);
        (new $create($record->id))->handle();
        $record->refresh();
        $this->assertSame(-21, $record->sync_status);
        $this->assertSame('100', $record->arbor_point_id);
        $this->assertSame('100', $record->arbor_behaviour_id);
        $this->assertSame('100', $record->arbor_behaviour_student_id);
        $this->assertNotEmpty($record->arbor_action_id['behaviourAction2']);
        $this->assertSame(429, $record->sync_last_error_status);
        $record->forceFill(['sync_status' => -20])->save();
        (new $update($record->id))->handle();
        $this->assertSame(0, $record->fresh()->sync_status);
        $posts = Http::recorded(fn ($request) => $request->method() === 'POST')->map(fn ($pair) => $pair[0]->url());
        $this->assertSame(1, $posts->filter(fn ($url) => str_ends_with($url, '/point-awards'))->count());
        $this->assertSame(1, $posts->filter(fn ($url) => str_ends_with($url, '/behavioural-incidents'))->count());
        $this->assertSame(1, $posts->filter(fn ($url) => str_ends_with($url, '/behavioural-incident-student-involvements'))->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('complexJobs')]
    public function test_partial_delete_keeps_only_remaining_resources_for_retry(string $model, string $create, string $update, string $delete): void
    {
        $this->mock(ArborRateLimiter::class, function ($mock) {
            $mock->shouldReceive('waitMilliseconds')->andReturn(0);
            $mock->shouldReceive('cooldown')->once()->with(60);
        });
        Http::fake(['arbor.test/*' => Http::sequence()->push('', 204)->push('Too Many Requests', 429)->push('', 204)->push('', 204)]);
        $record = $this->complexRecord($model, [
            'sync_status' => -10, 'arbor_action_id' => ['first' => 1, 'second' => 2], 'arbor_point_id' => '3',
        ]);
        (new $delete($record->id))->handle();
        $record->refresh();
        $this->assertSame(-11, $record->sync_status);
        $this->assertEmpty($record->arbor_action_id['first']);
        $this->assertSame(2, $record->arbor_action_id['second']);
        $this->assertSame('3', $record->arbor_point_id);
        $record->forceFill(['sync_status' => -10])->save();
        (new $delete($record->id))->handle();
        $this->assertNull($record->fresh());
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/behavioural-incident-actions/1')));
    }
}

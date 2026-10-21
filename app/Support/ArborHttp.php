<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Softonic\GraphQL\Client;
use Softonic\GraphQL\ClientBuilder;

class ArborHttp
{
    /**
     * Standardised Arbor HTTP client with per-request rate limiting.
     */
    public static function client(int $weight = 1): PendingRequest
    {
        return Http::baseUrl(config('services.arbor.base_url'))
            ->withBasicAuth(config('services.arbor.username'), config('services.arbor.password'))
            ->acceptJson()
            ->asJson()
            ->withoutRedirecting()
            ->connectTimeout((int) config('services.arbor.rate_limit.connect_timeout_seconds', 5))
            ->timeout((int) config('services.arbor.rate_limit.request_timeout_seconds', 15))
            ->withMiddleware(self::middleware($weight));
    }

    /** Also wraps non-Laravel HTTP clients, including the existing GraphQL SDK. */
    public static function middleware(int $weight = 1): callable
    {
        return static fn (callable $handler) => static function (RequestInterface $request, array $options) use ($handler, $weight) {
            ArborRateLimiter::consume($weight);

            return $handler($request, $options)->then(static function (ResponseInterface $response) {
                if ($response->getStatusCode() !== 429) {
                    return $response;
                }
                $delay = ArborSyncRetry::retryAfter($response->getHeaderLine('Retry-After'));
                try {
                    app(ArborRateLimiter::class)->cooldown($delay);
                } catch (\Throwable $e) {
                    Log::error('Could not publish Arbor 429 cooldown', ['exception' => $e::class]);
                }
                Log::warning('Arbor HTTP 429; shared writeback cooldown', ['retry_after_seconds' => $delay]);
                throw new ArborThrottledException($delay, 429, substr((string) $response->getBody(), 0, 4000));
            });
        };
    }

    public static function graphQlClient(): Client
    {
        // Retain the SDK's configuration and response API; replace only its HTTP transport.
        // Laravel's stack also lets Http::fake() protect tests from real SDK traffic.
        return ClientBuilder::build(rtrim((string) config('laravelarbor.url'), '/').'/graphql/query', [
            'handler' => self::client()->buildHandlerStack(),
            // Laravel's public handler stack expects this request-observer metadata option.
            // The SDK still owns and sends its original JSON body, without re-encoding it.
            'laravel_data' => [],
            'on_stats' => static function (\GuzzleHttp\TransferStats $stats): void {},
            'auth' => [config('laravelarbor.username'), config('laravelarbor.password')],
            'allow_redirects' => false,
            'connect_timeout' => (int) config('services.arbor.rate_limit.connect_timeout_seconds', 5),
            'timeout' => (int) config('services.arbor.rate_limit.request_timeout_seconds', 15),
        ]);
    }
}

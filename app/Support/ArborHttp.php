<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

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
            ->beforeSending(function ($request, $options) use ($weight) {
                ArborRateLimiter::consume($weight);
            });
    }
}

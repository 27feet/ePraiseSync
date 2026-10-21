<?php

namespace App\Support;

class ArborThrottledException extends \RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        public readonly ?int $httpStatus,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

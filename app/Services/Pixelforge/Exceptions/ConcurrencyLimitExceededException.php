<?php

namespace App\Services\Pixelforge\Exceptions;

class ConcurrencyLimitExceededException extends PixelforgeException
{
    public function __construct(
        string $message = 'Too many generations queued or processing.',
        int $retryAfter = 5,
        int $statusCode = 429,
    ) {
        parent::__construct($message, $statusCode, 'concurrency_limit_exceeded', $retryAfter);
    }
}

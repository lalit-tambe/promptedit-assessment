<?php

namespace App\Services\Pixelforge\Exceptions;

class RateLimitExceededException extends PixelforgeException
{
    public function __construct(
        string $message = 'Too many requests this minute.',
        int $retryAfter = 60,
        int $statusCode = 429,
    ) {
        parent::__construct($message, $statusCode, 'rate_limit_exceeded', $retryAfter);
    }
}

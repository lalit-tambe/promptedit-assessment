<?php

namespace App\Services\Pixelforge\Exceptions;

class DailyLimitExceededException extends PixelforgeException
{
    public function __construct(
        string $message = 'The daily generation quota (UTC) is used up.',
        int $statusCode = 429,
    ) {
        parent::__construct($message, $statusCode, 'daily_limit_exceeded');
    }
}

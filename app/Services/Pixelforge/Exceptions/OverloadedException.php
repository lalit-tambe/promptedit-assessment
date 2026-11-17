<?php

namespace App\Services\Pixelforge\Exceptions;

class OverloadedException extends PixelforgeException
{
    public function __construct(
        string $message = 'Pixelforge is temporarily overloaded.',
        int $retryAfter = 2,
        int $statusCode = 503,
    ) {
        parent::__construct($message, $statusCode, 'overloaded', $retryAfter);
    }
}

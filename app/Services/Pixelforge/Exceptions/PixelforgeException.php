<?php

namespace App\Services\Pixelforge\Exceptions;

use RuntimeException;

class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 500,
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}

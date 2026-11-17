<?php

namespace App\Services\Pixelforge\Exceptions;

class InvalidApiKeyException extends PixelforgeException
{
    public function __construct(
        string $message = 'The API key is missing, malformed or revoked.',
        int $statusCode = 401,
    ) {
        parent::__construct($message, $statusCode, 'invalid_api_key');
    }
}

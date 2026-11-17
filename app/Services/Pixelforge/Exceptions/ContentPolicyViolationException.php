<?php

namespace App\Services\Pixelforge\Exceptions;

class ContentPolicyViolationException extends PixelforgeException
{
    public function __construct(
        string $message = 'The prompt was rejected.',
        int $statusCode = 400,
    ) {
        parent::__construct($message, $statusCode, 'content_policy_violation');
    }
}

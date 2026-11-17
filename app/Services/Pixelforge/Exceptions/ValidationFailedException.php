<?php

namespace App\Services\Pixelforge\Exceptions;

class ValidationFailedException extends PixelforgeException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        string $message = 'Validation failed.',
        int $statusCode = 422,
        public readonly array $errors = [],
        public readonly ?string $param = null,
    ) {
        parent::__construct($message, $statusCode, 'validation_failed');
    }
}

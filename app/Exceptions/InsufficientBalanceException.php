<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(
        public readonly string $availableBalance,
        public readonly string $estimatedCost,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : 'Insufficient credit balance.');
    }
}

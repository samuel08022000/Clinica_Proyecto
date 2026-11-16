<?php

namespace App\Exceptions;

use RuntimeException;

final class AuthDomainException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status = 422,
        string $message = 'No fue posible completar la operación.',
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}

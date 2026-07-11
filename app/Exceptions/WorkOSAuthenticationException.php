<?php

namespace App\Exceptions;

use RuntimeException;

class WorkOSAuthenticationException extends RuntimeException
{
    public function __construct(
        public readonly string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }
}

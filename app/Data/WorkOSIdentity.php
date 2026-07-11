<?php

namespace App\Data;

readonly class WorkOSIdentity
{
    public function __construct(
        public string $id,
        public string $email,
        public bool $emailVerified,
        public ?string $name,
        public ?int $authenticatedAt = null,
    ) {}
}

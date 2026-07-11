<?php

namespace App\Data;

use App\Models\User;

readonly class WorkOSResolution
{
    public function __construct(
        public User $user,
        public bool $linked,
        public bool $created = false,
    ) {}
}

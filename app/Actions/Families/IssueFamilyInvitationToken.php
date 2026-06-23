<?php

namespace App\Actions\Families;

use Illuminate\Support\Str;

class IssueFamilyInvitationToken
{
    /**
     * Generate a cryptographically random invitation token.
     */
    public function execute(): string
    {
        return Str::random(64);
    }
}

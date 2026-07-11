<?php

namespace App\Contracts;

use App\Data\WorkOSIdentity;

interface WorkOSAuthenticator
{
    public function authorizationUrl(string $redirectUri, string $state, ?int $maxAge = null): string;

    public function authenticate(string $code, ?string $ipAddress, ?string $userAgent): WorkOSIdentity;
}

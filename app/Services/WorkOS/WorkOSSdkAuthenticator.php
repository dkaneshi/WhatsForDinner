<?php

namespace App\Services\WorkOS;

use App\Contracts\WorkOSAuthenticator;
use App\Data\WorkOSIdentity;
use Illuminate\Support\Uri;
use JsonException;
use RuntimeException;
use WorkOS\Resource\UserManagementAuthenticationProvider;
use WorkOS\WorkOS;

class WorkOSSdkAuthenticator implements WorkOSAuthenticator
{
    public function authorizationUrl(string $redirectUri, string $state, ?int $maxAge = null): string
    {
        $authorizationUrl = $this->client()->userManagement()->getAuthorizationUrl(
            redirectUri: $redirectUri,
            provider: UserManagementAuthenticationProvider::Authkit,
            state: $state,
        );

        return $maxAge === null
            ? $authorizationUrl
            : Uri::of($authorizationUrl)->withQuery(['max_age' => $maxAge])->value();
    }

    public function authenticate(string $code, ?string $ipAddress, ?string $userAgent): WorkOSIdentity
    {
        $response = $this->client()->userManagement()->authenticateWithCode(
            code: $code,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );

        return new WorkOSIdentity(
            id: $response->user->id,
            email: $response->user->email,
            emailVerified: $response->user->emailVerified,
            name: $response->user->name,
            authenticatedAt: $this->authenticationTime($response->accessToken),
        );
    }

    private function authenticationTime(string $accessToken): ?int
    {
        $segments = explode('.', $accessToken);

        if (count($segments) !== 3) {
            return null;
        }

        $payloadSegment = strtr($segments[1], '-_', '+/');
        $payloadSegment = str_pad($payloadSegment, (int) ceil(strlen($payloadSegment) / 4) * 4, '=');
        $payload = base64_decode($payloadSegment, strict: true);

        if (! is_string($payload)) {
            return null;
        }

        try {
            $claims = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($claims) && is_numeric($claims['auth_time'] ?? null)
            ? (int) $claims['auth_time']
            : null;
    }

    private function client(): WorkOS
    {
        $apiKey = config('workos.api_key');
        $clientId = config('workos.client_id');

        if (! is_string($apiKey) || $apiKey === '' || ! is_string($clientId) || $clientId === '') {
            throw new RuntimeException('WorkOS is not configured.');
        }

        return new WorkOS(
            apiKey: $apiKey,
            clientId: $clientId,
            baseUrl: config('workos.api_base_url', 'https://api.workos.com'),
            timeout: 10,
            maxRetries: 2,
        );
    }
}

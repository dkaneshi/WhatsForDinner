<?php

use App\Contracts\WorkOSAuthenticator;
use App\Data\WorkOSIdentity;
use App\Models\User;
use App\Services\WorkOS\WorkOSSdkAuthenticator;
use Laravel\Fortify\Features;

beforeEach(function () {
    config([
        'app.url' => 'https://whats-for-dinner.test',
        'workos.redirect_uri' => 'https://whats-for-dinner.test/auth/workos/callback',
    ]);

    $this->workOS = new class implements WorkOSAuthenticator
    {
        public WorkOSIdentity $identity;

        public bool $failAuthorization = false;

        public bool $failAuthentication = false;

        public ?int $authorizationMaxAge = null;

        public function __construct()
        {
            $this->identity = new WorkOSIdentity(
                id: 'user_workos_123',
                email: 'social@example.com',
                emailVerified: true,
                name: 'Social User',
                authenticatedAt: now()->getTimestamp(),
            );
        }

        public function authorizationUrl(string $redirectUri, string $state, ?int $maxAge = null): string
        {
            if ($this->failAuthorization) {
                throw new RuntimeException('API key sk_secret must never be rendered.');
            }

            $this->authorizationMaxAge = $maxAge;

            return 'https://authkit.example.test/authorize?'.http_build_query(array_filter([
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'max_age' => $maxAge,
            ], fn (mixed $value): bool => $value !== null));
        }

        public function authenticate(string $code, ?string $ipAddress, ?string $userAgent): WorkOSIdentity
        {
            if ($this->failAuthentication) {
                throw new RuntimeException('Authorization code '.$code.' must never be rendered.');
            }

            return $this->identity;
        }
    };

    app()->instance(WorkOSAuthenticator::class, $this->workOS);

    $this->beginWorkOSLogin = function (): string {
        $response = $this->get(route('workos.redirect'));
        $location = $response->headers->get('Location');

        $response->assertRedirectContains('https://authkit.example.test/authorize');

        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $query['state'];
    };

    $this->beginWorkOSLink = function (User $user): string {
        $response = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('workos.link'));
        $location = $response->headers->get('Location');

        $response->assertRedirectContains('https://authkit.example.test/authorize');

        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $query['state'];
    };

    $this->beginWorkOSConfirm = function (User $user, string $intended = '/settings/security'): string {
        $response = $this->actingAs($user)
            ->withSession(['url.intended' => $intended])
            ->post(route('workos.confirm'));
        $location = $response->headers->get('Location');

        $response->assertRedirectContains('https://authkit.example.test/authorize');

        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $query['state'];
    };
});

test('login and registration expose the hosted social login entry point', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Continue with Google, GitHub, or Apple');

    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Continue with Google, GitHub, or Apple');
});

test('starting social authentication stores hashed expiring state and uses the configured callback', function () {
    $state = ($this->beginWorkOSLogin)();
    $pending = session('workos.authentication');

    expect($pending)
        ->toMatchArray([
            'state_hash' => hash('sha256', $state),
            'intent' => 'login',
            'user_id' => null,
            'intended' => '/dashboard',
        ])
        ->and($pending['expires_at'])->toBeGreaterThan(now()->getTimestamp());
});

test('a new verified WorkOS user is created and authenticated', function () {
    $state = ($this->beginWorkOSLogin)();

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'valid-code']))
        ->assertRedirect('/dashboard');

    $user = User::query()->where('workos_user_id', 'user_workos_123')->firstOrFail();

    expect($user->email)->toBe('social@example.com')
        ->and($user->name)->toBe('Social User')
        ->and($user->hasVerifiedEmail())->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

test('an Apple private relay address may create a new account', function () {
    $this->workOS->identity = new WorkOSIdentity(
        id: 'user_apple_123',
        email: 'private@privaterelay.appleid.com',
        emailVerified: true,
        name: null,
    );

    $state = ($this->beginWorkOSLogin)();

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'apple-code']))
        ->assertRedirect('/dashboard');

    $user = User::query()->where('workos_user_id', 'user_apple_123')->firstOrFail();

    expect($user->email)->toBe('private@privaterelay.appleid.com')
        ->and($user->name)->toBe('private');
});

test('a returning WorkOS user is authenticated without provider profile overwrites', function () {
    $user = User::factory()->create([
        'name' => 'Local Name',
        'email' => 'local@example.com',
        'workos_user_id' => 'user_workos_123',
    ]);

    $state = ($this->beginWorkOSLogin)();

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'valid-code']))
        ->assertRedirect('/dashboard');

    expect($user->refresh()->name)->toBe('Local Name')
        ->and($user->email)->toBe('local@example.com');
    $this->assertAuthenticatedAs($user);
});

test('an unlinked matching email cannot authenticate or silently link', function () {
    $user = User::factory()->create(['email' => 'social@example.com']);
    $state = ($this->beginWorkOSLogin)();

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'valid-code']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('workos');

    expect($user->refresh()->workos_user_id)->toBeNull()
        ->and(User::query()->count())->toBe(1);
    $this->assertGuest();
});

test('an unverified WorkOS email is rejected', function () {
    $this->workOS->identity = new WorkOSIdentity(
        id: 'user_unverified',
        email: 'unverified@example.com',
        emailVerified: false,
        name: 'Unverified User',
    );
    $state = ($this->beginWorkOSLogin)();

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'valid-code']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('workos');

    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
});

test('state is session bound single use and replay resistant', function () {
    $state = ($this->beginWorkOSLogin)();

    $this->get(route('workos.callback', ['state' => 'wrong-state', 'code' => 'secret-code']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('workos');

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'secret-code']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('workos');

    expect(User::query()->count())->toBe(0);
});

test('expired state is rejected', function () {
    $state = ($this->beginWorkOSLogin)();
    $pending = session('workos.authentication');
    $pending['expires_at'] = now()->subSecond()->getTimestamp();

    $this->withSession(['workos.authentication' => $pending])
        ->get(route('workos.callback', ['state' => $state, 'code' => 'secret-code']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('workos');
});

test('provider cancellation and API failure expose no sensitive details', function () {
    $state = ($this->beginWorkOSLogin)();

    $this->get(route('workos.callback', ['state' => $state, 'error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('workos')
        ->assertSessionMissing('workos.authentication');

    $this->workOS->failAuthentication = true;
    $state = ($this->beginWorkOSLogin)();

    $response = $this->get(route('workos.callback', ['state' => $state, 'code' => 'secret-code']));

    $response->assertRedirect(route('login'))
        ->assertSessionHasErrors('workos');

    expect(session('errors')->first('workos'))
        ->not->toContain('secret-code')
        ->not->toContain('sk_secret');
});

test('only a safe local intended destination is restored', function () {
    $state = $this->withSession(['url.intended' => 'https://evil.example/steal'])
        ->get(route('workos.redirect'))
        ->headers->get('Location');
    parse_str(parse_url($state, PHP_URL_QUERY), $unsafeQuery);

    $this->get(route('workos.callback', ['state' => $unsafeQuery['state'], 'code' => 'valid-code']))
        ->assertRedirect('/dashboard');

    auth()->logout();

    $location = $this->withSession(['url.intended' => 'https://whats-for-dinner.test/families?tab=members'])
        ->get(route('workos.redirect'))
        ->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $safeQuery);

    $this->get(route('workos.callback', ['state' => $safeQuery['state'], 'code' => 'valid-code']))
        ->assertRedirect('/families?tab=members');
});

test('link initiation requires authentication verification and security confirmation', function () {
    $this->post(route('workos.link'))->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified)->post(route('workos.link'))->assertRedirect(route('verification.notice'));

    $verified = User::factory()->create();
    $this->actingAs($verified)->post(route('workos.link'))->assertRedirect(route('password.confirm'));
});

test('an authenticated user can explicitly link an exact verified email', function () {
    $user = User::factory()->create(['email' => 'Social@Example.com']);
    $state = ($this->beginWorkOSLink)($user);

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'valid-code']))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('status');

    expect($user->refresh()->workos_user_id)->toBe('user_workos_123');
    $this->assertAuthenticatedAs($user);
});

test('a linking callback cannot switch users or replace an existing WorkOS identity', function () {
    $firstUser = User::factory()->create(['email' => 'social@example.com']);
    $state = ($this->beginWorkOSLink)($firstUser);
    $secondUser = User::factory()->create(['email' => 'social@example.net']);

    $this->actingAs($secondUser)
        ->get(route('workos.callback', ['state' => $state, 'code' => 'valid-code']))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHasErrors('workos');

    expect($firstUser->refresh()->workos_user_id)->toBeNull();

    $firstUser->forceFill(['workos_user_id' => 'user_original'])->save();
    $state = ($this->beginWorkOSLink)($firstUser);

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'valid-code']))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHasErrors('workos');

    expect($firstUser->refresh()->workos_user_id)->toBe('user_original');
});

test('linking rejects mismatched Apple relay email and an identity owned by another user', function () {
    $user = User::factory()->create(['email' => 'person@example.com']);
    $this->workOS->identity = new WorkOSIdentity(
        id: 'user_apple_private',
        email: 'private@privaterelay.appleid.com',
        emailVerified: true,
        name: null,
    );
    $state = ($this->beginWorkOSLink)($user);

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'apple-code']))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHasErrors('workos');

    expect($user->refresh()->workos_user_id)->toBeNull();

    $owner = User::factory()->create([
        'email' => 'owner@example.com',
        'workos_user_id' => 'user_claimed',
    ]);
    $user->update(['email' => 'social@example.com']);
    $this->workOS->identity = new WorkOSIdentity('user_claimed', 'social@example.com', true, 'Social User');
    $state = ($this->beginWorkOSLink)($user);

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'valid-code']))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHasErrors('workos');

    expect($user->refresh()->workos_user_id)->toBeNull()
        ->and($owner->refresh()->workos_user_id)->toBe('user_claimed');
});

test('linked users with Fortify TOTP must complete the existing challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create([
        'email' => 'social@example.com',
        'workos_user_id' => 'user_workos_123',
    ]);
    $state = ($this->beginWorkOSLogin)();

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'valid-code']))
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

test('the security page shows WorkOS linking state', function () {
    $unlinked = User::factory()->create();

    $this->actingAs($unlinked)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Link social sign-in');

    $linked = User::factory()->create(['workos_user_id' => 'user_linked']);

    $this->actingAs($linked)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Social sign-in')
        ->assertSee('Linked')
        ->assertDontSee('Link social sign-in');
});

test('the confirmation page offers WorkOS only to linked users', function () {
    $unlinked = User::factory()->create();

    $this->actingAs($unlinked)
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertSee('Confirm your identity')
        ->assertDontSee('Confirm with Google, GitHub, or Apple');

    $linked = User::factory()->create(['workos_user_id' => 'user_workos_123']);

    $this->actingAs($linked)
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertSee('Confirm with Google, GitHub, or Apple');
});

test('starting WorkOS confirmation requires a verified linked user and forces fresh authentication', function () {
    $this->post(route('workos.confirm'))->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create(['workos_user_id' => 'user_unverified']);
    $this->actingAs($unverified)
        ->post(route('workos.confirm'))
        ->assertRedirect(route('verification.notice'));

    $unlinked = User::factory()->create();
    $this->actingAs($unlinked)
        ->post(route('workos.confirm'))
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors('workos');

    $linked = User::factory()->create(['workos_user_id' => 'user_workos_123']);
    $state = ($this->beginWorkOSConfirm)($linked);
    $pending = session('workos.authentication');

    expect($this->workOS->authorizationMaxAge)->toBe(0)
        ->and($pending)->toMatchArray([
            'state_hash' => hash('sha256', $state),
            'intent' => 'confirm',
            'user_id' => $linked->id,
            'intended' => '/settings/security',
        ]);
});

test('the WorkOS SDK adapter includes max age in reauthentication URLs', function () {
    config([
        'workos.api_key' => 'sk_test_example',
        'workos.client_id' => 'client_test_example',
    ]);

    $url = (new WorkOSSdkAuthenticator)->authorizationUrl(
        redirectUri: 'https://whats-for-dinner.test/auth/workos/callback',
        state: 'state-value',
        maxAge: 0,
    );
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    expect($query['max_age'])->toBe('0')
        ->and($query['provider'])->toBe('authkit')
        ->and($query['state'])->toBe('state-value');
});

test('fresh WorkOS reauthentication satisfies Laravel confirmation and restores the intended page', function () {
    $user = User::factory()->create(['workos_user_id' => 'user_workos_123']);
    $state = ($this->beginWorkOSConfirm)($user);

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'fresh-code']))
        ->assertRedirect('/settings/security')
        ->assertSessionHas('auth.password_confirmed_at');

    $this->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Security settings');
});

test('WorkOS confirmation rejects stale unverified mismatched and switched identities', function () {
    $user = User::factory()->create(['workos_user_id' => 'user_workos_123']);
    $this->workOS->identity = new WorkOSIdentity(
        id: 'user_workos_123',
        email: 'social@example.com',
        emailVerified: true,
        name: 'Social User',
        authenticatedAt: now()->subHour()->getTimestamp(),
    );
    $state = ($this->beginWorkOSConfirm)($user);

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'stale-code']))
        ->assertRedirect(route('password.confirm'))
        ->assertSessionMissing('auth.password_confirmed_at');

    $this->workOS->identity = new WorkOSIdentity(
        id: 'different_workos_user',
        email: 'social@example.com',
        emailVerified: false,
        name: 'Different User',
        authenticatedAt: now()->getTimestamp(),
    );
    $state = ($this->beginWorkOSConfirm)($user);

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'wrong-code']))
        ->assertRedirect(route('password.confirm'))
        ->assertSessionMissing('auth.password_confirmed_at');

    $this->workOS->identity = new WorkOSIdentity(
        id: 'user_workos_123',
        email: 'social@example.com',
        emailVerified: true,
        name: 'Social User',
        authenticatedAt: now()->getTimestamp(),
    );
    $state = ($this->beginWorkOSConfirm)($user);
    $differentUser = User::factory()->create(['workos_user_id' => 'different_workos_user']);

    $this->actingAs($differentUser)
        ->get(route('workos.callback', ['state' => $state, 'code' => 'switched-code']))
        ->assertRedirect(route('password.confirm'))
        ->assertSessionMissing('auth.password_confirmed_at');
});

test('WorkOS confirmation does not restore an external intended destination', function () {
    $user = User::factory()->create(['workos_user_id' => 'user_workos_123']);
    $state = ($this->beginWorkOSConfirm)($user, 'https://evil.example/steal');

    $this->get(route('workos.callback', ['state' => $state, 'code' => 'fresh-code']))
        ->assertRedirect(route('security.edit', absolute: false))
        ->assertSessionHas('auth.password_confirmed_at');
});

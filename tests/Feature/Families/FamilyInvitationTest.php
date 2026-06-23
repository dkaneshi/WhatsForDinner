<?php

use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use App\Notifications\FamilyInvitationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 * @return array{FamilyInvitation, string}
 */
function invitationWithToken(Family $family, string $email, array $attributes = []): array
{
    $token = Str::random(64);

    $invitation = FamilyInvitation::factory()->create([
        'family_id' => $family->id,
        'invited_by_user_id' => $family->head_user_id,
        'email' => Str::lower($email),
        'token_hash' => FamilyInvitation::hashToken($token),
        ...$attributes,
    ]);

    return [$invitation, $token];
}

test('only the head can visit family invitation management', function () {
    $head = User::factory()->create();
    $member = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $family->members()->attach($member);
    $member->forceFill(['current_family_id' => $family->id])->save();

    $this->actingAs($head)
        ->get(route('family-invitations.index'))
        ->assertOk();

    $this->actingAs($member)
        ->get(route('family-invitations.index'))
        ->assertForbidden();
});

test('users without a family are redirected to family setup', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('family-invitations.index'))
        ->assertRedirect(route('families.index'));
});

test('the head can send a seven day email invitation', function () {
    Carbon::setTestNow('2026-06-22 12:00:00');

    $head = User::factory()->create();
    Family::factory()->for($head, 'head')->create();

    Notification::fake();

    Livewire::actingAs($head)
        ->test('pages::family-invitations')
        ->set('email', ' Guest@Example.com ')
        ->call('sendInvitation')
        ->assertHasNoErrors()
        ->assertSet('email', '');

    $invitation = FamilyInvitation::query()->sole();

    expect($invitation->email)->toBe('guest@example.com')
        ->and($invitation->expires_at->equalTo(now()->addDays(7)))->toBeTrue()
        ->and($invitation->isPending())->toBeTrue();

    Notification::assertSentOnDemand(
        FamilyInvitationNotification::class,
        function (FamilyInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($invitation): bool {
            $mail = $notification->toMail($notifiable);

            return $channels === ['mail']
                && $notifiable->routes['mail'] === 'guest@example.com'
                && $notification instanceof ShouldQueue
                && $notification->invitation->is($invitation)
                && $invitation->token_hash === FamilyInvitation::hashToken($notification->token)
                && $invitation->token_hash !== $notification->token
                && str_contains($mail->actionUrl, $notification->token);
        },
    );
});

test('a member cannot send invitations', function () {
    $head = User::factory()->create();
    $member = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $family->members()->attach($member);
    $member->forceFill(['current_family_id' => $family->id])->save();

    Notification::fake();

    Livewire::actingAs($member)
        ->test('pages::family-invitations')
        ->assertForbidden();

    expect(FamilyInvitation::query()->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('an existing family member cannot be invited', function () {
    $head = User::factory()->create();
    $member = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $family->members()->attach($member);

    Notification::fake();

    Livewire::actingAs($head)
        ->test('pages::family-invitations')
        ->set('email', Str::upper($member->email))
        ->call('sendInvitation')
        ->assertHasErrors(['email']);

    expect(FamilyInvitation::query()->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('resending replaces the token and invalidates the previous link', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    [$invitation, $oldToken] = invitationWithToken($family, 'guest@example.com');
    $oldHash = $invitation->token_hash;

    Notification::fake();

    Livewire::actingAs($head)
        ->test('pages::family-invitations')
        ->call('resendInvitation', $invitation->id)
        ->assertHasNoErrors();

    expect($invitation->refresh()->token_hash)->not->toBe($oldHash)
        ->and(FamilyInvitation::findForToken($oldToken))->toBeNull();

    Notification::assertSentOnDemandTimes(FamilyInvitationNotification::class, 1);
});

test('the head can revoke a pending invitation', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    [$invitation, $token] = invitationWithToken($family, 'guest@example.com');

    Livewire::actingAs($head)
        ->test('pages::family-invitations')
        ->call('revokeInvitation', $invitation->id)
        ->assertHasNoErrors();

    expect($invitation->refresh()->revoked_at)->not->toBeNull()
        ->and($invitation->isPending())->toBeFalse();

    $recipient = User::factory()->create(['email' => 'guest@example.com']);

    Livewire::actingAs($recipient)
        ->test('pages::family-invitation', ['token' => $token])
        ->assertSee('Invitation unavailable');
});

test('a matching verified user can accept an invitation once', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $recipient = User::factory()->create(['email' => 'guest@example.com']);
    [$invitation, $token] = invitationWithToken($family, $recipient->email);

    Livewire::actingAs($recipient)
        ->test('pages::family-invitation', ['token' => $token])
        ->assertSee($family->name)
        ->call('acceptInvitation')
        ->assertHasNoErrors()
        ->assertRedirect(route('families.index'));

    expect($family->members()->whereKey($recipient->id)->exists())->toBeTrue()
        ->and($recipient->refresh()->current_family_id)->toBe($family->id)
        ->and($invitation->refresh()->accepted_at)->not->toBeNull();

    Livewire::actingAs($recipient)
        ->test('pages::family-invitation', ['token' => $token])
        ->call('acceptInvitation')
        ->assertHasErrors(['invitation']);

    expect($family->members()->whereKey($recipient->id)->count())->toBe(1);
});

test('accepting an invitation does not replace an existing active family', function () {
    $recipient = User::factory()->create(['email' => 'guest@example.com']);
    $existingFamily = Family::factory()->for($recipient, 'head')->create();
    $invitingFamily = Family::factory()->create();
    [, $token] = invitationWithToken($invitingFamily, $recipient->email);

    Livewire::actingAs($recipient)
        ->test('pages::family-invitation', ['token' => $token])
        ->call('acceptInvitation')
        ->assertHasNoErrors();

    expect($recipient->refresh()->current_family_id)->toBe($existingFamily->id)
        ->and($invitingFamily->members()->whereKey($recipient->id)->exists())->toBeTrue();
});

test('a user signed in with another email cannot accept an invitation', function () {
    $family = Family::factory()->create();
    $wrongUser = User::factory()->create(['email' => 'wrong@example.com']);
    [, $token] = invitationWithToken($family, 'guest@example.com');

    Livewire::actingAs($wrongUser)
        ->test('pages::family-invitation', ['token' => $token])
        ->assertSee('Different email required')
        ->call('acceptInvitation')
        ->assertForbidden();

    expect($family->members()->whereKey($wrongUser->id)->exists())->toBeFalse();
});

test('expired invitations cannot be accepted', function () {
    $family = Family::factory()->create();
    $recipient = User::factory()->create(['email' => 'guest@example.com']);
    [, $token] = invitationWithToken($family, $recipient->email, [
        'expires_at' => now()->subSecond(),
    ]);

    Livewire::actingAs($recipient)
        ->test('pages::family-invitation', ['token' => $token])
        ->assertSee('Invitation unavailable')
        ->call('acceptInvitation')
        ->assertHasErrors(['invitation']);

    expect($family->members()->whereKey($recipient->id)->exists())->toBeFalse();
});

test('a matching user can decline without joining the family', function () {
    $family = Family::factory()->create();
    $recipient = User::factory()->create(['email' => 'guest@example.com']);
    [$invitation, $token] = invitationWithToken($family, $recipient->email);

    Livewire::actingAs($recipient)
        ->test('pages::family-invitation', ['token' => $token])
        ->call('declineInvitation')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect($invitation->refresh()->declined_at)->not->toBeNull()
        ->and($family->members()->whereKey($recipient->id)->exists())->toBeFalse();
});

test('invitation routes require authentication and email verification', function () {
    $family = Family::factory()->create();
    [, $token] = invitationWithToken($family, 'guest@example.com');

    $this->get(route('family-invitations.show', $token))
        ->assertRedirect(route('login'));

    $unverifiedUser = User::factory()->unverified()->create(['email' => 'guest@example.com']);

    $this->actingAs($unverifiedUser)
        ->get(route('family-invitations.show', $token))
        ->assertRedirect(route('verification.notice'));
});

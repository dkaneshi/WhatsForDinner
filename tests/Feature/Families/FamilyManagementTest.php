<?php

use App\Actions\Families\ResolveActiveFamily;
use App\Models\Family;
use App\Models\User;
use Livewire\Livewire;

test('verified users can visit the families page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('families.index'))
        ->assertOk();
});

test('unverified users cannot visit the families page', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('families.index'))
        ->assertRedirect(route('verification.notice'));
});

test('a user can create a family and becomes its head and first member', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::families')
        ->set('newFamilyName', 'Kaneshi Family')
        ->set('newFamilyTimezone', 'Pacific/Honolulu')
        ->call('createFamily')
        ->assertHasNoErrors();

    $family = Family::query()->sole();

    expect($family->name)->toBe('Kaneshi Family')
        ->and($family->timezone)->toBe('Pacific/Honolulu')
        ->and($family->head->is($user))->toBeTrue()
        ->and($family->members()->whereKey($user->id)->exists())->toBeTrue()
        ->and($user->refresh()->currentFamily->is($family))->toBeTrue();
});

test('family creation validates its name and timezone', function (string $name, string $timezone, string $field) {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::families')
        ->set('newFamilyName', $name)
        ->set('newFamilyTimezone', $timezone)
        ->call('createFamily')
        ->assertHasErrors([$field]);

    expect(Family::query()->exists())->toBeFalse();
})->with([
    'missing name' => ['', 'Pacific/Honolulu', 'newFamilyName'],
    'invalid timezone' => ['Kaneshi Family', 'Pacific/Atlantis', 'newFamilyTimezone'],
]);

test('a member can switch their active family', function () {
    $user = User::factory()->create();
    $firstFamily = Family::factory()->for($user, 'head')->create(['name' => 'First Family']);
    $secondFamily = Family::factory()->for($user, 'head')->create(['name' => 'Second Family']);

    Livewire::actingAs($user)
        ->test('pages::families')
        ->assertSet('activeFamilyId', $firstFamily->id)
        ->call('switchFamily', $secondFamily->id)
        ->assertSet('activeFamilyId', $secondFamily->id);

    expect($user->refresh()->current_family_id)->toBe($secondFamily->id);
});

test('a user cannot switch to a family they do not belong to', function () {
    $user = User::factory()->create();
    $otherFamily = Family::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::families')
        ->call('switchFamily', $otherFamily->id)
        ->assertForbidden();
});

test('the head can update family settings', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();

    Livewire::actingAs($head)
        ->test('pages::families')
        ->set('familyName', 'Updated Family')
        ->set('familyTimezone', 'America/Chicago')
        ->call('updateFamily')
        ->assertHasNoErrors();

    expect($family->refresh()->name)->toBe('Updated Family')
        ->and($family->timezone)->toBe('America/Chicago');
});

test('a member cannot update family settings', function () {
    $member = User::factory()->create();
    $family = Family::factory()->create();
    $family->members()->attach($member);
    $member->forceFill(['current_family_id' => $family->id])->save();

    Livewire::actingAs($member)
        ->test('pages::families')
        ->set('familyName', 'Unauthorized Change')
        ->set('familyTimezone', 'Pacific/Honolulu')
        ->call('updateFamily')
        ->assertForbidden();

    expect($family->refresh()->name)->not->toBe('Unauthorized Change');
});

test('family listings are isolated to the current user memberships', function () {
    $user = User::factory()->create();
    Family::factory()->for($user, 'head')->create(['name' => 'Visible Family']);
    Family::factory()->create(['name' => 'Private Family']);

    Livewire::actingAs($user)
        ->test('pages::families')
        ->assertSee('Visible Family')
        ->assertDontSee('Private Family');
});

test('active family falls back when the selected membership no longer exists', function () {
    $user = User::factory()->create();
    $formerFamily = Family::factory()->create();
    $fallbackFamily = Family::factory()->create();
    $formerFamily->members()->attach($user);
    $fallbackFamily->members()->attach($user);
    $user->forceFill(['current_family_id' => $formerFamily->id])->save();

    $formerFamily->members()->detach($user);

    $resolvedFamily = app(ResolveActiveFamily::class)->execute($user->refresh());

    expect($resolvedFamily->is($fallbackFamily))->toBeTrue()
        ->and($user->refresh()->current_family_id)->toBe($fallbackFamily->id);
});

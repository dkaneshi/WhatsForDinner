<?php

use App\Models\Family;
use App\Models\User;
use Livewire\Livewire;

test('family members can visit membership management while outsiders cannot', function () {
    $family = Family::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $family->members()->attach($member);
    $member->forceFill(['current_family_id' => $family->id])->save();

    $this->actingAs($member)
        ->get(route('family-members.index'))
        ->assertOk();

    $outsider->forceFill(['current_family_id' => $family->id])->save();

    $this->actingAs($outsider)
        ->get(route('family-members.index'))
        ->assertRedirect(route('families.index'));
});

test('a member can leave and immediately loses access with an active family fallback', function () {
    $member = User::factory()->create();
    $family = Family::factory()->create();
    $fallbackFamily = Family::factory()->create();
    $family->members()->attach($member);
    $fallbackFamily->members()->attach($member);
    $member->forceFill(['current_family_id' => $family->id])->save();

    Livewire::actingAs($member)
        ->test('pages::family-members')
        ->call('leaveFamily')
        ->assertRedirect(route('families.index'));

    expect($family->members()->whereKey($member->id)->exists())->toBeFalse()
        ->and($member->refresh()->current_family_id)->toBe($fallbackFamily->id);

    $this->actingAs($member)
        ->get(route('family-members.index'))
        ->assertOk()
        ->assertSee($fallbackFamily->name)
        ->assertDontSee($family->name);
});

test('the head cannot leave or remove themselves', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();

    Livewire::actingAs($head)
        ->test('pages::family-members')
        ->call('leaveFamily')
        ->assertForbidden();

    Livewire::actingAs($head)
        ->test('pages::family-members')
        ->call('removeMember', $head->id)
        ->assertForbidden();

    expect($family->members()->whereKey($head->id)->exists())->toBeTrue()
        ->and($family->refresh()->head_user_id)->toBe($head->id);
});

test('the head can remove a member and their active family falls back', function () {
    $head = User::factory()->create();
    $member = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $fallbackFamily = Family::factory()->create();
    $family->members()->attach($member);
    $fallbackFamily->members()->attach($member);
    $member->forceFill(['current_family_id' => $family->id])->save();

    Livewire::actingAs($head)
        ->test('pages::family-members')
        ->call('removeMember', $member->id)
        ->assertHasNoErrors();

    expect($family->members()->whereKey($member->id)->exists())->toBeFalse()
        ->and($member->refresh()->current_family_id)->toBe($fallbackFamily->id);
});

test('members cannot remove others or initiate leadership transfers', function () {
    $head = User::factory()->create();
    $member = User::factory()->create();
    $otherMember = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $family->members()->attach([$member->id, $otherMember->id]);
    $member->forceFill(['current_family_id' => $family->id])->save();

    Livewire::actingAs($member)
        ->test('pages::family-members')
        ->call('removeMember', $otherMember->id)
        ->assertForbidden();

    Livewire::actingAs($member)
        ->test('pages::family-members')
        ->call('offerHeadship', $otherMember->id)
        ->assertForbidden();

    expect($family->members()->whereKey($otherMember->id)->exists())->toBeTrue()
        ->and($family->refresh()->pending_head_user_id)->toBeNull();
});

test('a pending leadership offer leaves the current head unchanged', function () {
    $head = User::factory()->create();
    $member = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $family->members()->attach($member);

    Livewire::actingAs($head)
        ->test('pages::family-members')
        ->call('offerHeadship', $member->id)
        ->assertHasNoErrors();

    expect($family->refresh()->head_user_id)->toBe($head->id)
        ->and($family->pending_head_user_id)->toBe($member->id);
});

test('only the offered member can accept leadership', function () {
    $head = User::factory()->create();
    $offeredMember = User::factory()->create();
    $otherMember = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create([
        'pending_head_user_id' => $offeredMember->id,
    ]);
    $family->members()->attach([$offeredMember->id, $otherMember->id]);
    $otherMember->forceFill(['current_family_id' => $family->id])->save();

    Livewire::actingAs($otherMember)
        ->test('pages::family-members')
        ->call('acceptHeadship')
        ->assertForbidden();

    expect($family->refresh()->head_user_id)->toBe($head->id)
        ->and($family->pending_head_user_id)->toBe($offeredMember->id);
});

test('accepting leadership creates one new head and keeps the previous head as a member', function () {
    $head = User::factory()->create();
    $member = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create([
        'pending_head_user_id' => $member->id,
    ]);
    $family->members()->attach($member);
    $member->forceFill(['current_family_id' => $family->id])->save();

    Livewire::actingAs($member)
        ->test('pages::family-members')
        ->call('acceptHeadship')
        ->assertHasNoErrors();

    expect($family->refresh()->head_user_id)->toBe($member->id)
        ->and($family->pending_head_user_id)->toBeNull()
        ->and($family->members()->whereKey($head->id)->exists())->toBeTrue()
        ->and($family->members()->whereKey($member->id)->exists())->toBeTrue()
        ->and($family->members()->count())->toBe(2);
});

test('the current head can cancel a leadership offer', function () {
    $head = User::factory()->create();
    $member = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create([
        'pending_head_user_id' => $member->id,
    ]);
    $family->members()->attach($member);

    Livewire::actingAs($head)
        ->test('pages::family-members')
        ->call('cancelHeadship')
        ->assertHasNoErrors();

    expect($family->refresh()->head_user_id)->toBe($head->id)
        ->and($family->pending_head_user_id)->toBeNull();
});

test('a pending leadership offer is canceled when its recipient leaves', function () {
    $member = User::factory()->create();
    $family = Family::factory()->create([
        'pending_head_user_id' => $member->id,
    ]);
    $family->members()->attach($member);
    $member->forceFill(['current_family_id' => $family->id])->save();

    Livewire::actingAs($member)
        ->test('pages::family-members')
        ->call('leaveFamily');

    expect($family->refresh()->pending_head_user_id)->toBeNull();
});

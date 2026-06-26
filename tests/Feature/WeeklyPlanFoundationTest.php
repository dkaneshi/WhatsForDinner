<?php

use App\Actions\WeeklyPlans\EnsureWeeklyPlanIsEditable;
use App\Actions\WeeklyPlans\FindOrCreateWeeklyPlan;
use App\Actions\WeeklyPlans\FindPriorWeeklyPlans;
use App\Actions\WeeklyPlans\ResolveWeeklyPlanWeek;
use App\Models\Family;
use App\Models\User;
use App\Models\WeeklyPlan;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('duplicate plans cannot be created for the same family and week', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();

    WeeklyPlan::factory()->for($family)->create([
        'week_start_date' => '2026-06-22',
    ]);

    expect(fn () => WeeklyPlan::factory()->for($family)->create([
        'week_start_date' => '2026-06-22',
    ]))->toThrow(QueryException::class);

    expect($family->weeklyPlans()->count())->toBe(1);
});

test('plans with the same week may exist for different families', function () {
    $firstFamily = Family::factory()->create();
    $secondFamily = Family::factory()->create();

    WeeklyPlan::factory()->for($firstFamily)->create([
        'week_start_date' => '2026-06-22',
    ]);
    WeeklyPlan::factory()->for($secondFamily)->create([
        'week_start_date' => '2026-06-22',
    ]);

    expect(WeeklyPlan::query()->count())->toBe(2);
});

test('time-zone boundaries correctly determine current and past weeks', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:30:00', 'UTC'));

    $resolver = app(ResolveWeeklyPlanWeek::class);
    $hawaiiFamily = Family::factory()->create(['timezone' => 'Pacific/Honolulu']);
    $kiritimatiFamily = Family::factory()->create(['timezone' => 'Pacific/Kiritimati']);

    expect($resolver->currentWeekStart($hawaiiFamily)->toDateString())->toBe('2026-06-22')
        ->and($resolver->currentWeekStart($kiritimatiFamily)->toDateString())->toBe('2026-06-29')
        ->and($resolver->isPastWeek($hawaiiFamily, Carbon::parse('2026-06-22', 'Pacific/Honolulu')))->toBeFalse()
        ->and($resolver->isPastWeek($kiritimatiFamily, Carbon::parse('2026-06-22', 'Pacific/Kiritimati')))->toBeTrue();

    Carbon::setTestNow();
});

test('past plans reject mutations while current plans are editable', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'UTC'));

    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create(['timezone' => 'UTC']);
    $pastPlan = WeeklyPlan::factory()->for($family)->create([
        'week_start_date' => '2026-06-22',
    ]);
    $currentPlan = WeeklyPlan::factory()->for($family)->create([
        'week_start_date' => '2026-06-29',
    ]);

    expect(fn () => app(EnsureWeeklyPlanIsEditable::class)->execute($head, $pastPlan))
        ->toThrow(AuthorizationException::class);

    app(EnsureWeeklyPlanIsEditable::class)->execute($head, $currentPlan);

    expect(true)->toBeTrue();

    Carbon::setTestNow();
});

test('current week plans remain editable when stored dates are cast in utc', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-26 06:00:00', 'UTC'));

    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create(['timezone' => 'Pacific/Honolulu']);
    $plan = WeeklyPlan::factory()->for($family)->create([
        'week_start_date' => '2026-06-22',
    ]);

    app(EnsureWeeklyPlanIsEditable::class)->execute($head, $plan);

    $this->actingAs($head)
        ->get(route('weekly-plans.show'))
        ->assertSuccessful()
        ->assertSee('Editable');

    Carbon::setTestNow();
});

test('first visit creates only one plan across repeated requests', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'UTC'));

    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create(['timezone' => 'UTC']);

    Livewire::actingAs($head)
        ->test('pages::weekly-plan')
        ->assertSet('weekStartDate', '2026-06-29')
        ->assertSee('Editable');

    Livewire::actingAs($head)
        ->test('pages::weekly-plan')
        ->assertSet('weekStartDate', '2026-06-29');

    $plan = $family->weeklyPlans()->sole();

    expect($plan->week_start_date->toDateString())->toBe('2026-06-29');

    Carbon::setTestNow();
});

test('find or create returns the existing weekly plan for a repeated first visit', function () {
    $head = User::factory()->create();
    $family = Family::factory()->for($head, 'head')->create();
    $weekStart = Carbon::parse('2026-06-22');

    $firstPlan = app(FindOrCreateWeeklyPlan::class)->execute($head, $family, $weekStart);
    $secondPlan = app(FindOrCreateWeeklyPlan::class)->execute($head, $family, $weekStart);

    expect($secondPlan->is($firstPlan))->toBeTrue()
        ->and($family->weeklyPlans()->count())->toBe(1);
});

test('missing prior plans are treated as empty history', function () {
    $family = Family::factory()->create();
    $currentPlan = WeeklyPlan::factory()->for($family)->create([
        'week_start_date' => '2026-06-29',
    ]);

    expect(app(FindPriorWeeklyPlans::class)->execute($currentPlan))->toHaveCount(0);
});

test('prior plan history returns only existing plans for the same family', function () {
    $family = Family::factory()->create();
    $otherFamily = Family::factory()->create();
    $currentPlan = WeeklyPlan::factory()->for($family)->create([
        'week_start_date' => '2026-06-29',
    ]);
    $priorPlan = WeeklyPlan::factory()->for($family)->create([
        'week_start_date' => '2026-06-22',
    ]);
    WeeklyPlan::factory()->for($otherFamily)->create([
        'week_start_date' => '2026-06-22',
    ]);

    $history = app(FindPriorWeeklyPlans::class)->execute($currentPlan);

    expect($history)->toHaveCount(1)
        ->and($history->first()->is($priorPlan))->toBeTrue();
});

test('users without a family are redirected to family setup', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('weekly-plans.show'))
        ->assertRedirect(route('families.index'));
});

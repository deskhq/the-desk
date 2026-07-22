<?php

use App\Events\UserProfileUpdated;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

test('snoozing inside a same-day window suppresses it until it closes today', function (): void {
    Event::fake([UserProfileUpdated::class]);

    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '09:00',
        'dnd_ends_at' => '17:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 12:00:00', 'UTC'), function () use ($user): void {
        $this->actingAs($user)
            ->put(route('dnd-schedule.snooze'))
            ->assertRedirect();

        expect($user->refresh()->dnd_schedule_snoozed_until->equalTo(Carbon::parse('2026-07-22 17:00:00', 'UTC')))->toBeTrue()
            ->and($user->isDndActive())->toBeFalse();
    });

    Event::assertDispatched(
        UserProfileUpdated::class,
        fn (UserProfileUpdated $event): bool => $event->user->is($user),
    );
});

test('snoozing an overnight window before midnight suppresses it until tomorrow morning', function (): void {
    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '22:00',
        'dnd_ends_at' => '07:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 23:30:00', 'UTC'), function () use ($user): void {
        $this->actingAs($user)
            ->put(route('dnd-schedule.snooze'))
            ->assertRedirect();

        expect($user->refresh()->dnd_schedule_snoozed_until->equalTo(Carbon::parse('2026-07-23 07:00:00', 'UTC')))->toBeTrue();
    });
});

test('snoozing an overnight window in its morning tail suppresses it until it closes today', function (): void {
    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '22:00',
        'dnd_ends_at' => '07:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 06:30:00', 'UTC'), function () use ($user): void {
        $this->actingAs($user)
            ->put(route('dnd-schedule.snooze'))
            ->assertRedirect();

        expect($user->refresh()->dnd_schedule_snoozed_until->equalTo(Carbon::parse('2026-07-22 07:00:00', 'UTC')))->toBeTrue();
    });
});

test('the snooze lapse is computed in the user timezone, not the server one', function (): void {
    $user = User::factory()->create([
        'timezone' => 'America/New_York',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '09:00',
        'dnd_ends_at' => '17:00',
    ]);

    // 16:00 UTC is 12:00 in New York, inside the window; 17:00 New York wall
    // clock is 21:00 UTC in July (EDT).
    $this->travelTo(Carbon::parse('2026-07-22 16:00:00', 'UTC'), function () use ($user): void {
        $this->actingAs($user)
            ->put(route('dnd-schedule.snooze'))
            ->assertRedirect();

        expect($user->refresh()->dnd_schedule_snoozed_until->equalTo(Carbon::parse('2026-07-22 21:00:00', 'UTC')))->toBeTrue();
    });
});

test('snoozing outside the window changes nothing and announces nothing', function (): void {
    Event::fake([UserProfileUpdated::class]);

    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '09:00',
        'dnd_ends_at' => '17:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 18:30:00', 'UTC'), function () use ($user): void {
        $this->actingAs($user)
            ->put(route('dnd-schedule.snooze'))
            ->assertRedirect();

        expect($user->refresh()->dnd_schedule_snoozed_until)->toBeNull();
    });

    Event::assertNotDispatched(UserProfileUpdated::class);
});

test('snoozing with the schedule disabled changes nothing', function (): void {
    Event::fake([UserProfileUpdated::class]);

    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => false,
        'dnd_starts_at' => '00:00',
        'dnd_ends_at' => '23:59',
    ]);

    $this->actingAs($user)
        ->put(route('dnd-schedule.snooze'))
        ->assertRedirect();

    expect($user->refresh()->dnd_schedule_snoozed_until)->toBeNull();

    Event::assertNotDispatched(UserProfileUpdated::class);
});

test('snoozing leaves the manual pause and the schedule untouched', function (): void {
    $until = Carbon::parse('2026-07-22 14:00:00', 'UTC');

    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_until' => $until,
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '09:00',
        'dnd_ends_at' => '17:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 12:00:00', 'UTC'), function () use ($user, $until): void {
        $this->actingAs($user)->put(route('dnd-schedule.snooze'))->assertRedirect();

        $user->refresh();

        expect($user->dnd_until->equalTo($until))->toBeTrue()
            ->and($user->dnd_schedule_enabled)->toBeTrue()
            ->and($user->dnd_starts_at)->toBe('09:00')
            ->and($user->dnd_ends_at)->toBe('17:00')
            ->and($user->isDndActive())->toBeTrue();
    });
});

test('a guest cannot snooze the schedule', function (): void {
    $this->put(route('dnd-schedule.snooze'))
        ->assertRedirect(route('login'));
});

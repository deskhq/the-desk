<?php

use App\Models\User;
use Illuminate\Support\Carbon;

test('a user with no dnd configuration is not in dnd', function (): void {
    $user = User::factory()->create();

    expect($user->isDndActive())->toBeFalse();
});

test('a manual pause still ahead of its lapse reads as dnd', function (): void {
    $user = User::factory()->create([
        'dnd_until' => now()->addMinutes(30),
    ]);

    expect($user->isDndActive())->toBeTrue();
});

test('a manual pause lapses on read once its instant passes', function (): void {
    $user = User::factory()->create([
        'dnd_until' => now()->subMinute(),
    ]);

    expect($user->isDndActive())->toBeFalse();
});

test('an enabled quiet-hours schedule reads as dnd inside its window', function (): void {
    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '09:00',
        'dnd_ends_at' => '17:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 12:00:00', 'UTC'), function () use ($user): void {
        expect($user->isDndActive())->toBeTrue();
    });
});

test('an enabled quiet-hours schedule reads as no dnd outside its window', function (): void {
    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '09:00',
        'dnd_ends_at' => '17:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 18:30:00', 'UTC'), function () use ($user): void {
        expect($user->isDndActive())->toBeFalse();
    });
});

test('the window starts inclusive and ends exclusive', function (): void {
    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '09:00',
        'dnd_ends_at' => '17:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 09:00:00', 'UTC'), function () use ($user): void {
        expect($user->isDndActive())->toBeTrue();
    });

    $this->travelTo(Carbon::parse('2026-07-22 17:00:00', 'UTC'), function () use ($user): void {
        expect($user->isDndActive())->toBeFalse();
    });
});

test('an overnight window wraps across midnight', function (): void {
    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '22:00',
        'dnd_ends_at' => '07:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 23:30:00', 'UTC'), function () use ($user): void {
        expect($user->isDndActive())->toBeTrue();
    });

    $this->travelTo(Carbon::parse('2026-07-22 06:30:00', 'UTC'), function () use ($user): void {
        expect($user->isDndActive())->toBeTrue();
    });

    $this->travelTo(Carbon::parse('2026-07-22 12:00:00', 'UTC'), function () use ($user): void {
        expect($user->isDndActive())->toBeFalse();
    });
});

test('the window is evaluated in the user timezone, not the server one', function (): void {
    $user = User::factory()->create([
        'timezone' => 'America/New_York',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '09:00',
        'dnd_ends_at' => '17:00',
    ]);

    // 14:00 UTC is 10:00 in New York — inside the window. 12:00 UTC is 08:00
    // there — outside it, though a naive UTC read would say inside.
    $this->travelTo(Carbon::parse('2026-07-22 14:00:00', 'UTC'), function () use ($user): void {
        expect($user->isDndActive())->toBeTrue();
    });

    $this->travelTo(Carbon::parse('2026-07-22 12:00:00', 'UTC'), function () use ($user): void {
        expect($user->isDndActive())->toBeFalse();
    });
});

test('a snoozed quiet-hours window reads as no dnd', function (): void {
    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '09:00',
        'dnd_ends_at' => '17:00',
        'dnd_schedule_snoozed_until' => Carbon::parse('2026-07-22 17:00:00', 'UTC'),
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 12:00:00', 'UTC'), function () use ($user): void {
        expect($user->isDndActive())->toBeFalse();
    });
});

test('a lapsed snooze lets the window read as dnd again', function (): void {
    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '09:00',
        'dnd_ends_at' => '17:00',
        'dnd_schedule_snoozed_until' => Carbon::parse('2026-07-22 17:00:00', 'UTC'),
    ]);

    $this->travelTo(Carbon::parse('2026-07-23 12:00:00', 'UTC'), function () use ($user): void {
        expect($user->isDndActive())->toBeTrue();
    });
});

test('a snooze never mutes a manual pause', function (): void {
    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_until' => Carbon::parse('2026-07-22 14:00:00', 'UTC'),
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '09:00',
        'dnd_ends_at' => '17:00',
        'dnd_schedule_snoozed_until' => Carbon::parse('2026-07-22 17:00:00', 'UTC'),
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 12:00:00', 'UTC'), function () use ($user): void {
        expect($user->isDndActive())->toBeTrue();
    });
});

test('a disabled schedule never reads as dnd', function (): void {
    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => false,
        'dnd_starts_at' => '00:00',
        'dnd_ends_at' => '23:59',
    ]);

    expect($user->isDndActive())->toBeFalse();
});

test('an enabled schedule missing either bound never reads as dnd', function (): void {
    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => null,
        'dnd_ends_at' => null,
    ]);

    expect($user->isDndActive())->toBeFalse();
});

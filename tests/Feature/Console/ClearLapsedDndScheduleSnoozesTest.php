<?php

use App\Actions\Users\ClearLapsedDndScheduleSnoozes;
use App\Events\UserProfileUpdated;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

test('a lapsed snooze is cleared and the clear is broadcast', function (): void {
    Event::fake([UserProfileUpdated::class]);

    $user = User::factory()->create(['dnd_schedule_snoozed_until' => now()->subMinute()]);

    expect(app(ClearLapsedDndScheduleSnoozes::class)->handle())->toBe(1);

    expect($user->refresh()->dnd_schedule_snoozed_until)->toBeNull();

    Event::assertDispatched(
        UserProfileUpdated::class,
        fn (UserProfileUpdated $event): bool => $event->user->is($user),
    );
});

test('a snooze still ahead of its lapse is left alone', function (): void {
    Event::fake([UserProfileUpdated::class]);

    $user = User::factory()->create(['dnd_schedule_snoozed_until' => now()->addMinutes(30)]);

    expect(app(ClearLapsedDndScheduleSnoozes::class)->handle())->toBe(0);
    expect($user->refresh()->dnd_schedule_snoozed_until)->not->toBeNull();

    Event::assertNotDispatched(UserProfileUpdated::class);
});

test('a user with no snooze is never touched', function (): void {
    User::factory()->create();

    expect(app(ClearLapsedDndScheduleSnoozes::class)->handle())->toBe(0);
});

test('the sweep clears every lapsed snooze in one pass', function (): void {
    User::factory()->count(3)->create(['dnd_schedule_snoozed_until' => now()->subHour()]);

    expect(app(ClearLapsedDndScheduleSnoozes::class)->handle())->toBe(3);
    expect(User::query()->whereNotNull('dnd_schedule_snoozed_until')->count())->toBe(0);
});

test('a snooze replaced mid-sweep is left alone', function (): void {
    User::factory()->count(2)->create(['dnd_schedule_snoozed_until' => now()->subMinute()]);

    // Stand in for someone snoozing afresh in the window between the sweep's
    // query and its write. The first clear the sweep performs is the trigger,
    // so whichever user it reaches second is mutated behind it — while the
    // model it already hydrated still carries the lapsed instant.
    Event::listen(UserProfileUpdated::class, function () use (&$replaced): void {
        if ($replaced ?? false) {
            return;
        }

        $replaced = true;

        User::query()
            ->whereNotNull('dnd_schedule_snoozed_until')
            ->update(['dnd_schedule_snoozed_until' => now()->addHour()]);
    });

    // Only the first user is cleared; the replaced one is skipped, because its
    // clear is conditional on the instant the sweep actually read.
    expect(app(ClearLapsedDndScheduleSnoozes::class)->handle())->toBe(1);

    expect(User::query()->whereNotNull('dnd_schedule_snoozed_until')->sole()->dnd_schedule_snoozed_until->isFuture())->toBeTrue();
});

test('the sweep is scheduled every minute', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => $event->description === 'Clear lapsed quiet-hours snoozes');

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});

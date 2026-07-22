<?php

use App\Actions\Users\ClearLapsedDndPauses;
use App\Events\UserProfileUpdated;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

test('a lapsed pause is cleared and the clear is broadcast', function (): void {
    Event::fake([UserProfileUpdated::class]);

    $user = User::factory()->create(['dnd_until' => now()->subMinute()]);

    expect(app(ClearLapsedDndPauses::class)->handle())->toBe(1);

    expect($user->refresh()->dnd_until)->toBeNull();

    Event::assertDispatched(
        UserProfileUpdated::class,
        fn (UserProfileUpdated $event): bool => $event->user->is($user),
    );
});

test('a pause still ahead of its lapse is left alone', function (): void {
    Event::fake([UserProfileUpdated::class]);

    $user = User::factory()->create(['dnd_until' => now()->addMinutes(30)]);

    expect(app(ClearLapsedDndPauses::class)->handle())->toBe(0);
    expect($user->refresh()->dnd_until)->not->toBeNull();

    Event::assertNotDispatched(UserProfileUpdated::class);
});

test('a user with no pause is never touched', function (): void {
    User::factory()->create();

    expect(app(ClearLapsedDndPauses::class)->handle())->toBe(0);
});

test('the sweep clears every lapsed pause in one pass', function (): void {
    User::factory()->count(3)->create(['dnd_until' => now()->subHour()]);

    expect(app(ClearLapsedDndPauses::class)->handle())->toBe(3);
    expect(User::query()->whereNotNull('dnd_until')->count())->toBe(0);
});

test('a pause replaced mid-sweep is left alone', function (): void {
    User::factory()->count(2)->create(['dnd_until' => now()->subMinute()]);

    // Stand in for someone starting a fresh pause in the window between the
    // sweep's query and its write. The first clear the sweep performs is the
    // trigger, so whichever user it reaches second is mutated behind it — while
    // the model it already hydrated still carries the lapsed instant.
    Event::listen(UserProfileUpdated::class, function () use (&$replaced): void {
        if ($replaced ?? false) {
            return;
        }

        $replaced = true;

        User::query()
            ->whereNotNull('dnd_until')
            ->update(['dnd_until' => now()->addHour()]);
    });

    // Only the first user is cleared; the replaced one is skipped, because its
    // clear is conditional on the instant the sweep actually read.
    expect(app(ClearLapsedDndPauses::class)->handle())->toBe(1);

    expect(User::query()->whereNotNull('dnd_until')->sole()->dnd_until->isFuture())->toBeTrue();
});

test('the sweep is scheduled every minute', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => $event->description === 'Clear lapsed do-not-disturb pauses');

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});

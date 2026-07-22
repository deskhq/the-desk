<?php

use App\Actions\Users\ClearExpiredUserStatuses;
use App\Events\UserProfileUpdated;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

test('a lapsed status is cleared and the clear is broadcast', function (): void {
    Event::fake([UserProfileUpdated::class]);

    $user = User::factory()->create([
        'status_emoji' => '📅',
        'status_text' => 'In a meeting',
        'status_expires_at' => now()->subMinute(),
    ]);

    expect(app(ClearExpiredUserStatuses::class)->handle())->toBe(1);

    $user->refresh();

    expect($user->status_emoji)->toBeNull()
        ->and($user->status_text)->toBeNull()
        ->and($user->status_expires_at)->toBeNull();

    Event::assertDispatched(
        UserProfileUpdated::class,
        fn (UserProfileUpdated $event): bool => $event->user->is($user),
    );
});

test('a status still ahead of its expiry is left alone', function (): void {
    Event::fake([UserProfileUpdated::class]);

    $user = User::factory()->create([
        'status_emoji' => '🚌',
        'status_text' => 'Commuting',
        'status_expires_at' => now()->addMinutes(30),
    ]);

    expect(app(ClearExpiredUserStatuses::class)->handle())->toBe(0);
    expect($user->refresh()->status_emoji)->toBe('🚌');

    Event::assertNotDispatched(UserProfileUpdated::class);
});

test('a status that never clears is left alone', function (): void {
    $user = User::factory()->create([
        'status_emoji' => '🏠',
        'status_text' => 'Working remotely',
        'status_expires_at' => null,
    ]);

    expect(app(ClearExpiredUserStatuses::class)->handle())->toBe(0);
    expect($user->refresh()->status_emoji)->toBe('🏠');
});

test('a user with no status is never touched', function (): void {
    User::factory()->create();

    expect(app(ClearExpiredUserStatuses::class)->handle())->toBe(0);
});

test('the sweep clears every lapsed status in one pass', function (): void {
    User::factory()->count(3)->create([
        'status_emoji' => '🤒',
        'status_expires_at' => now()->subHour(),
    ]);

    expect(app(ClearExpiredUserStatuses::class)->handle())->toBe(3);
    expect(User::query()->whereNotNull('status_emoji')->count())->toBe(0);
});

test('a status replaced mid-sweep is left alone', function (): void {
    User::factory()->count(2)->create([
        'status_emoji' => '📅',
        'status_text' => 'In a meeting',
        'status_expires_at' => now()->subMinute(),
    ]);

    // Stand in for someone setting a fresh status in the window between the
    // sweep's query and its write. The first clear the sweep performs is the
    // trigger, so whichever user it reaches second is mutated behind it — while
    // the model it already hydrated still carries the lapsed expiry.
    Event::listen(UserProfileUpdated::class, function () use (&$replaced): void {
        if ($replaced ?? false) {
            return;
        }

        $replaced = true;

        User::query()
            ->whereNotNull('status_emoji')
            ->update([
                'status_emoji' => '🏠',
                'status_text' => 'Working remotely',
                'status_expires_at' => now()->addHour(),
            ]);
    });

    // Only the first user is cleared; the replaced one is skipped, because its
    // clear is conditional on the expiry the sweep actually read.
    expect(app(ClearExpiredUserStatuses::class)->handle())->toBe(1);

    $survivor = User::query()->whereNotNull('status_emoji')->sole();

    expect($survivor->status_emoji)->toBe('🏠')
        ->and($survivor->status_text)->toBe('Working remotely')
        ->and($survivor->status_expires_at->isFuture())->toBeTrue();
});

test('the sweep is scheduled every minute', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => $event->description === 'Clear lapsed custom statuses');

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});

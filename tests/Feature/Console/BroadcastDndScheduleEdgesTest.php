<?php

use App\Actions\Users\BroadcastDndScheduleEdges;
use App\Events\UserProfileUpdated;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

test('a window opening this minute is broadcast', function (): void {
    Event::fake([UserProfileUpdated::class]);

    $user = User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '22:00',
        'dnd_ends_at' => '07:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 22:00:10', 'UTC'), function () use ($user): void {
        expect(app(BroadcastDndScheduleEdges::class)->handle())->toBe(1);

        Event::assertDispatched(
            UserProfileUpdated::class,
            fn (UserProfileUpdated $event): bool => $event->user->is($user),
        );
    });
});

test('a window closing this minute is broadcast', function (): void {
    Event::fake([UserProfileUpdated::class]);

    User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '22:00',
        'dnd_ends_at' => '07:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 07:00:45', 'UTC'), function (): void {
        expect(app(BroadcastDndScheduleEdges::class)->handle())->toBe(1);

        Event::assertDispatched(UserProfileUpdated::class);
    });
});

test('a minute inside or outside the window broadcasts nothing', function (): void {
    Event::fake([UserProfileUpdated::class]);

    User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '22:00',
        'dnd_ends_at' => '07:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 23:30:00', 'UTC'), function (): void {
        expect(app(BroadcastDndScheduleEdges::class)->handle())->toBe(0);
    });

    Event::assertNotDispatched(UserProfileUpdated::class);
});

test('a disabled schedule is never broadcast', function (): void {
    Event::fake([UserProfileUpdated::class]);

    User::factory()->create([
        'timezone' => 'UTC',
        'dnd_schedule_enabled' => false,
        'dnd_starts_at' => '22:00',
        'dnd_ends_at' => '07:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-22 22:00:10', 'UTC'), function (): void {
        expect(app(BroadcastDndScheduleEdges::class)->handle())->toBe(0);
    });

    Event::assertNotDispatched(UserProfileUpdated::class);
});

test('the edge is matched in the user timezone, not the server one', function (): void {
    Event::fake([UserProfileUpdated::class]);

    User::factory()->create([
        'timezone' => 'America/New_York',
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '22:00',
        'dnd_ends_at' => '07:00',
    ]);

    // 02:00 UTC is 22:00 in New York — their window opens now.
    $this->travelTo(Carbon::parse('2026-07-23 02:00:30', 'UTC'), function (): void {
        expect(app(BroadcastDndScheduleEdges::class)->handle())->toBe(1);
    });

    // 22:00 UTC is 18:00 in New York — no edge for them.
    $this->travelTo(Carbon::parse('2026-07-22 22:00:30', 'UTC'), function (): void {
        expect(app(BroadcastDndScheduleEdges::class)->handle())->toBe(0);
    });
});

test('the edge broadcast is scheduled every minute', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => $event->description === 'Broadcast quiet-hours windows opening or closing');

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});

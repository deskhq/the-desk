<?php

use App\Models\DataExport;
use App\Models\SecurityEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * Find a scheduled event by its description, loading the console schedule first
 * so a fresh (post-reload) application has its events registered.
 */
function scheduledEventDescribed(string $description): ?Event
{
    Artisan::call('schedule:list');

    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => $event->description === $description);
}

/**
 * Assert a retention sweep is registered to run once a day and not to overlap a
 * still-running pass, then hand the event back so the caller can run it.
 */
function dailyRetentionSweep(string $description): Event
{
    $event = scheduledEventDescribed($description);

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 0 * * *');
    expect($event->withoutOverlapping)->toBeTrue();

    return $event;
}

test('the scheduled security-event sweep prunes past the retention window', function (): void {
    config()->set('security.events.retention_days', 365);
    $stale = SecurityEvent::factory()->create(['created_at' => now()->subDays(400)]);
    $recent = SecurityEvent::factory()->create();

    dailyRetentionSweep('Prune security events past the retention window')->run($this->app);

    $this->assertDatabaseMissing('security_events', ['id' => $stale->id]);
    $this->assertDatabaseHas('security_events', ['id' => $recent->id]);
});

test('the scheduled data-export sweep purges expired archives', function (): void {
    Storage::fake('local');
    $expired = DataExport::factory()->expired()->create();
    Storage::disk('local')->put($expired->path, 'zip-bytes');
    $live = DataExport::factory()->ready()->create();

    dailyRetentionSweep('Purge expired data-export archives (files and rows)')->run($this->app);

    Storage::disk('local')->assertMissing($expired->path);
    $this->assertDatabaseMissing('data_exports', ['id' => $expired->id]);
    $this->assertDatabaseHas('data_exports', ['id' => $live->id]);
});

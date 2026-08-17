<?php

declare(strict_types=1);

use App\Models\AuditActivity;
use App\Models\DataExport;
use App\Models\SecurityEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * The description the workspace audit-log sweep is registered under, which is
 * also how it is located in the schedule.
 */
const AUDIT_LOG_SWEEP_DESCRIPTION = 'Prune workspace audit-log entries past the retention window';

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
    Storage::disk('local')->put($live->path, 'zip-bytes');

    dailyRetentionSweep('Purge expired data-export archives (files and rows)')->run($this->app);

    Storage::disk('local')->assertMissing($expired->path);
    Storage::disk('local')->assertExists($live->path);
    $this->assertDatabaseMissing('data_exports', ['id' => $expired->id]);
    $this->assertDatabaseHas('data_exports', ['id' => $live->id]);
});

test('the audit-log sweep is scheduled daily without overlapping', function (): void {
    $event = dailyRetentionSweep(AUDIT_LOG_SWEEP_DESCRIPTION);

    expect($event->command)->toContain('activitylog:clean');
});

test('the audit-log sweep forces itself through the production confirmation prompt', function (): void {
    expect(dailyRetentionSweep(AUDIT_LOG_SWEEP_DESCRIPTION)->command)->toContain('--force');
});

test('the audit-log sweep runs while a retention window is set', function (): void {
    config()->set('activitylog.clean_after_days', 365);

    expect(dailyRetentionSweep(AUDIT_LOG_SWEEP_DESCRIPTION)->filtersPass($this->app))->toBeTrue();
});

test('the audit-log sweep is skipped when retention is disabled', function (int $days): void {
    config()->set('activitylog.clean_after_days', $days);

    expect(dailyRetentionSweep(AUDIT_LOG_SWEEP_DESCRIPTION)->filtersPass($this->app))->toBeFalse();
})->with([0, -1]);

test('the audit-log sweep deletes entries past the configured window and keeps newer ones', function (): void {
    config()->set('activitylog.clean_after_days', 30);
    $stale = AuditActivity::factory()->create(['created_at' => now()->subDays(31)]);
    $recent = AuditActivity::factory()->create(['created_at' => now()->subDays(29)]);

    $this->artisan('activitylog:clean', ['--force' => true])->assertSuccessful();

    $this->assertDatabaseMissing('activity_log', ['id' => $stale->id]);
    $this->assertDatabaseHas('activity_log', ['id' => $recent->id]);
});

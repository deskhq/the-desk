<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/**
 * The scheduled `demo:seed` reset event, loading the console schedule first so a
 * fresh (post-reload) application has its events registered.
 */
function demoSeedEvent(): ?Event
{
    Artisan::call('schedule:list');

    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'demo:seed'));
}

test('the demo reset is scheduled to run hourly', function (): void {
    $event = demoSeedEvent();

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 * * * *');
});

test('the demo reset runs when demo mode is on', function (): void {
    $this->reloadWithDemoMode(true);

    expect(demoSeedEvent()->filtersPass($this->app))->toBeTrue();
});

test('the demo reset is skipped when demo mode is off', function (): void {
    $this->reloadWithDemoMode(false);

    expect(demoSeedEvent()->filtersPass($this->app))->toBeFalse();
});

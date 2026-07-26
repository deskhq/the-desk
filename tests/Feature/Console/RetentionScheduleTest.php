<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

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

test('the retention sweeps run daily, without overlapping', function (string $description): void {
    $event = scheduledEventDescribed($description);

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 0 * * *');
    expect($event->withoutOverlapping)->toBeTrue();
})->with([
    'Prune security events past the retention window',
    'Purge expired data-export archives (files and rows)',
]);

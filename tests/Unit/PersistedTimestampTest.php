<?php

declare(strict_types=1);

use App\Support\PersistedTimestamp;
use Illuminate\Support\Carbon;

test('it hands back the timestamp a persisted row carries', function (): void {
    $at = Carbon::parse('2026-08-17 12:00:00');

    expect(PersistedTimestamp::of($at))->toBe($at);
});

test('it refuses a model that was never saved', function (): void {
    PersistedTimestamp::of(null);
})->throws(RuntimeException::class, 'Expected a persisted model to carry the timestamp being read.');

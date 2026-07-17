<?php

use App\Models\Message;
use Illuminate\Support\Carbon;

/**
 * Regression guard for the residual #447/#448 timeline flake. The busiest
 * channel's analytics backfill spreads each day's messages forward from 09:00,
 * one hour per message, so the most recent day's tail spills past midnight into
 * "now" whenever the seeder runs in the small hours. A back-dated message that
 * lands in the future mints a UUIDv7 whose embedded timestamp outranks every real
 * message, so `id DESC` (the timeline) surfaces a seed row on top and a freshly
 * sent message no longer sorts newest.
 *
 * Freeze a post-midnight instant plus the fixed faker seed that drove the
 * overshoot (a busy final day whose tail reached 01:00), then assert the backfill
 * never dates a message ahead of the clock.
 */
test('backfilled channel history never dates a message in the future when seeded after midnight', function (): void {
    Carbon::setTestNow('2026-07-17 00:30:00');
    fake()->seed(4);

    $this->seed();

    expect(Message::where('created_at', '>', now())->exists())->toBeFalse();
});

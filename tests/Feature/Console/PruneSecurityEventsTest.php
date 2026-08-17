<?php

declare(strict_types=1);

use App\Actions\Users\PruneSecurityEvents;
use App\Models\SecurityEvent;

/**
 * Record a security event as having happened the given number of days ago.
 */
function agedSecurityEvent(int $daysAgo): SecurityEvent
{
    return SecurityEvent::factory()->create(['created_at' => now()->subDays($daysAgo)]);
}

test('an event older than the retention window is pruned and a newer one survives', function (): void {
    config()->set('security.events.retention_days', 365);
    $stale = agedSecurityEvent(366);
    $recent = agedSecurityEvent(364);

    $pruned = app(PruneSecurityEvents::class)->handle();

    expect($pruned)->toBe(1);
    $this->assertDatabaseMissing('security_events', ['id' => $stale->id]);
    $this->assertDatabaseHas('security_events', ['id' => $recent->id]);
});

test('the retention window is configurable', function (): void {
    config()->set('security.events.retention_days', 30);
    $stale = agedSecurityEvent(31);
    $recent = agedSecurityEvent(29);

    $pruned = app(PruneSecurityEvents::class)->handle();

    expect($pruned)->toBe(1);
    $this->assertDatabaseMissing('security_events', ['id' => $stale->id]);
    $this->assertDatabaseHas('security_events', ['id' => $recent->id]);
});

test('it prunes across every user, not just one', function (): void {
    config()->set('security.events.retention_days', 90);
    $first = agedSecurityEvent(120);
    $second = agedSecurityEvent(200);

    $pruned = app(PruneSecurityEvents::class)->handle();

    expect($pruned)->toBe(2);
    expect($first->user_id)->not->toBe($second->user_id);
    expect(SecurityEvent::query()->count())->toBe(0);
});

test('a window of zero or less keeps events forever', function (int $days): void {
    config()->set('security.events.retention_days', $days);
    $ancient = agedSecurityEvent(3_650);

    $pruned = app(PruneSecurityEvents::class)->handle();

    expect($pruned)->toBe(0);
    $this->assertDatabaseHas('security_events', ['id' => $ancient->id]);
})->with([0, -1]);

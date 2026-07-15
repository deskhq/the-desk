<?php

use App\Enums\SecurityEventType;
use App\Exceptions\SecurityEventImmutableException;
use App\Models\SecurityEvent;

test('a security event cannot be updated', function (): void {
    $event = SecurityEvent::factory()->create(['ip_address' => '192.0.2.1']);

    expect(fn () => $event->update(['ip_address' => '10.0.0.1']))
        ->toThrow(SecurityEventImmutableException::class);

    expect($event->fresh()->ip_address)->toBe('192.0.2.1');
});

test('a security event cannot be deleted', function (): void {
    $event = SecurityEvent::factory()->create();

    expect(fn () => $event->delete())
        ->toThrow(SecurityEventImmutableException::class);

    expect(SecurityEvent::query()->whereKey($event->id)->exists())->toBeTrue();
});

test('a query-builder bulk delete bypasses the guard, as the prune path relies on', function (): void {
    $stale = SecurityEvent::factory()->ofType(SecurityEventType::LoggedIn)->create();
    $kept = SecurityEvent::factory()->ofType(SecurityEventType::PasswordChanged)->create();

    $deleted = SecurityEvent::query()
        ->where('type', SecurityEventType::LoggedIn)
        ->delete();

    expect($deleted)->toBe(1);
    expect(SecurityEvent::query()->whereKey($stale->id)->exists())->toBeFalse();
    expect(SecurityEvent::query()->whereKey($kept->id)->exists())->toBeTrue();
});

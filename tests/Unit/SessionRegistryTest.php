<?php

use App\Support\SessionRegistry;
use Tests\TestCase;

// The registry reads config('session.lifetime') and the application cache, so
// these tests boot the container.
uses(TestCase::class);

function registry(): SessionRegistry
{
    return app(SessionRegistry::class);
}

test('forgetting the last session clears the index', function (): void {
    $registry = registry();
    $registry->record('user-1', 'session-a', '203.0.113.1', 'Chrome', now()->timestamp);

    expect($registry->forget('user-1', 'session-a'))->toBeTrue();

    expect($registry->has('user-1', 'session-a'))->toBeFalse();
    expect($registry->all('user-1'))->toBe([]);
});

test('forgetting an absent session is a no-op', function (): void {
    expect(registry()->forget('user-1', 'never-seen'))->toBeFalse();
});

test('flushing revokes every session and clears the index', function (): void {
    $registry = registry();
    $registry->record('user-1', 'session-a', '203.0.113.1', 'Chrome', now()->timestamp);
    $registry->record('user-1', 'session-b', '203.0.113.2', 'Firefox', now()->timestamp);

    expect($registry->flush('user-1'))->toBe(2);

    expect($registry->all('user-1'))->toBe([])
        ->and($registry->has('user-1', 'session-a'))->toBeFalse()
        ->and($registry->has('user-1', 'session-b'))->toBeFalse();
});

test('flushing a user with no sessions removes nothing', function (): void {
    expect(registry()->flush('user-1'))->toBe(0);
});

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

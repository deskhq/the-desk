<?php

/*
 * Regression guard for the DEMO_MODE cross-suite leak (#549): the two tests
 * below are an intentionally ordered pair. The first overrides DEMO_MODE via
 * reloadWithDemoMode(); the second asserts the override was fully unwound by
 * TestCase::tearDown() — no env store may still carry the override's "true",
 * and a freshly booted application must read demo mode as off again, whether
 * DEMO_MODE arrived via the phpunit.xml pin, a process-level export, or a
 * developer's .env. (The exact restored string is not asserted: PHPUnit
 * normalizes the pin's value="false" to an empty string.)
 */

test('overriding demo mode reboots the application with the flag on', function (): void {
    $this->reloadWithDemoMode(true);

    expect(config('demo.mode'))->toBeTrue();
});

test('the demo mode override from the previous test is fully unwound', function (): void {
    expect(getenv('DEMO_MODE'))->not->toBe('true');
    expect($_ENV['DEMO_MODE'] ?? null)->not->toBe('true');
    expect(config('demo.mode'))->toBeFalse();
});

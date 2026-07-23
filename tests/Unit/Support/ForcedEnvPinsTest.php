<?php

declare(strict_types=1);

use Tests\Support\ForcedEnvPins;

/*
 * ForcedEnvPins is what makes phpunit.xml's `force="true"` pins live up to
 * their name (#756). PHPUnit writes a forced pin to putenv() and $_ENV only,
 * so an exported variable — which also lands in $_SERVER — still wins, because
 * Laravel's env repository reads $_SERVER first. tests/bootstrap.php runs this
 * helper right after PHPUnit applies the pins to clear those stale copies.
 */

$fixture = dirname(__DIR__, 2).'/Fixtures/env-pins/configuration.xml';

afterEach(function (): void {
    unset($_SERVER['PIN_FORCED_EMPTY'], $_SERVER['PIN_FORCED_VALUE'], $_SERVER['PIN_WITHOUT_FORCE']);
});

test('it reads the names of the force-pinned env variables', function () use ($fixture): void {
    expect(ForcedEnvPins::names($fixture))->toBe(['PIN_FORCED_EMPTY', 'PIN_FORCED_VALUE']);
});

test('it clears the exported copy of every force-pinned name', function () use ($fixture): void {
    $_SERVER['PIN_FORCED_EMPTY'] = 'exported';
    $_SERVER['PIN_FORCED_VALUE'] = 'exported';
    $_SERVER['PIN_WITHOUT_FORCE'] = 'exported';

    ForcedEnvPins::clearServerCopies($fixture);

    expect($_SERVER)->not->toHaveKey('PIN_FORCED_EMPTY')
        ->and($_SERVER)->not->toHaveKey('PIN_FORCED_VALUE')
        ->and($_SERVER)->toHaveKey('PIN_WITHOUT_FORCE', 'exported');
});

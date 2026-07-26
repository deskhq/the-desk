<?php

declare(strict_types=1);

/**
 * The session lifetime an operator inherits when they never set
 * `SESSION_LIFETIME`. Eight hours covers a working day, so a chat app left alone
 * over lunch is still signed in on return; anything longer is what "Keep me
 * signed in" is for. See issue #880.
 */
const DEFAULT_SESSION_LIFETIME = 480;

/**
 * The default is stated in four places an operator can read — the config
 * fallback, both env templates, and the docs table — and a change to one of them
 * that misses the others hands out contradictory answers.
 */
function statedSessionLifetime(string $relativePath, string $pattern): ?int
{
    $contents = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);

    if (preg_match($pattern, $contents, $matches) !== 1) {
        return null;
    }

    return (int) $matches[1];
}

test('the config fallback is eight hours', function (): void {
    expect(statedSessionLifetime('config/session.php', "/env\('SESSION_LIFETIME',\s*(\d+)\)/"))
        ->toBe(DEFAULT_SESSION_LIFETIME);
});

test('both env templates ship the config fallback', function (string $template): void {
    expect(statedSessionLifetime($template, '/^SESSION_LIFETIME=(\d+)$/m'))
        ->toBe(DEFAULT_SESSION_LIFETIME);
})->with(['.env.example', '.env.prod.example']);

test('the documented default matches the shipped one', function (): void {
    expect(statedSessionLifetime(
        'docs/src/content/docs/reference/environment-variables.md',
        '/\|\s*`SESSION_LIFETIME`\s*\|\s*`(\d+)`/',
    ))->toBe(DEFAULT_SESSION_LIFETIME);
});

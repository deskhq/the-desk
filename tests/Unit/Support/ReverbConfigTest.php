<?php

declare(strict_types=1);

use App\Support\ReverbConfig;

test('the websocket origin maps an https connection to wss', function (): void {
    config([
        'app.url' => 'https://chat.example.test',
        'broadcasting.connections.reverb.public_host' => null,
        'broadcasting.connections.reverb.public_port' => null,
        'broadcasting.connections.reverb.public_scheme' => null,
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
    ]);

    expect(ReverbConfig::websocketOrigin())->toBe('wss://chat.example.test:443');
});

test('the websocket origin maps a plain http connection to ws', function (): void {
    config([
        'app.url' => 'http://localhost',
        'broadcasting.connections.reverb.public_host' => null,
        'broadcasting.connections.reverb.public_port' => null,
        'broadcasting.connections.reverb.public_scheme' => null,
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
    ]);

    expect(ReverbConfig::websocketOrigin())->toBe('ws://localhost:8080');
});

test('the websocket origin honours the browser-facing overrides', function (): void {
    // A TLS-terminating reverse proxy: the container speaks http on 8080, but the
    // browser reaches Reverb as wss on 443 at a dedicated WebSocket host.
    config([
        'app.url' => 'https://chat.example.test',
        'broadcasting.connections.reverb.public_host' => 'ws.example.test',
        'broadcasting.connections.reverb.public_port' => 443,
        'broadcasting.connections.reverb.public_scheme' => 'https',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
    ]);

    expect(ReverbConfig::websocketOrigin())->toBe('wss://ws.example.test:443');
});

/*
 * Regression guard for #732. The browser-facing overrides come from
 * REVERB_HOST_PUBLIC / REVERB_PORT_PUBLIC / REVERB_SCHEME_PUBLIC, and
 * ReverbConfig prefers them over the server-facing connection — so any value in
 * the host's .env (which bin/worktree copies verbatim into every worktree)
 * shadows the config() overrides the tests above make, and the suite fails on a
 * machine-specific port. phpunit.xml pins all three empty; this asserts the pin
 * is in place, because without it the failure surfaces far from its cause.
 */
test('the browser-facing overrides are unset in the test environment', function (): void {
    expect(config('broadcasting.connections.reverb.public_host'))->toBeEmpty()
        ->and(config('broadcasting.connections.reverb.public_port'))->toBeEmpty()
        ->and(config('broadcasting.connections.reverb.public_scheme'))->toBeEmpty();
});

test('the websocket origin is null when no browser-facing host can be resolved', function (): void {
    config([
        'app.url' => '',
        'broadcasting.connections.reverb.public_host' => null,
    ]);

    expect(ReverbConfig::websocketOrigin())->toBeNull();
});

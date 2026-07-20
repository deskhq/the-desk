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

test('the websocket origin is null when no browser-facing host can be resolved', function (): void {
    config([
        'app.url' => '',
        'broadcasting.connections.reverb.public_host' => null,
    ]);

    expect(ReverbConfig::websocketOrigin())->toBeNull();
});

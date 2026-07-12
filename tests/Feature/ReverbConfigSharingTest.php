<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the browser-facing reverb config is shared to the frontend at runtime', function (): void {
    config([
        'app.name' => 'The Desk',
        'app.url' => 'https://chat.example.test',
        'broadcasting.connections.reverb.key' => 'demo-key',
        'broadcasting.connections.reverb.public_host' => null,
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('name', 'The Desk')
            ->where('reverb.key', 'demo-key')
            ->where('reverb.host', 'chat.example.test')
            ->where('reverb.port', 443)
            ->where('reverb.scheme', 'https')
        );
});

test('browser-facing host, port, and scheme can each override the server-facing values', function (): void {
    // A TLS-terminating reverse proxy: the container speaks http on 8080, but the
    // browser reaches Reverb as wss on 443 at a dedicated WebSocket host.
    config([
        'app.url' => 'https://chat.example.test',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.public_host' => 'ws.example.test',
        'broadcasting.connections.reverb.public_port' => 443,
        'broadcasting.connections.reverb.public_scheme' => 'https',
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('reverb.host', 'ws.example.test')
            ->where('reverb.port', 443)
            ->where('reverb.scheme', 'https')
        );
});

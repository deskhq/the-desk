<?php

namespace App\Support;

class ReverbConfig
{
    /**
     * The browser-facing Reverb connection details, served to the SPA at runtime
     * (via an Inertia shared prop) rather than baked into the JS bundle at build
     * time with VITE_* args. This is what lets a single published image connect
     * to any operator's Reverb server without a rebuild.
     *
     * Host, port, and scheme each default to the server-facing connection (host
     * from the APP_URL origin, port/scheme from REVERB_PORT/REVERB_SCHEME) and are
     * independently overridable — because in a TLS-terminating reverse proxy the
     * browser reaches Reverb on a different host/port/scheme (e.g. wss on 443)
     * than the internal container (http on 8080).
     *
     * @return array{key: string|null, host: string|null, port: int, scheme: string}
     */
    public static function forFrontend(): array
    {
        $reverb = config('broadcasting.connections.reverb');
        $options = $reverb['options'] ?? [];

        $host = $reverb['public_host'] ?: (parse_url((string) config('app.url'), PHP_URL_HOST) ?: null);

        return [
            'key' => $reverb['key'] ?? null,
            'host' => $host,
            'port' => (int) ($reverb['public_port'] ?: $options['port'] ?? 443),
            'scheme' => (string) ($reverb['public_scheme'] ?: $options['scheme'] ?? 'https'),
        ];
    }
}

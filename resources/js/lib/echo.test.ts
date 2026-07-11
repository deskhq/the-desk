import { describe, expect, it } from 'vitest';
import { reverbEchoConfig } from '@/lib/echo';

describe('reverbEchoConfig', () => {
    it('maps runtime reverb config to Echo options with TLS forced on https', () => {
        expect(
            reverbEchoConfig({
                key: 'demo-key',
                host: 'chat.example.test',
                port: 443,
                scheme: 'https',
            }),
        ).toEqual({
            broadcaster: 'reverb',
            key: 'demo-key',
            wsHost: 'chat.example.test',
            wsPort: 443,
            wssPort: 443,
            forceTLS: true,
            enabledTransports: ['ws', 'wss'],
        });
    });

    it('does not force TLS for an http scheme', () => {
        const config = reverbEchoConfig({
            key: 'demo-key',
            host: 'localhost',
            port: 8080,
            scheme: 'http',
        });

        expect(config.forceTLS).toBe(false);
        expect(config.wsPort).toBe(8080);
        expect(config.wssPort).toBe(8080);
    });

    it('coerces a missing key or host to undefined so Echo falls back gracefully', () => {
        const config = reverbEchoConfig({
            key: null,
            host: null,
            port: 443,
            scheme: 'https',
        });

        expect(config.key).toBeUndefined();
        expect(config.wsHost).toBeUndefined();
    });
});

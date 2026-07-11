/**
 * Browser-facing Reverb connection details, provided by the backend at runtime
 * (the `reverb` Inertia shared prop) instead of build-time `VITE_*` env vars.
 * This is what lets a single published image connect to any operator's Reverb
 * server without rebuilding the frontend bundle.
 */
export type ReverbRuntimeConfig = {
    key: string | null;
    host: string | null;
    port: number;
    scheme: string;
};

/**
 * Translate the runtime Reverb config into the options object `configureEcho`
 * expects. TLS is forced for the `https` scheme; a missing key/host collapses to
 * `undefined` so Echo can fall back rather than connecting to a literal "null".
 */
export function reverbEchoConfig(config: ReverbRuntimeConfig) {
    const enabledTransports: ('ws' | 'wss')[] = ['ws', 'wss'];

    return {
        broadcaster: 'reverb' as const,
        key: config.key ?? undefined,
        wsHost: config.host ?? undefined,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports,
    };
}

import { afterEach, expect, it, vi } from 'vitest';

type Listener = (event: never) => void;

function createScope() {
    const listeners = new Map<string, Listener>();

    return {
        listeners,
        skipWaiting: vi.fn(() => Promise.resolve()),
        clients: { claim: vi.fn(() => Promise.resolve()) },
        addEventListener: vi.fn((type: string, listener: Listener) => {
            listeners.set(type, listener);
        }),
    };
}

/**
 * Loads the worker with a stubbed global scope, mimicking how the browser
 * evaluates it: the module wires its listeners as a side effect of importing.
 */
async function loadWorker() {
    const scope = createScope();

    vi.stubGlobal('self', scope);
    vi.resetModules();

    await import('./service-worker');

    return scope;
}

function dispatch(
    scope: ReturnType<typeof createScope>,
    type: string,
    event: unknown,
) {
    const listener = scope.listeners.get(type);

    expect(listener).toBeDefined();

    listener?.(event as never);
}

afterEach(() => {
    vi.unstubAllGlobals();
});

it('activates the newest worker without waiting for open tabs to close', async () => {
    const scope = await loadWorker();

    dispatch(scope, 'install', {});

    expect(scope.skipWaiting).toHaveBeenCalled();
});

it('claims open clients as soon as it activates', async () => {
    const scope = await loadWorker();
    const event = { waitUntil: vi.fn() };

    dispatch(scope, 'activate', event);

    expect(scope.clients.claim).toHaveBeenCalled();
    expect(event.waitUntil).toHaveBeenCalled();
});

it('lets every request fall through to the network, caching nothing', async () => {
    const scope = await loadWorker();
    const event = { respondWith: vi.fn() };

    dispatch(scope, 'fetch', event);

    expect(event.respondWith).not.toHaveBeenCalled();
});

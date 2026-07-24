import { afterEach, describe, expect, it, vi } from 'vitest';

type Listener = (event: never) => void;

type FakeWindow = {
    url: string;
    visibilityState: string;
    focus: ReturnType<typeof vi.fn>;
    navigate: ReturnType<typeof vi.fn>;
};

/**
 * An open app window the worker can find, focus and navigate.
 */
function appWindow(overrides: Partial<FakeWindow> = {}): FakeWindow {
    return {
        url: 'https://desk.test/t/acme/c/general',
        visibilityState: 'hidden',
        focus: vi.fn(() => Promise.resolve()),
        navigate: vi.fn(() => Promise.resolve()),
        ...overrides,
    };
}

function createScope(windows: FakeWindow[] = []) {
    const listeners = new Map<string, Listener>();

    return {
        listeners,
        skipWaiting: vi.fn(() => Promise.resolve()),
        clients: {
            claim: vi.fn(() => Promise.resolve()),
            matchAll: vi.fn(() => Promise.resolve(windows)),
            openWindow: vi.fn(() => Promise.resolve()),
        },
        registration: {
            showNotification: vi.fn<
                (
                    title: string,
                    options?: Record<string, unknown>,
                ) => Promise<void>
            >(() => Promise.resolve()),
        },
        addEventListener: vi.fn((type: string, listener: Listener) => {
            listeners.set(type, listener);
        }),
    };
}

/**
 * Loads the worker with a stubbed global scope, mimicking how the browser
 * evaluates it: the module wires its listeners as a side effect of importing.
 */
async function loadWorker(windows: FakeWindow[] = []) {
    const scope = createScope(windows);

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

/**
 * Dispatch an event whose handler defers its work to `waitUntil`, and settle
 * that work before returning — the worker's async handlers all hand their
 * promise to the browser this way.
 */
async function dispatchAndSettle(
    scope: ReturnType<typeof createScope>,
    type: string,
    event: Record<string, unknown>,
) {
    let held: Promise<unknown> = Promise.resolve();

    dispatch(scope, type, {
        ...event,
        waitUntil: (promise: Promise<unknown>) => {
            held = promise;
        },
    });

    await held;
}

/**
 * A push event carrying the given (already decrypted) payload.
 */
function pushEventData(payload: unknown) {
    return { data: { json: () => payload } };
}

const NEW_MESSAGE = {
    title: 'Ada Lovelace in #deploys',
    body: 'Deploy is green',
    icon: '/icons/icon-192.png',
    badge: '/icons/icon-192.png',
    tag: 'channel-abc',
    renotify: true,
    data: { url: 'https://desk.test/t/acme/c/deploys?message=42' },
};

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

describe('push', () => {
    it('raises a banner for a pushed message', async () => {
        const scope = await loadWorker();

        await dispatchAndSettle(scope, 'push', pushEventData(NEW_MESSAGE));

        expect(scope.registration.showNotification).toHaveBeenCalledWith(
            'Ada Lovelace in #deploys',
            {
                body: 'Deploy is green',
                icon: '/icons/icon-192.png',
                badge: '/icons/icon-192.png',
                tag: 'channel-abc',
                renotify: true,
                data: { url: 'https://desk.test/t/acme/c/deploys?message=42' },
            },
        );
    });

    it('tags the banner per channel so a second message replaces the first', async () => {
        const scope = await loadWorker();

        await dispatchAndSettle(scope, 'push', pushEventData(NEW_MESSAGE));
        await dispatchAndSettle(
            scope,
            'push',
            pushEventData({ ...NEW_MESSAGE, body: 'And rolled out' }),
        );

        const tags = scope.registration.showNotification.mock.calls.map(
            ([, options]) => options?.tag,
        );

        expect(tags).toEqual(['channel-abc', 'channel-abc']);
    });

    it('stays silent while a window of the app is on screen', async () => {
        const scope = await loadWorker([
            appWindow({ visibilityState: 'visible' }),
        ]);

        await dispatchAndSettle(scope, 'push', pushEventData(NEW_MESSAGE));

        expect(scope.registration.showNotification).not.toHaveBeenCalled();
    });

    it('still raises a banner when every window is backgrounded', async () => {
        const scope = await loadWorker([
            appWindow({ visibilityState: 'hidden' }),
        ]);

        await dispatchAndSettle(scope, 'push', pushEventData(NEW_MESSAGE));

        expect(scope.registration.showNotification).toHaveBeenCalled();
    });

    it('ignores a push carrying no payload', async () => {
        const scope = await loadWorker();

        await dispatchAndSettle(scope, 'push', { data: null });

        expect(scope.registration.showNotification).not.toHaveBeenCalled();
    });

    it('ignores a payload that is not the object this app sends', async () => {
        const scope = await loadWorker();

        await dispatchAndSettle(scope, 'push', pushEventData('a bare string'));
        await dispatchAndSettle(
            scope,
            'push',
            pushEventData({ body: 'no title' }),
        );

        expect(scope.registration.showNotification).not.toHaveBeenCalled();
    });

    it('ignores a payload that will not decode', async () => {
        const scope = await loadWorker();

        await dispatchAndSettle(scope, 'push', {
            data: {
                json: () => {
                    throw new SyntaxError('undecryptable');
                },
            },
        });

        expect(scope.registration.showNotification).not.toHaveBeenCalled();
    });

    it('drops payload members that are not strings', async () => {
        const scope = await loadWorker();

        await dispatchAndSettle(
            scope,
            'push',
            pushEventData({ title: 'Ada Lovelace', body: 42, renotify: 'yes' }),
        );

        expect(scope.registration.showNotification).toHaveBeenCalledWith(
            'Ada Lovelace',
            expect.objectContaining({ body: undefined, renotify: false }),
        );
    });
});

describe('notificationclick', () => {
    function clickEvent(data: unknown) {
        return { notification: { close: vi.fn(), data } };
    }

    it('dismisses the banner it was raised from', async () => {
        const scope = await loadWorker();
        const event = clickEvent(NEW_MESSAGE.data);

        await dispatchAndSettle(scope, 'notificationclick', event);

        expect(event.notification.close).toHaveBeenCalled();
    });

    it('opens a window at the message when the app is closed', async () => {
        const scope = await loadWorker();

        await dispatchAndSettle(
            scope,
            'notificationclick',
            clickEvent(NEW_MESSAGE.data),
        );

        expect(scope.clients.openWindow).toHaveBeenCalledWith(
            'https://desk.test/t/acme/c/deploys?message=42',
        );
    });

    it('steers an already-open window to the message instead of opening another', async () => {
        const open = appWindow();
        const scope = await loadWorker([open]);

        await dispatchAndSettle(
            scope,
            'notificationclick',
            clickEvent(NEW_MESSAGE.data),
        );

        expect(open.focus).toHaveBeenCalled();
        expect(open.navigate).toHaveBeenCalledWith(
            'https://desk.test/t/acme/c/deploys?message=42',
        );
        expect(scope.clients.openWindow).not.toHaveBeenCalled();
    });

    it('only focuses a window already showing the message', async () => {
        const open = appWindow({ url: NEW_MESSAGE.data.url });
        const scope = await loadWorker([open]);

        await dispatchAndSettle(
            scope,
            'notificationclick',
            clickEvent(NEW_MESSAGE.data),
        );

        expect(open.focus).toHaveBeenCalled();
        expect(open.navigate).not.toHaveBeenCalled();
    });

    it('opens a fresh window when the open one refuses to navigate', async () => {
        const open = appWindow({
            navigate: vi.fn(() => Promise.reject(new Error('not controlled'))),
        });
        const scope = await loadWorker([open]);

        await dispatchAndSettle(
            scope,
            'notificationclick',
            clickEvent(NEW_MESSAGE.data),
        );

        expect(scope.clients.openWindow).toHaveBeenCalledWith(
            'https://desk.test/t/acme/c/deploys?message=42',
        );
    });

    it('does nothing for a notification carrying no link', async () => {
        const scope = await loadWorker();

        await dispatchAndSettle(scope, 'notificationclick', clickEvent(null));
        await dispatchAndSettle(scope, 'notificationclick', clickEvent({}));
        await dispatchAndSettle(
            scope,
            'notificationclick',
            clickEvent({ url: '' }),
        );

        expect(scope.clients.openWindow).not.toHaveBeenCalled();
    });
});

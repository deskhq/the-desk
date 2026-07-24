/**
 * The event a worker may hold open while it finishes work — the subset of
 * `ExtendableEvent` this worker uses.
 */
interface ExtendableEvent {
    waitUntil(promise: Promise<unknown>): void;
}

/**
 * A push delivered by the browser vendor's push service. `data` is absent for a
 * payload-less push, which the spec permits and this worker ignores.
 */
interface PushEvent extends ExtendableEvent {
    data: { json(): unknown } | null;
}

/**
 * A click on one of this worker's notifications.
 */
interface NotificationEvent extends ExtendableEvent {
    notification: { close(): void; data: unknown };
}

/**
 * One of the app's open windows, as the worker sees it.
 */
interface AppWindow {
    url: string;
    visibilityState: string;
    focus(): Promise<unknown>;
    navigate(url: string): Promise<unknown>;
}

/**
 * The banner options this worker sets. Declared here rather than taken from the
 * DOM's `NotificationOptions`, which omits the service-worker-only members —
 * `renotify` is one, and it is only legal alongside a `tag`.
 */
interface BannerOptions {
    body?: string;
    icon?: string;
    badge?: string;
    tag?: string;
    renotify?: boolean;
    data?: unknown;
}

/**
 * The payload the server encrypts into a push, mirroring what
 * `NotificationChannels\WebPush\WebPushMessage` serialises.
 */
interface PushPayload {
    title?: unknown;
    body?: unknown;
    icon?: unknown;
    badge?: unknown;
    tag?: unknown;
    renotify?: unknown;
    data?: unknown;
}

/**
 * The worker's global scope. The app's tsconfig models `self` as a DOM
 * `Window`, so the worker-only members are declared here instead of pulling the
 * WebWorker lib into the whole program, where its globals clash with the DOM's.
 */
interface ServiceWorkerScope {
    skipWaiting(): Promise<void>;
    clients: {
        claim(): Promise<void>;
        matchAll(options?: {
            type?: string;
            includeUncontrolled?: boolean;
        }): Promise<AppWindow[]>;
        openWindow(url: string): Promise<unknown>;
    };
    registration: {
        showNotification(title: string, options?: BannerOptions): Promise<void>;
    };
    addEventListener(
        type: 'install' | 'activate' | 'fetch',
        listener: (event: ExtendableEvent) => void,
    ): void;
    addEventListener(type: 'push', listener: (event: PushEvent) => void): void;
    addEventListener(
        type: 'notificationclick',
        listener: (event: NotificationEvent) => void,
    ): void;
}

/**
 * The instance's service worker. It makes the app installable and hosts the
 * push handlers below — it caches nothing, so the app is never served from a
 * stale bundle and offline reading stays out of scope.
 */
const worker = self as unknown as ServiceWorkerScope;

worker.addEventListener('install', () => {
    void worker.skipWaiting();
});

worker.addEventListener('activate', (event) => {
    event.waitUntil(worker.clients.claim());
});

// Browsers only treat the app as installable when the worker handles `fetch`.
// This handler deliberately never calls `respondWith`, which hands the request
// back to the browser's default networking — no interception, no cache.
worker.addEventListener('fetch', () => {
    return;
});

worker.addEventListener('push', (event) => {
    event.waitUntil(showBanner(readPayload(event)));
});

worker.addEventListener('notificationclick', (event) => {
    event.notification.close();

    event.waitUntil(openMessage(readTargetUrl(event.notification.data)));
});

/**
 * Decode the pushed payload, tolerating anything that isn't the JSON object
 * this app sends. A push from an unexpected source, or one whose body failed to
 * decrypt, must not throw inside the handler: the browser answers an unhandled
 * push with its own generic "site updated in the background" notice.
 */
function readPayload(event: PushEvent): PushPayload | null {
    try {
        const payload = event.data?.json();

        return typeof payload === 'object' && payload !== null
            ? (payload as PushPayload)
            : null;
    } catch {
        return null;
    }
}

/**
 * Raise the banner, unless the user is already looking at the app.
 *
 * A visible window has alerted them itself — the in-tab chime, the unread badge
 * — so a banner on top of that would be a second alert for one message. This is
 * the whole double-alert suppression: the server always sends, and the device
 * decides whether the user still needs telling.
 */
async function showBanner(payload: PushPayload | null): Promise<void> {
    if (payload === null || typeof payload.title !== 'string') {
        return;
    }

    if (await appIsVisible()) {
        return;
    }

    // The tag collapses a conversation: a second message in the same channel
    // replaces the banner already on screen rather than stacking beside it.
    // `renotify` makes that replacement alert again instead of swapping in
    // silently — and is only legal alongside a tag, so a payload that somehow
    // arrived without one must not carry it either, or showNotification rejects.
    const tag = asString(payload.tag);

    await worker.registration.showNotification(payload.title, {
        body: asString(payload.body),
        icon: asString(payload.icon),
        badge: asString(payload.badge),
        tag,
        renotify: tag !== undefined && payload.renotify === true,
        data: payload.data,
    });
}

/**
 * Focus an open app window on the message, or open one there.
 *
 * Reusing a window is what keeps a click from stacking up tabs over a day of
 * notifications. `navigate` only works on a window this worker controls, so a
 * failure falls back to opening a fresh one rather than leaving the click doing
 * nothing at all.
 */
async function openMessage(url: string | null): Promise<void> {
    if (url === null) {
        return;
    }

    const [existing] = await openWindows();

    if (existing === undefined) {
        await worker.clients.openWindow(url);

        return;
    }

    await existing.focus();

    if (existing.url === url) {
        return;
    }

    try {
        await existing.navigate(url);
    } catch {
        await worker.clients.openWindow(url);
    }
}

/**
 * Whether any of the app's windows is on screen right now.
 */
async function appIsVisible(): Promise<boolean> {
    const windows = await openWindows();

    return windows.some((window) => window.visibilityState === 'visible');
}

/**
 * The app's open windows, including ones this worker version does not yet
 * control: right after an update the previous worker still controls them, and
 * they are just as much "the app is open" as any other.
 */
function openWindows(): Promise<AppWindow[]> {
    return worker.clients.matchAll({
        type: 'window',
        includeUncontrolled: true,
    });
}

/**
 * The deep link a notification carries, or null when it carries none.
 */
function readTargetUrl(data: unknown): string | null {
    if (typeof data !== 'object' || data === null) {
        return null;
    }

    const url = (data as { url?: unknown }).url;

    return typeof url === 'string' && url !== '' ? url : null;
}

/**
 * Narrow a payload member to a string, dropping anything else.
 */
function asString(value: unknown): string | undefined {
    return typeof value === 'string' ? value : undefined;
}

export {};

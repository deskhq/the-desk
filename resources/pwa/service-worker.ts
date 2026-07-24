/**
 * The event a worker may hold open while it finishes work — the subset of
 * `ExtendableEvent` this worker uses.
 */
interface ExtendableEvent {
    waitUntil(promise: Promise<unknown>): void;
}

/**
 * The worker's global scope. The app's tsconfig models `self` as a DOM
 * `Window`, so the worker-only members are declared here instead of pulling the
 * WebWorker lib into the whole program, where its globals clash with the DOM's.
 */
interface ServiceWorkerScope {
    skipWaiting(): Promise<void>;
    clients: { claim(): Promise<void> };
    addEventListener(
        type: string,
        listener: (event: ExtendableEvent) => void,
    ): void;
}

/**
 * The instance's service worker. It exists to make the app installable and to
 * host the push handlers a later issue adds (#532) — it caches nothing, so the
 * app is never served from a stale bundle and offline reading stays out of scope.
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

export {};

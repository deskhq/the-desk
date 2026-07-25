/**
 * Registers the app's service worker from the web root, so its scope covers
 * every route. The worker itself (`resources/pwa/service-worker.ts`) caches
 * nothing — it is what makes the instance installable, and later hosts the
 * push handlers.
 *
 * A failed registration is never fatal: the app keeps working as a plain tab,
 * it just cannot be installed.
 */
export function registerServiceWorker(): void {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    navigator.serviceWorker
        .register('/service-worker.js', { scope: '/' })
        .catch((error: unknown) => {
            console.warn('Service worker registration failed', error);
        });
}

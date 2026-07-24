import {
    destroy as destroySubscription,
    store as storeSubscription,
} from '@/actions/App/Http/Controllers/Settings/PushSubscriptionController';
import { parseXsrfToken } from '@/lib/uploadAttachment';

/**
 * Whether this browser can do web push at all.
 *
 * Three separate capabilities have to line up, and on iOS they only do once the
 * app has been installed to the home screen (Safari 16.4+) — a plain tab there
 * reports no `PushManager`, which is exactly the case this guard covers.
 */
export function pushSupported(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        'serviceWorker' in navigator &&
        typeof window !== 'undefined' &&
        'PushManager' in window &&
        'Notification' in window
    );
}

/**
 * Decode a base64url VAPID key into the raw bytes `pushManager.subscribe()`
 * wants. The server hands the key over in the URL-safe alphabet the spec uses,
 * unpadded; `atob` accepts neither, so both are restored first.
 */
export function urlBase64ToUint8Array(
    base64Url: string,
): Uint8Array<ArrayBuffer> {
    const padded = base64Url.padEnd(
        base64Url.length + ((4 - (base64Url.length % 4)) % 4),
        '=',
    );
    const binary = atob(padded.replace(/-/g, '+').replace(/_/g, '/'));

    // Backed by a plain ArrayBuffer rather than `Uint8Array.from`'s
    // implementation-defined buffer, which is what `applicationServerKey`
    // accepts as a BufferSource.
    const bytes = new Uint8Array(new ArrayBuffer(binary.length));

    for (let index = 0; index < binary.length; index++) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

/**
 * This device's push subscription, or null when it has none.
 *
 * Read from the browser rather than the server, because the browser is the
 * authority: a subscription can be revoked from the site settings, or dropped
 * when the push service rotates its keys, without the server ever hearing.
 */
export async function currentSubscription(): Promise<PushSubscription | null> {
    if (!pushSupported()) {
        return null;
    }

    const registration = await navigator.serviceWorker.ready;

    return registration.pushManager.getSubscription();
}

/**
 * Ask for permission, subscribe this device, and register it with the server.
 *
 * Resolves false when the user dismisses or blocks the permission prompt —
 * their answer, not an error. `userVisibleOnly` is mandatory: every push this
 * app sends raises a banner, and Chrome refuses to subscribe without the
 * promise that it will.
 */
export async function enablePush(vapidPublicKey: string): Promise<boolean> {
    if (!pushSupported()) {
        return false;
    }

    if ((await Notification.requestPermission()) !== 'granted') {
        return false;
    }

    const registration = await navigator.serviceWorker.ready;

    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
        }));

    await send(storeSubscription().url, 'POST', subscription.toJSON());

    return true;
}

/**
 * Unsubscribe this device and forget it server-side.
 *
 * The server is told first: if the browser-side unsubscribe then fails, the
 * worst case is a device that stays subscribed but is never pushed to, rather
 * than one the server keeps pushing at forever.
 */
export async function disablePush(): Promise<void> {
    const subscription = await currentSubscription();

    if (subscription === null) {
        return;
    }

    await send(destroySubscription().url, 'DELETE', {
        endpoint: subscription.endpoint,
    });

    await subscription.unsubscribe();
}

/**
 * A same-origin JSON write carrying the CSRF header the stateful `web` guard
 * expects. These endpoints answer 204 and change no rendered state, so they are
 * called directly rather than through an Inertia visit that would reload every
 * shared prop for nothing.
 */
async function send(
    url: string,
    method: 'POST' | 'DELETE',
    body: unknown,
): Promise<void> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const token = parseXsrfToken(document.cookie);

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    const response = await fetch(url, {
        method,
        headers,
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error('push-subscription-failed');
    }
}

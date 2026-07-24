import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    currentSubscription,
    disablePush,
    enablePush,
    pushSupported,
    urlBase64ToUint8Array,
} from '@/lib/push';

const SUBSCRIPTION_JSON = {
    endpoint: 'https://push.example.test/device-one',
    keys: { p256dh: 'device-key', auth: 'device-auth' },
};

/** The shape both push endpoints are called with. */
type JsonRequest = {
    method: string;
    headers: Record<string, string>;
    body: string;
};

/**
 * A browser that can do push, with an optional existing subscription.
 */
function stubBrowser(
    options: {
        existing?: unknown;
        permission?: NotificationPermission;
        subscribe?: ReturnType<typeof vi.fn>;
    } = {},
) {
    const getSubscription = vi.fn(() =>
        Promise.resolve(options.existing ?? null),
    );
    const subscribe =
        options.subscribe ??
        vi.fn(() =>
            Promise.resolve({
                ...SUBSCRIPTION_JSON,
                toJSON: () => SUBSCRIPTION_JSON,
                unsubscribe: vi.fn(() => Promise.resolve(true)),
            }),
        );
    const requestPermission = vi.fn(() =>
        Promise.resolve(options.permission ?? 'granted'),
    );

    vi.stubGlobal('navigator', {
        serviceWorker: {
            ready: Promise.resolve({
                pushManager: { getSubscription, subscribe },
            }),
        },
    });
    vi.stubGlobal('window', { PushManager: class {}, Notification: class {} });
    vi.stubGlobal('Notification', { requestPermission });
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=tok%20en' });

    const fetchMock = vi.fn<
        (url: string, init: JsonRequest) => Promise<{ ok: boolean }>
    >(() => Promise.resolve({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);

    return { getSubscription, subscribe, requestPermission, fetchMock };
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('urlBase64ToUint8Array', () => {
    it('decodes an unpadded url-safe key', () => {
        // "hello" as base64url, unpadded.
        expect(Array.from(urlBase64ToUint8Array('aGVsbG8'))).toEqual([
            104, 101, 108, 108, 111,
        ]);
    });

    it('restores the url-safe alphabet before decoding', () => {
        // Bytes 0xFB 0xFF 0xBF encode as "-_-_" only in the url-safe alphabet.
        expect(Array.from(urlBase64ToUint8Array('-_-_'))).toEqual([
            251, 255, 191,
        ]);
    });

    it('produces bytes backed by a plain ArrayBuffer, as subscribe() requires', () => {
        expect(urlBase64ToUint8Array('aGVsbG8').buffer).toBeInstanceOf(
            ArrayBuffer,
        );
    });
});

describe('pushSupported', () => {
    it('is true when the browser has all three capabilities', () => {
        stubBrowser();

        expect(pushSupported()).toBe(true);
    });

    it('is false without a PushManager, as in a plain iOS tab', () => {
        stubBrowser();
        vi.stubGlobal('window', { Notification: class {} });

        expect(pushSupported()).toBe(false);
    });

    it('is false without a service worker', () => {
        stubBrowser();
        vi.stubGlobal('navigator', {});

        expect(pushSupported()).toBe(false);
    });
});

describe('enablePush', () => {
    it('subscribes the device and registers it with the server', async () => {
        const { subscribe, fetchMock } = stubBrowser();

        await expect(enablePush('aGVsbG8')).resolves.toBe(true);

        expect(subscribe).toHaveBeenCalledWith(
            expect.objectContaining({ userVisibleOnly: true }),
        );

        const [url, init] = fetchMock.mock.calls[0];

        expect(url).toBe('/settings/push-subscriptions');
        expect(init.method).toBe('POST');
        expect(init.headers['X-XSRF-TOKEN']).toBe('tok en');
        expect(JSON.parse(init.body)).toEqual(SUBSCRIPTION_JSON);
    });

    it('reuses a subscription the browser already holds', async () => {
        const { subscribe, fetchMock } = stubBrowser({
            existing: {
                ...SUBSCRIPTION_JSON,
                toJSON: () => SUBSCRIPTION_JSON,
            },
        });

        await expect(enablePush('aGVsbG8')).resolves.toBe(true);

        expect(subscribe).not.toHaveBeenCalled();
        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it('gives up quietly when the user declines the permission prompt', async () => {
        const { subscribe, fetchMock } = stubBrowser({ permission: 'denied' });

        await expect(enablePush('aGVsbG8')).resolves.toBe(false);

        expect(subscribe).not.toHaveBeenCalled();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('does nothing on a browser that cannot do push', async () => {
        stubBrowser();
        vi.stubGlobal('window', {});

        await expect(enablePush('aGVsbG8')).resolves.toBe(false);
    });

    it('throws when the server refuses the subscription', async () => {
        stubBrowser();
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve({ ok: false })),
        );

        await expect(enablePush('aGVsbG8')).rejects.toThrow(
            'push-subscription-failed',
        );
    });
});

describe('disablePush', () => {
    const unsubscribe = vi.fn(() => Promise.resolve(true));

    beforeEach(() => {
        unsubscribe.mockClear();
    });

    it('revokes the device server-side before unsubscribing it', async () => {
        const { fetchMock } = stubBrowser({
            existing: { ...SUBSCRIPTION_JSON, unsubscribe },
        });

        await disablePush();

        const [url, init] = fetchMock.mock.calls[0];

        expect(url).toBe('/settings/push-subscriptions');
        expect(init.method).toBe('DELETE');
        expect(JSON.parse(init.body)).toEqual({
            endpoint: SUBSCRIPTION_JSON.endpoint,
        });
        expect(unsubscribe).toHaveBeenCalled();
    });

    it('does nothing when this device was never subscribed', async () => {
        const { fetchMock } = stubBrowser();

        await disablePush();

        expect(fetchMock).not.toHaveBeenCalled();
    });
});

describe('currentSubscription', () => {
    it('reads the subscription back from the browser', async () => {
        stubBrowser({ existing: SUBSCRIPTION_JSON });

        await expect(currentSubscription()).resolves.toEqual(SUBSCRIPTION_JSON);
    });

    it('is null on a browser that cannot do push', async () => {
        stubBrowser();
        vi.stubGlobal('window', {});

        await expect(currentSubscription()).resolves.toBeNull();
    });
});

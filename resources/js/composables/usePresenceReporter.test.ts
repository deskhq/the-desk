import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createRenderer, defineComponent } from 'vue';

const page = {
    props: {
        presence: { awayAfterMinutes: 10 },
    },
};

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

vi.mock('@/routes/presence', () => ({
    report: () => ({ url: '/presence/connection' }),
    release: () => ({ url: '/presence/connection/release' }),
}));

import {
    PRESENCE_CONNECTION_STORAGE_KEY,
    PRESENCE_HEARTBEAT_MS,
    PRESENCE_REGISTER_DELAY_MS,
    usePresenceReporter,
} from '@/composables/usePresenceReporter';

const { createApp } = createRenderer<object, object>({
    insert: () => {},
    remove: () => {},
    createElement: () => ({}),
    createText: () => ({}),
    createComment: () => ({}),
    setText: () => {},
    setElementText: () => {},
    parentNode: () => null,
    nextSibling: () => null,
    patchProp: () => {},
});

/** Handlers registered on document/window, by event name. */
const handlers = new Map<string, (event?: unknown) => void>();

const store = new Map<string, string>();
const fetchMock = vi.fn(() => Promise.resolve());

/** The bodies posted so far, newest last, as `[url, payload]` pairs. */
function posts(): [string, Record<string, unknown>][] {
    return fetchMock.mock.calls.map((call) => {
        const [url, init] = call as unknown as [string, { body: string }];

        return [url, JSON.parse(init.body) as Record<string, unknown>];
    });
}

function mount() {
    const app = createApp(
        defineComponent({
            setup: () => {
                usePresenceReporter();

                return () => null;
            },
        }),
    );

    app.mount({});

    return { unmount: () => app.unmount() };
}

/** Fire an activity event the way the browser would. */
function activity(event = 'keydown'): void {
    handlers.get(event)?.();
}

beforeEach(() => {
    vi.useFakeTimers();
    handlers.clear();
    store.clear();
    fetchMock.mockClear();

    const listenerTarget = {
        addEventListener: (name: string, handler: (event?: unknown) => void) =>
            handlers.set(name, handler),
        removeEventListener: (name: string) => handlers.delete(name),
    };

    vi.stubGlobal('document', { ...listenerTarget, cookie: '' });
    vi.stubGlobal('window', listenerTarget);
    vi.stubGlobal('fetch', fetchMock);
    vi.stubGlobal('sessionStorage', {
        getItem: (key: string) => store.get(key) ?? null,
        setItem: (key: string, value: string) => store.set(key, value),
    });
    vi.stubGlobal('crypto', { randomUUID: () => 'tab-uuid' });
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('usePresenceReporter', () => {
    it('registers the tab as active shortly after it opens, off the load path', () => {
        const { unmount } = mount();

        expect(posts()).toEqual([]);

        vi.advanceTimersByTime(PRESENCE_REGISTER_DELAY_MS);

        expect(posts()).toEqual([
            [
                '/presence/connection',
                { connection: 'tab-uuid', state: 'active' },
            ],
        ]);

        unmount();
    });

    it('reuses the key already minted for this tab', () => {
        store.set(PRESENCE_CONNECTION_STORAGE_KEY, 'existing-tab');

        const { unmount } = mount();
        vi.advanceTimersByTime(PRESENCE_REGISTER_DELAY_MS);

        expect(posts()[0][1].connection).toBe('existing-tab');

        unmount();
    });

    it('keeps the tab key across remounts so a page visit is not a new device', () => {
        mount().unmount();
        fetchMock.mockClear();

        const { unmount } = mount();
        vi.advanceTimersByTime(PRESENCE_REGISTER_DELAY_MS);

        expect(posts()[0][1].connection).toBe('tab-uuid');

        unmount();
    });

    it('reports away once the configured threshold passes with no activity', () => {
        const { unmount } = mount();
        fetchMock.mockClear();

        vi.advanceTimersByTime(10 * 60 * 1000 - 1);

        expect(posts().some(([, body]) => body.state === 'away')).toBe(false);

        vi.advanceTimersByTime(1);

        expect(posts()).toContainEqual([
            '/presence/connection',
            { connection: 'tab-uuid', state: 'away' },
        ]);

        unmount();
    });

    it('honours an operator-configured threshold', () => {
        page.props.presence.awayAfterMinutes = 2;

        const { unmount } = mount();
        vi.advanceTimersByTime(PRESENCE_REGISTER_DELAY_MS);
        fetchMock.mockClear();

        vi.advanceTimersByTime(2 * 60 * 1000 - PRESENCE_REGISTER_DELAY_MS);

        expect(posts()).toHaveLength(1);
        expect(posts()[0][1].state).toBe('away');

        unmount();
        page.props.presence.awayAfterMinutes = 10;
    });

    it('stays active while the person keeps working', () => {
        const { unmount } = mount();
        fetchMock.mockClear();

        for (let minute = 0; minute < 30; minute++) {
            vi.advanceTimersByTime(60 * 1000);
            activity();
        }

        expect(posts().filter(([, body]) => body.state === 'away')).toEqual([]);

        unmount();
    });

    it('comes back on the first input after going away', () => {
        const { unmount } = mount();

        vi.advanceTimersByTime(10 * 60 * 1000);
        fetchMock.mockClear();

        activity('pointerdown');

        expect(posts()).toEqual([
            [
                '/presence/connection',
                { connection: 'tab-uuid', state: 'active' },
            ],
        ]);

        unmount();
    });

    it('treats regaining window focus as activity', () => {
        const { unmount } = mount();

        vi.advanceTimersByTime(10 * 60 * 1000);
        fetchMock.mockClear();

        activity('focus');

        expect(posts()[0][1].state).toBe('active');

        unmount();
    });

    it('does not re-post on every keystroke while already active', () => {
        const { unmount } = mount();
        fetchMock.mockClear();

        activity();
        activity();
        activity();

        expect(posts()).toEqual([]);

        unmount();
    });

    it('heartbeats so a crashed tab eventually ages out', () => {
        const { unmount } = mount();
        fetchMock.mockClear();

        vi.advanceTimersByTime(PRESENCE_HEARTBEAT_MS);

        expect(posts()).toContainEqual([
            '/presence/connection',
            { connection: 'tab-uuid', state: 'active' },
        ]);

        unmount();
    });

    it('releases the tab as the page goes away', () => {
        const { unmount } = mount();
        fetchMock.mockClear();

        activity('pagehide');

        expect(posts()).toEqual([
            ['/presence/connection/release', { connection: 'tab-uuid' }],
        ]);

        unmount();
    });

    it('sends the release with keepalive so it survives the unload', () => {
        const { unmount } = mount();
        fetchMock.mockClear();

        activity('pagehide');

        const [, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            { keepalive?: boolean },
        ];

        expect(init.keepalive).toBe(true);

        unmount();
    });

    it('stops reporting once the app is torn down', () => {
        const { unmount } = mount();

        unmount();
        fetchMock.mockClear();

        vi.advanceTimersByTime(60 * 60 * 1000);

        expect(posts()).toEqual([]);
    });
});

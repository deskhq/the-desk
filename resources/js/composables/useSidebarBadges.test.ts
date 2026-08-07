import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createRenderer, defineComponent } from 'vue';

const reload = vi.fn();
const flushByCacheTags = vi.fn();
const page = {
    props: {
        auth: { user: { id: 'viewer-id' } },
        channels: [] as { id: string }[],
        channel: undefined as { id?: string } | undefined,
    },
};

/** Listeners registered per Echo channel name, keyed by event name. */
const listeners = new Map<string, Map<string, (payload: unknown) => void>>();
const left: string[] = [];

vi.mock('@inertiajs/vue3', () => ({
    router: {
        reload: (...args: unknown[]) => reload(...args),
        flushByCacheTags: (...args: unknown[]) => flushByCacheTags(...args),
    },
    usePage: () => page,
}));

vi.mock('@laravel/echo-vue', () => ({
    echo: () => ({
        private(name: string) {
            const channel = {
                listen(event: string, handler: (payload: unknown) => void) {
                    const events =
                        listeners.get(name) ??
                        new Map<string, (payload: unknown) => void>();
                    events.set(event, handler);
                    listeners.set(name, events);

                    return channel;
                },
            };

            return channel;
        },
        leave: (name: string) => left.push(name),
    }),
}));

import { useSidebarBadges } from '@/composables/useSidebarBadges';

/**
 * A no-op custom renderer mounts a real component instance under Node (no DOM),
 * which is what fires the composable's `onMounted` subscription and its
 * `onBeforeUnmount` teardown. An effectScope alone never mounts either hook.
 */
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

/** Mount the composable, exposing its teardown. */
function harness(): { unmount: () => void } {
    const app = createApp(
        defineComponent({
            setup() {
                useSidebarBadges();

                return () => null;
            },
        }),
    );
    app.mount({});

    return { unmount: () => app.unmount() };
}

/** Fire an event on a subscribed Echo channel, as Reverb would. */
function emit(channel: string, event: string, payload: unknown = {}): void {
    listeners.get(channel)?.get(event)?.(payload);
}

/** A teammate's arrival, carrying only what the sidebar reads off it. */
function arrival(): unknown {
    return {
        id: 'msg-1',
        user: { id: 'someone-else' },
        mentions: [],
        threadRootId: null,
        sentToChannel: false,
    };
}

describe('useSidebarBadges cross-device read sync', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        reload.mockClear();
        flushByCacheTags.mockClear();
        page.props.channels = [];
        page.props.channel = undefined;
        listeners.clear();
        left.length = 0;
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('reloads the badge props when another device advances the read pointer', () => {
        harness();

        emit('user.viewer-id', 'ReadStateAdvanced');

        expect(reload).not.toHaveBeenCalled();

        vi.runAllTimers();

        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({
                // One prop answers every badge the shell draws. `channels`
                // rides along because an arrival also reorders the DM group and
                // can earn a new row, and `unreadThreadCount` so the Threads
                // panel's tally refreshes on the same debounced reload.
                only: ['unread', 'channels', 'unreadThreadCount'],
            }),
        );
    });

    it('collapses a burst of read signals into a single reload', () => {
        harness();

        emit('user.viewer-id', 'ReadStateAdvanced');
        emit('user.viewer-id', 'ReadStateAdvanced');
        emit('user.viewer-id', 'ReadStateAdvanced');

        vi.runAllTimers();

        expect(reload).toHaveBeenCalledTimes(1);
    });

    /**
     * The fleet is already subscribed to every channel in the sidebar, which is
     * exactly the set the rows prefetch — so the event that makes a prefetched
     * timeline wrong is already arriving, and flushing its tag is what stops the
     * click that follows from painting a message-old screen.
     */
    it('flushes the prefetched entry of the channel a message arrived in', () => {
        page.props.channels = [{ id: 'ch-1' }, { id: 'ch-2' }];
        harness();

        emit('channel.ch-1', 'MessageSent', arrival());

        expect(flushByCacheTags).toHaveBeenCalledWith('channel:ch-1');
    });

    it('leaves the entries of the channels nothing arrived in alone', () => {
        page.props.channels = [{ id: 'ch-1' }, { id: 'ch-2' }];
        harness();

        emit('channel.ch-1', 'MessageSent', arrival());

        expect(flushByCacheTags).not.toHaveBeenCalledWith('channel:ch-2');
    });

    it('leaves the private channel on teardown', () => {
        const { unmount } = harness();

        unmount();

        expect(left).toContain('user.viewer-id');
    });
});

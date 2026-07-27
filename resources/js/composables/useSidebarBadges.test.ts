import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createRenderer, defineComponent } from 'vue';

const reload = vi.fn();
const page = {
    props: {
        auth: { user: { id: 'viewer-id' } },
        channels: [],
        channel: undefined as { id?: string } | undefined,
    },
};

/** Listeners registered per Echo channel name, keyed by event name. */
const listeners = new Map<string, Map<string, (payload: unknown) => void>>();
const left: string[] = [];

vi.mock('@inertiajs/vue3', () => ({
    router: {
        reload: (...args: unknown[]) => reload(...args),
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
function emit(channel: string, event: string): void {
    listeners.get(channel)?.get(event)?.({});
}

describe('useSidebarBadges cross-device read sync', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        reload.mockClear();
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
                // `unreadThreadCount` and `teams` ride along so the Threads
                // panel's tally and the cross-workspace dots refresh on the same
                // debounced reload the channel badges already use.
                only: [
                    'channels',
                    'hasUnreadThreads',
                    'unreadThreadCount',
                    'teams',
                ],
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

    it('leaves the private channel on teardown', () => {
        const { unmount } = harness();

        unmount();

        expect(left).toContain('user.viewer-id');
    });
});

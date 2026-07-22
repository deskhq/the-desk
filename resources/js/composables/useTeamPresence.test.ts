import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createRenderer, defineComponent, nextTick } from 'vue';

const reload = vi.fn();

const page = {
    props: {
        teamMembers: [] as {
            id: string;
            name: string;
            presence?: 'active' | 'away';
        }[],
    },
};

/** Roster handlers registered per presence channel, by hook name. */
type Handlers = {
    here?: (members: { id: string; name: string }[]) => void;
    joining?: (member: { id: string; name: string }) => void;
    leaving?: (member: { id: string; name: string }) => void;
    listeners: Map<string, (payload: never) => void>;
};

const channels = new Map<string, Handlers>();
const left: string[] = [];

vi.mock('@inertiajs/vue3', () => ({
    router: { reload: (...args: unknown[]) => reload(...args) },
    usePage: () => page,
}));

vi.mock('@laravel/echo-vue', () => ({
    echo: () => ({
        join(name: string) {
            const handlers: Handlers = channels.get(name) ?? {
                listeners: new Map(),
            };
            channels.set(name, handlers);

            const chain = {
                here(callback: Handlers['here']) {
                    handlers.here = callback;

                    return chain;
                },
                joining(callback: Handlers['joining']) {
                    handlers.joining = callback;

                    return chain;
                },
                leaving(callback: Handlers['leaving']) {
                    handlers.leaving = callback;

                    return chain;
                },
                listen(event: string, callback: (payload: never) => void) {
                    handlers.listeners.set(event, callback);

                    return chain;
                },
            };

            return chain;
        },
        leave: (name: string) => left.push(name),
    }),
}));

import { useTeamPresence } from '@/composables/useTeamPresence';

/**
 * A no-op renderer mounts a real component instance under Node (no DOM), which
 * is what fires the composable's `onMounted` subscription.
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
    setScopeId: () => {},
});

function mount() {
    let api!: ReturnType<typeof useTeamPresence>;

    const app = createApp(
        defineComponent({
            setup() {
                api = useTeamPresence(() => 'team-1');

                return () => null;
            },
        }),
    );

    app.mount({});

    return { api, unmount: () => app.unmount() };
}

const roster = () => channels.get('team.team-1')!;

beforeEach(() => {
    channels.clear();
    left.length = 0;
    reload.mockClear();
    page.props.teamMembers = [];
});

afterEach(() => {
    vi.useRealTimers();
});

describe('useTeamPresence', () => {
    it('reports a member absent from the roster as offline', () => {
        const { api, unmount } = mount();

        roster().here!([]);

        expect(api.presenceFor('maya')).toBe('offline');

        unmount();
    });

    it('reports a roster member with no reported state as active', () => {
        const { api, unmount } = mount();

        roster().here!([{ id: 'maya', name: 'Maya' }]);

        expect(api.presenceFor('maya')).toBe('active');

        unmount();
    });

    it('seeds a joining client from the presence carried in the initial props', () => {
        page.props.teamMembers = [
            { id: 'maya', name: 'Maya', presence: 'away' },
            { id: 'jonas', name: 'Jonas', presence: 'active' },
        ];

        const { api, unmount } = mount();

        roster().here!([
            { id: 'maya', name: 'Maya' },
            { id: 'jonas', name: 'Jonas' },
        ]);

        expect(api.presenceFor('maya')).toBe('away');
        expect(api.presenceFor('jonas')).toBe('active');

        unmount();
    });

    it('patches a member live when they go away, with no reload', () => {
        const { api, unmount } = mount();

        roster().here!([{ id: 'maya', name: 'Maya' }]);
        roster().listeners.get('UserPresenceChanged')!({
            id: 'maya',
            state: 'away',
        } as never);

        expect(api.presenceFor('maya')).toBe('away');
        expect(reload).not.toHaveBeenCalled();

        unmount();
    });

    it('patches them back to active on their next activity', () => {
        const { api, unmount } = mount();

        roster().here!([{ id: 'maya', name: 'Maya' }]);
        roster().listeners.get('UserPresenceChanged')!({
            id: 'maya',
            state: 'away',
        } as never);
        roster().listeners.get('UserPresenceChanged')!({
            id: 'maya',
            state: 'active',
        } as never);

        expect(api.presenceFor('maya')).toBe('active');

        unmount();
    });

    it('prefers a live flip over the state the props were seeded with', () => {
        page.props.teamMembers = [{ id: 'maya', name: 'Maya', presence: 'away' }];

        const { api, unmount } = mount();

        roster().here!([{ id: 'maya', name: 'Maya' }]);
        roster().listeners.get('UserPresenceChanged')!({
            id: 'maya',
            state: 'active',
        } as never);

        expect(api.presenceFor('maya')).toBe('active');

        unmount();
    });

    it('renders a member who leaves as offline, whatever they last reported', () => {
        const { api, unmount } = mount();

        roster().here!([{ id: 'maya', name: 'Maya' }]);
        roster().listeners.get('UserPresenceChanged')!({
            id: 'maya',
            state: 'away',
        } as never);
        roster().leaving!({ id: 'maya', name: 'Maya' });

        expect(api.presenceFor('maya')).toBe('offline');

        unmount();
    });

    it('forgets a stale flip so a returning member re-reads the fresh props', () => {
        page.props.teamMembers = [
            { id: 'maya', name: 'Maya', presence: 'active' },
        ];

        const { api, unmount } = mount();

        roster().here!([{ id: 'maya', name: 'Maya' }]);
        roster().listeners.get('UserPresenceChanged')!({
            id: 'maya',
            state: 'away',
        } as never);
        roster().leaving!({ id: 'maya', name: 'Maya' });
        roster().joining!({ id: 'maya', name: 'Maya' });

        expect(api.presenceFor('maya')).toBe('active');

        unmount();
    });

    it('still reloads every prop when a teammate changes their profile', async () => {
        vi.useFakeTimers();

        const { unmount } = mount();

        roster().listeners.get('UserProfileUpdated')!(undefined as never);

        await vi.advanceTimersByTimeAsync(500);
        await nextTick();

        expect(reload).toHaveBeenCalledTimes(1);

        unmount();
    });

    it('leaves the channel on unmount', () => {
        const { unmount } = mount();

        unmount();

        expect(left).toContain('team.team-1');
    });
});

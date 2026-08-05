import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createRenderer, defineComponent, nextTick, reactive, ref } from 'vue';
import type { MaybeRefOrGetter } from 'vue';

const reload = vi.fn();

// Reactive like the real usePage() page, so computeds inside the composable
// re-read a prop the (simulated) profile reload has since replaced.
const page = reactive({
    props: {
        teamMembers: [] as {
            id: string;
            name: string;
            presence?: 'active' | 'away';
            isDnd?: boolean;
        }[],
    },
});

/**
 * Roster handlers registered per presence channel, by hook name.
 *
 * Every hook is a *list*, because Echo caches one channel per name and each
 * `.here()` / `.listen()` on it adds a callback rather than replacing the last —
 * which is exactly how a second subscriber ends up doubling the work.
 */
type Handlers = {
    here: ((members: { id: string; name: string }[]) => void)[];
    joining: ((member: { id: string; name: string }) => void)[];
    leaving: ((member: { id: string; name: string }) => void)[];
    listeners: Map<string, ((payload: never) => void)[]>;
};

const channels = new Map<string, Handlers>();
const joined: string[] = [];
const left: string[] = [];

vi.mock('@inertiajs/vue3', () => ({
    router: { reload: (...args: unknown[]) => reload(...args) },
    usePage: () => page,
}));

vi.mock('@laravel/echo-vue', () => ({
    echo: () => ({
        join(name: string) {
            joined.push(name);

            const handlers: Handlers = channels.get(name) ?? {
                here: [],
                joining: [],
                leaving: [],
                listeners: new Map(),
            };
            channels.set(name, handlers);

            const chain = {
                here(callback: Handlers['here'][number]) {
                    handlers.here.push(callback);

                    return chain;
                },
                joining(callback: Handlers['joining'][number]) {
                    handlers.joining.push(callback);

                    return chain;
                },
                leaving(callback: Handlers['leaving'][number]) {
                    handlers.leaving.push(callback);

                    return chain;
                },
                listen(event: string, callback: (payload: never) => void) {
                    handlers.listeners.set(event, [
                        ...(handlers.listeners.get(event) ?? []),
                        callback,
                    ]);

                    return chain;
                },
            };

            return chain;
        },
        leave: (name: string) => {
            channels.delete(name);
            left.push(name);
        },
    }),
}));

import {
    useTeamPresence,
    useTeamPresenceSubscription,
} from '@/composables/useTeamPresence';
import { TEAM_MEMBER_PROPS } from '@/lib/reloadProps';

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
    patchProp: () => {},
    setScopeId: () => {},
});

/**
 * A subscriber, standing in for the shell or the channel page. The reader half
 * is module state, so {@link useTeamPresence} answers from anywhere — the tests
 * call it outside a component the way a deep dot component calls it inside one.
 */
function mount(teamId: MaybeRefOrGetter<string | undefined> = () => 'team-1') {
    const app = createApp(
        defineComponent({
            setup() {
                useTeamPresenceSubscription(teamId);

                return () => null;
            },
        }),
    );

    app.mount({});

    return { api: useTeamPresence(), unmount: () => app.unmount() };
}

const roster = () => channels.get('team.team-1')!;

/** Broadcast the roster snapshot to every `here` callback bound to the channel. */
function here(members: { id: string; name: string }[]): void {
    roster().here.forEach((callback) => callback(members));
}

function joining(member: { id: string; name: string }): void {
    roster().joining.forEach((callback) => callback(member));
}

function leaving(member: { id: string; name: string }): void {
    roster().leaving.forEach((callback) => callback(member));
}

/** Broadcast a server event to every listener bound to it, as Echo does. */
function broadcast(event: string, payload?: unknown): void {
    roster()
        .listeners.get(event)!
        .forEach((callback) => callback(payload as never));
}

beforeEach(() => {
    channels.clear();
    joined.length = 0;
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

        here([]);

        expect(api.presenceFor('maya')).toBe('offline');

        unmount();
    });

    it('reports a roster member with no reported state as active', () => {
        const { api, unmount } = mount();

        here([{ id: 'maya', name: 'Maya' }]);

        expect(api.presenceFor('maya')).toBe('active');

        unmount();
    });

    it('seeds a joining client from the presence carried in the initial props', () => {
        page.props.teamMembers = [
            { id: 'maya', name: 'Maya', presence: 'away' },
            { id: 'jonas', name: 'Jonas', presence: 'active' },
        ];

        const { api, unmount } = mount();

        here([
            { id: 'maya', name: 'Maya' },
            { id: 'jonas', name: 'Jonas' },
        ]);

        expect(api.presenceFor('maya')).toBe('away');
        expect(api.presenceFor('jonas')).toBe('active');

        unmount();
    });

    it('reports no dnd at all when the teamMembers prop is absent', () => {
        page.props.teamMembers =
            undefined as unknown as typeof page.props.teamMembers;

        const { api, unmount } = mount();

        expect(api.isDndFor('maya')).toBe(false);

        unmount();
    });

    it('re-reads the dnd flag when the profile reload refreshes the prop', () => {
        page.props.teamMembers = [
            { id: 'maya', name: 'Maya', presence: 'active', isDnd: false },
        ];

        const { api, unmount } = mount();

        expect(api.isDndFor('maya')).toBe(false);

        // Stand in for the debounced reload landing fresh props after a
        // teammate's UserProfileUpdated broadcast.
        page.props.teamMembers = [
            { id: 'maya', name: 'Maya', presence: 'active', isDnd: true },
        ];

        expect(api.isDndFor('maya')).toBe(true);

        unmount();
    });

    it('reads a member dnd flag from the shared teamMembers prop', () => {
        page.props.teamMembers = [
            { id: 'maya', name: 'Maya', presence: 'active', isDnd: true },
            { id: 'jonas', name: 'Jonas', presence: 'active' },
        ];

        const { api, unmount } = mount();

        expect(api.isDndFor('maya')).toBe(true);
        expect(api.isDndFor('jonas')).toBe(false);
        expect(api.isDndFor('missing')).toBe(false);

        unmount();
    });

    it('patches a member live when they go away, with no reload', () => {
        const { api, unmount } = mount();

        here([{ id: 'maya', name: 'Maya' }]);
        broadcast('UserPresenceChanged', {
            id: 'maya',
            state: 'away',
        } as never);

        expect(api.presenceFor('maya')).toBe('away');
        expect(reload).not.toHaveBeenCalled();

        unmount();
    });

    it('patches them back to active on their next activity', () => {
        const { api, unmount } = mount();

        here([{ id: 'maya', name: 'Maya' }]);
        broadcast('UserPresenceChanged', {
            id: 'maya',
            state: 'away',
        } as never);
        broadcast('UserPresenceChanged', {
            id: 'maya',
            state: 'active',
        } as never);

        expect(api.presenceFor('maya')).toBe('active');

        unmount();
    });

    it('prefers a live flip over the state the props were seeded with', () => {
        page.props.teamMembers = [
            { id: 'maya', name: 'Maya', presence: 'away' },
        ];

        const { api, unmount } = mount();

        here([{ id: 'maya', name: 'Maya' }]);
        broadcast('UserPresenceChanged', {
            id: 'maya',
            state: 'active',
        } as never);

        expect(api.presenceFor('maya')).toBe('active');

        unmount();
    });

    it('renders a member who leaves as offline, whatever they last reported', () => {
        const { api, unmount } = mount();

        here([{ id: 'maya', name: 'Maya' }]);
        broadcast('UserPresenceChanged', {
            id: 'maya',
            state: 'away',
        } as never);
        leaving({ id: 'maya', name: 'Maya' });

        expect(api.presenceFor('maya')).toBe('offline');

        unmount();
    });

    it('forgets a stale flip so a returning member re-reads the fresh props', () => {
        page.props.teamMembers = [
            { id: 'maya', name: 'Maya', presence: 'active' },
        ];

        const { api, unmount } = mount();

        here([{ id: 'maya', name: 'Maya' }]);
        broadcast('UserPresenceChanged', {
            id: 'maya',
            state: 'away',
        } as never);
        leaving({ id: 'maya', name: 'Maya' });
        joining({ id: 'maya', name: 'Maya' });

        expect(api.presenceFor('maya')).toBe('active');

        unmount();
    });

    it('asks for the roster, and only the roster, when a teammate changes their profile', async () => {
        vi.useFakeTimers();

        const { unmount } = mount();

        broadcast('UserProfileUpdated', undefined as never);

        await vi.advanceTimersByTimeAsync(500);
        await nextTick();

        // This fired with no `only` at all until #1252 — a whole page of props
        // on a teammate's timing, to move a do-not-disturb crescent. The bound
        // list is the regression: a reload without `only` is answered with the
        // page as the route stood when it left, and replaces every prop with it.
        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: TEAM_MEMBER_PROPS }),
        );

        unmount();
    });

    it('leaves the channel on unmount', () => {
        const { unmount } = mount();

        unmount();

        expect(left).toContain('team.team-1');
    });

    it('joins the channel once however many subscribers mount', () => {
        const first = mount();
        const second = mount();

        expect(joined).toEqual(['team.team-1']);

        first.unmount();
        second.unmount();
    });

    it('reloads once per broadcast, not once per subscriber', async () => {
        vi.useFakeTimers();

        const first = mount();
        const second = mount();

        broadcast('UserProfileUpdated');

        await vi.advanceTimersByTimeAsync(500);
        await nextTick();

        expect(reload).toHaveBeenCalledTimes(1);

        first.unmount();
        second.unmount();
    });

    it('keeps the channel while another subscriber is still mounted', () => {
        const first = mount();
        const second = mount();

        first.unmount();

        expect(left).not.toContain('team.team-1');

        second.unmount();

        expect(left).toContain('team.team-1');
    });

    it('keeps the roster readable when one subscriber unmounts', () => {
        const first = mount();
        const second = mount();

        here([{ id: 'maya', name: 'Maya' }]);
        first.unmount();

        expect(second.api.presenceFor('maya')).toBe('active');

        second.unmount();
    });

    it('leaves the roster standing when a second subscriber arrives', () => {
        const first = mount();

        here([{ id: 'maya', name: 'Maya' }]);

        const second = mount();

        expect(second.api.presenceFor('maya')).toBe('active');

        first.unmount();
        second.unmount();
    });

    it('follows a team switch, leaving the old channel for the new one', async () => {
        const teamId = ref<string | undefined>('team-1');
        const { api, unmount } = mount(teamId);

        here([{ id: 'maya', name: 'Maya' }]);

        teamId.value = 'team-2';
        await nextTick();

        expect(left).toEqual(['team.team-1']);
        expect(joined).toEqual(['team.team-1', 'team.team-2']);
        // A stale team's presence must never bleed into the next one's roster.
        expect(api.presenceFor('maya')).toBe('offline');

        unmount();
    });

    it('reads everyone as offline before anything has subscribed', () => {
        expect(useTeamPresence().presenceFor('maya')).toBe('offline');
    });
});

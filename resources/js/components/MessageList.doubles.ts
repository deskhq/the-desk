import { vi } from 'vitest';
import type { Mock } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import {
    provideMessageActions,
    provideMessageSubtree,
} from '@/composables/useMessageActionsContext';
import type {
    MessageActionHandlers,
    MessageActionsContext,
    MessageSubtreeContext,
} from '@/composables/useMessageActionsContext';
import { translate } from '@/lib/i18n';
import type { Message } from '@/types';

/**
 * The doubles the timeline's suites share: the Inertia props its leaves read,
 * the message factory, the stub component shapes, and the mount that stands a
 * component up under a provided message-actions facade.
 *
 * The facade is the seam (ADR-0009): every suite here asserts against these
 * spies rather than threading a handler down as a prop, which is what lets a
 * row, the hover toolbar and the touch sheet each mount on their own.
 *
 * A `vi.mock` factory is hoisted above the imports, so a suite pulls what it
 * needs from here with a dynamic `import()` inside the factory.
 */
export const inertiaPageProps = {
    auth: { user: { timezone: 'UTC' } },
    customEmojis: {} as Record<string, string>,
    userGroups: [] as Array<{ id: string }>,
    frequentEmojis: [] as string[],
};

/** Renders nothing, standing in for a leaf whose own tests cover it. */
export function inert(name: string): Component {
    return defineComponent({ name, setup: () => () => null });
}

/** Renders an empty marker element, so a stubbed leaf is still findable. */
export function marker(name: string): Component {
    return defineComponent({
        name,
        setup: () => () => h('div', { 'data-stub': name }),
    });
}

/** Renders a child's default slot, so a stubbed wrapper stays transparent. */
export function passthrough(name: string): Component {
    return defineComponent({
        name,
        setup:
            (_props, { slots }) =>
            () =>
                h('div', { 'data-stub': name }, slots.default?.()),
    });
}

/** A wrapper that hands its slot the `{ open }` a popover trigger reads. */
export function popover(name: string): Component {
    return defineComponent({
        name,
        setup:
            (_props, { slots }) =>
            () =>
                h('div', slots.default?.({ open: false })),
    });
}

export function message(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        clientUuid: 'uuid-1',
        body: 'hello',
        type: 'standard',
        user: { id: 'peer', name: 'Peer' },
        createdAt: '2024-03-04T10:30:00.000Z',
        editedAt: null,
        isDeleted: false,
        mentions: [],
        linkPreviews: [],
        attachments: [],
        reactions: [],
        pin: null,
        poll: null,
        replyTo: null,
        forwardedFrom: null,
        threadRootId: null,
        sentToChannel: false,
        threadReplyCount: 0,
        threadLastReplyAt: null,
        threadParticipants: [],
        threadFollowed: false,
        threadUnread: false,
        threadUnreadReplyCount: 0,
        ...overrides,
    } as Message;
}

/**
 * The facade's handlers as spies, so a suite can read `.mock.calls` on any of
 * them while the bag still satisfies {@link MessageActionHandlers}.
 */
export type MockedHandlers = {
    [K in keyof MessageActionHandlers]: Mock<MessageActionHandlers[K]>;
};

/** Every action a row can ask for, as spies. */
export function actionsDouble(): MockedHandlers {
    return {
        react: vi.fn(),
        vote: vi.fn(),
        closePoll: vi.fn(),
        pin: vi.fn(),
        unpin: vi.fn(),
        remind: vi.fn(),
        remindCustom: vi.fn(),
        forward: vi.fn(),
        jump: vi.fn(),
        edit: vi.fn(),
        delete: vi.fn(),
        reply: vi.fn(),
        openThread: vi.fn(),
    };
}

let active: Array<{ app: App; host: HTMLElement }> = [];

export type Mounted = {
    host: HTMLElement;
    /** The provided facade's spies, which every action lands on. */
    actions: MockedHandlers;
    /** The subtree's mention sink, which a hover card lands on. */
    mention: MessageSubtreeContext['mention'];
    /** What the component emitted, in order, as `[name, ...payload]`. */
    events: Array<[string, ...unknown[]]>;
};

/**
 * Mount `component` under a provided facade, the way the channel page provides
 * one. `scope` and `subtree` override the viewer capabilities and the timeline
 * scope a suite cares about; everything else stays at a permissive default.
 *
 * Every `onX` in `props` is also captured into `events`, so a suite can assert
 * on what the component emitted without wiring a listener per test.
 */
export function mountWithActions(
    component: Component,
    props: Record<string, unknown> = {},
    overrides: {
        scope?: Omit<Partial<MessageActionsContext>, 'actions'>;
        subtree?: Partial<MessageSubtreeContext>;
    } = {},
): Mounted {
    const events: Array<[string, ...unknown[]]> = [];
    const actions = actionsDouble();
    const mention = overrides.subtree?.mention ?? vi.fn();
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(
        defineComponent({
            setup() {
                provideMessageActions({
                    currentUserId: 'me',
                    canReact: true,
                    canPin: true,
                    canModerate: false,
                    viewerTimeZone: 'UTC',
                    ...overrides.scope,
                    actions,
                });
                provideMessageSubtree({
                    inThread: false,
                    ...overrides.subtree,
                    mention,
                });

                return () => h(component, capture(props, events));
            },
        }),
    );
    app.config.globalProperties.$t = translate;
    app.mount(host);
    active.push({ app, host });

    return { host, actions, mention, events };
}

/**
 * Wrap each `onX` listener so the emit is recorded before it runs, leaving a
 * suite free to pass its own listener on top.
 */
function capture(
    props: Record<string, unknown>,
    events: Array<[string, ...unknown[]]>,
): Record<string, unknown> {
    return Object.fromEntries(
        Object.entries(props).map(([key, value]) => {
            if (!/^on[A-Z]/.test(key) || typeof value !== 'function') {
                return [key, value];
            }

            const name = key.slice(2, 3).toLowerCase() + key.slice(3);

            return [
                key,
                (...payload: unknown[]) => {
                    events.push([name, ...payload]);
                    (value as (...args: unknown[]) => void)(...payload);
                },
            ];
        }),
    );
}

export function unmountAll(): void {
    for (const { app, host } of active.splice(0)) {
        app.unmount();
        host.remove();
    }

    active = [];
}

export function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

export function all(host: HTMLElement, selector: string): HTMLElement[] {
    return [...host.querySelectorAll<HTMLElement>(`[data-test="${selector}"]`)];
}

export function stub(host: HTMLElement, name: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-stub="${name}"]`);
}

export function click(host: HTMLElement, selector: string): void {
    find(host, selector)?.click();
}

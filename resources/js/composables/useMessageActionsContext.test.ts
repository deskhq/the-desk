// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App, Component } from 'vue';
import { computed, createApp, defineComponent, h, reactive } from 'vue';
import {
    provideMessageActions,
    provideMessageSubtree,
    useMessageActionGuards,
    useMessageActionsContext,
    useMessageSubtree,
} from '@/composables/useMessageActionsContext';
import type {
    MessageActionHandlers,
    MessageActionsContext,
    MessageSubtreeContext,
} from '@/composables/useMessageActionsContext';

/**
 * Covers the seam the message-action relay collapsed into: what the page
 * provides, what a subtree narrows, and the guard context a row builds from the
 * two. Every consumer reaches these through the named pair, so what is pinned
 * here is the accessor's contract — including the throw that makes a missing
 * provider a mount-time error rather than an `undefined` at click time.
 */
function handlers(): MessageActionHandlers {
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

function context(
    overrides: Partial<MessageActionsContext> = {},
): MessageActionsContext {
    return {
        currentUserId: 'me',
        canReact: true,
        canPin: true,
        canModerate: false,
        viewerTimeZone: 'UTC',
        actions: handlers(),
        ...overrides,
    };
}

function subtree(
    overrides: Partial<MessageSubtreeContext> = {},
): MessageSubtreeContext {
    return { inThread: false, mention: vi.fn(), ...overrides };
}

let active: Array<{ app: App; host: HTMLElement }> = [];

/**
 * Mount `child` under a provider, so the accessors run exactly where a real
 * consumer runs them: inside a descendant's own `setup`.
 */
function mount(child: Component, provided?: () => void): HTMLElement {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(
        defineComponent({
            setup() {
                provided?.();

                return () => h(child);
            },
        }),
    );
    app.mount(host);
    active.push({ app, host });

    return host;
}

/** A leaf that runs `read` in its own setup and reports what it saw. */
function reader<T>(read: () => T, seen: { value: T | null }): Component {
    return defineComponent({
        setup() {
            seen.value = read();

            return () => h('div');
        },
    });
}

afterEach(() => {
    for (const { app, host } of active) {
        app.unmount();
        host.remove();
    }

    active = [];
});

describe('the message-actions context', () => {
    it('hands a descendant exactly what the page provided', () => {
        const provided = context();
        const seen: { value: MessageActionsContext | null } = { value: null };

        mount(
            reader(() => useMessageActionsContext(), seen),
            () => provideMessageActions(provided),
        );

        expect(seen.value).toBe(provided);
    });

    it('throws where a provider is missing, rather than reading undefined later', () => {
        const seen: { value: MessageActionsContext | null } = { value: null };

        expect(() =>
            mount(reader(() => useMessageActionsContext(), seen)),
        ).toThrow('No message-actions context was provided');
    });

    it('tracks a capability the page revises after mount', () => {
        const page = reactive({ canReact: true });
        const provided = reactive({
            ...context(),
            canReact: computed(() => page.canReact),
        });
        const seen: { value: MessageActionsContext | null } = { value: null };

        mount(
            reader(() => useMessageActionsContext(), seen),
            () => provideMessageActions(provided),
        );

        page.canReact = false;

        expect(seen.value!.canReact).toBe(false);
    });
});

describe('the per-subtree context', () => {
    it('hands a descendant the scope its own subtree provided', () => {
        const provided = subtree({ inThread: true });
        const seen: { value: MessageSubtreeContext | null } = { value: null };

        mount(
            reader(() => useMessageSubtree(), seen),
            () => provideMessageSubtree(provided),
        );

        expect(seen.value).toBe(provided);
    });

    it('throws where a subtree provider is missing', () => {
        const seen: { value: MessageSubtreeContext | null } = { value: null };

        expect(() => mount(reader(() => useMessageSubtree(), seen))).toThrow(
            'No message subtree was provided',
        );
    });
});

describe('the guard context for one row', () => {
    /** Build the guard context a row with the given pending state resolves against. */
    function guards(
        overrides: Partial<MessageActionsContext> = {},
        scope: Partial<MessageSubtreeContext> = {},
    ) {
        const seen: {
            value: ReturnType<typeof useMessageActionGuards> | null;
        } = { value: null };

        mount(
            reader(() => useMessageActionGuards(), seen),
            () => {
                provideMessageActions(context(overrides));
                provideMessageSubtree(subtree(scope));
            },
        );

        return seen.value!;
    }

    it('resolves the viewer capabilities, the subtree and the row into one shape', () => {
        const { contextFor } = guards(
            { currentUserId: 'me', canModerate: true },
            { inThread: true },
        );

        expect(contextFor(true)).toEqual({
            currentUserId: 'me',
            canReact: true,
            canPin: true,
            canModerate: true,
            inThread: true,
            pending: true,
        });
    });

    it('leaves the pending flag to the row, since only it knows', () => {
        const { contextFor } = guards();

        expect(contextFor(false).pending).toBe(false);
        expect(contextFor(true).pending).toBe(true);
    });
});

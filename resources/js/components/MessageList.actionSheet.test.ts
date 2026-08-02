// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, reactive } from 'vue';
import { LONG_PRESS_MS } from '@/composables/useLongPress';
import {
    find,
    message,
    mountWithActions,
    unmountAll,
} from './MessageList.doubles';

/**
 * Covers the touch stand-in for the hover toolbar: the actions sheet a hold on a
 * message row opens, the rows it refuses to open on, and the two ways it closes
 * itself again. It survives a split of `MessageList.vue` unchanged, so what is
 * pinned here is when it opens and which message it resolves.
 */
const mobile = vi.hoisted(() => ({
    current: null as { value: boolean } | null,
}));

vi.mock('@inertiajs/vue3', async () => {
    const { inertiaPageProps } = await import('./MessageList.doubles');

    return { usePage: () => ({ props: inertiaPageProps }) };
});

vi.mock('@/composables/useIsMobile', async () => {
    const { ref } = await import('vue');
    const value = ref(false);
    mobile.current = value;

    return { useIsMobile: () => value };
});

vi.mock('@/components/MessageActions.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('MessageActions') };
});

/** Reports the sheet's open state and the message it resolved, nothing more. */
vi.mock('@/components/MessageActionsSheet.vue', () => ({
    default: defineComponent({
        name: 'MessageActionsSheetStub',
        props: {
            open: { type: Boolean, default: false },
            message: { type: Object, default: null },
        },
        setup: (props) => () =>
            h('div', {
                'data-test': 'actions-sheet',
                'data-open': String(props.open),
                'data-message': props.message?.id ?? '',
            }),
    }),
}));

vi.mock('@/components/UserHoverCard.vue', () => ({
    default: defineComponent({
        name: 'UserHoverCardStub',
        setup:
            (_props, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
}));

vi.mock('@/components/MessageAttachments.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('MessageAttachments') };
});

vi.mock('@/components/MessagePoll.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('MessagePoll') };
});

vi.mock('@/components/MessageReactions.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('MessageReactions') };
});

vi.mock('@/components/MessageForward.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('MessageForward') };
});

import MessageList from './MessageList.vue';

function mount(
    props: Record<string, unknown> = {},
    canReact = true,
): HTMLElement {
    return mountWithActions(
        MessageList,
        { messages: [message()], teamSlug: 'acme', ...props },
        { scope: { currentUserId: 'peer', canReact } },
    ).host;
}

/** A pointer event shaped the way the row's long-press handlers read it. */
function pointer(type: string, target: HTMLElement): PointerEvent {
    const event = new Event(type, { bubbles: true, cancelable: true });

    Object.defineProperties(event, {
        clientX: { value: 0 },
        clientY: { value: 0 },
        target: { value: target },
        pointerType: { value: 'touch' },
    });

    return event as PointerEvent;
}

/** Hold the given row long enough for the sheet's gesture to fire. */
async function hold(host: HTMLElement, selector: string): Promise<void> {
    const row = find(host, selector) as HTMLElement;
    row.dispatchEvent(pointer('pointerdown', row));
    vi.advanceTimersByTime(LONG_PRESS_MS);
    await nextTick();
}

function sheetOpen(host: HTMLElement): string | undefined {
    return find(host, 'actions-sheet')?.getAttribute('data-open') ?? undefined;
}

beforeEach(() => {
    vi.useFakeTimers();

    if (mobile.current) {
        mobile.current.value = false;
    }
});

afterEach(() => {
    unmountAll();
    vi.useRealTimers();
});

describe('the touch actions sheet', () => {
    it('opens on a hold below the breakpoint, resolving the message live', async () => {
        mobile.current!.value = true;
        const host = mount();
        await hold(host, 'message-body');

        expect(sheetOpen(host)).toBe('true');
        expect(find(host, 'actions-sheet')?.getAttribute('data-message')).toBe(
            'm1',
        );
    });

    it('stays shut on a hover-capable layout, where the toolbar owns the actions', async () => {
        const host = mount();
        await hold(host, 'message-body');

        expect(sheetOpen(host)).toBe('false');
    });

    it('stays shut on a row that offers no actions at all', async () => {
        mobile.current!.value = true;
        const host = mount({ messages: [message({ isDeleted: true })] }, false);

        await hold(host, 'message-tombstone');

        expect(sheetOpen(host)).toBe('false');
    });

    it('closes when the viewport crosses up over the breakpoint mid-press', async () => {
        mobile.current!.value = true;
        const host = mount();
        await hold(host, 'message-body');

        mobile.current!.value = false;
        await nextTick();

        expect(sheetOpen(host)).toBe('false');
    });

    it('closes when the message it is open on is tombstoned in place', async () => {
        mobile.current!.value = true;
        const messages = reactive([message()]);
        const host = mount({ messages });

        await hold(host, 'message-body');

        expect(sheetOpen(host)).toBe('true');

        messages[0].isDeleted = true;
        await nextTick();

        expect(sheetOpen(host)).toBe('false');
    });
});

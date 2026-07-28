// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick, reactive } from 'vue';
import { LONG_PRESS_MS } from '@/composables/useLongPress';
import { translate } from '@/lib/i18n';
import type { Message } from '@/types';

/**
 * Covers the touch stand-in for the hover toolbar: the actions sheet a hold on a
 * message row opens, the rows it refuses to open on, and the two ways it closes
 * itself again. It survives a split of `MessageList.vue` unchanged, so what is
 * pinned here is when it opens and which message it resolves.
 */
const pageProps = vi.hoisted(() => ({
    auth: { user: { timezone: 'UTC' } },
    customEmojis: {} as Record<string, string>,
    userGroups: [] as unknown[],
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
}));

const mobile = vi.hoisted(() => ({
    current: null as { value: boolean } | null,
}));

vi.mock('@/composables/useIsMobile', async () => {
    const { ref } = await import('vue');
    const value = ref(false);
    mobile.current = value;

    return { useIsMobile: () => value };
});

/** Renders a child's default slot, so a stubbed wrapper stays transparent. */
function passthrough(name: string) {
    return defineComponent({
        name,
        setup:
            (_props, { slots }) =>
            () =>
                h('div', { 'data-stub': name }, slots.default?.()),
    });
}

/** Renders an empty marker element, so a stubbed leaf is still findable. */
function marker(name: string) {
    return defineComponent({
        name,
        setup: () => () => h('div', { 'data-stub': name }),
    });
}

vi.mock('@/components/MessageActions.vue', () => ({
    default: marker('MessageActions'),
}));

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

vi.mock('@/components/ui/hover-card', () => ({
    HoverCard: passthrough('HoverCard'),
    HoverCardTrigger: passthrough('HoverCardTrigger'),
    HoverCardContent: passthrough('HoverCardContent'),
}));

vi.mock('@/components/ui/dialog', () => ({
    Dialog: defineComponent({
        name: 'DialogStub',
        props: { open: { type: Boolean, default: false } },
        setup:
            (props, { slots }) =>
            () =>
                props.open
                    ? h(
                          'div',
                          { 'data-test': 'delete-dialog' },
                          slots.default?.(),
                      )
                    : null,
    }),
    DialogClose: passthrough('DialogClose'),
    DialogContent: passthrough('DialogContent'),
    DialogDescription: passthrough('DialogDescription'),
    DialogFooter: passthrough('DialogFooter'),
    DialogHeader: passthrough('DialogHeader'),
    DialogTitle: passthrough('DialogTitle'),
}));

vi.mock('@/components/MessageAttachments.vue', () => ({
    default: marker('MessageAttachments'),
}));

vi.mock('@/components/MessagePoll.vue', () => ({
    default: marker('MessagePoll'),
}));

vi.mock('@/components/MessageReactions.vue', () => ({
    default: marker('MessageReactions'),
}));

vi.mock('@/components/MessageForward.vue', () => ({
    default: marker('MessageForward'),
}));

vi.mock('@/components/LinkPreview.vue', () => ({
    default: marker('LinkPreview'),
}));

import MessageList from './MessageList.vue';

function message(overrides: Partial<Message> = {}): Message {
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

let active: Array<{ app: App; host: HTMLElement }> = [];

function mount(props: Record<string, unknown> = {}): HTMLElement {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp({
        render: () =>
            h(MessageList, {
                messages: [message()],
                teamSlug: 'acme',
                currentUserId: 'peer',
                canReact: true,
                ...props,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);
    active.push({ app, host });

    return host;
}

function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
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

beforeEach(() => {
    if (mobile.current) {
        mobile.current.value = false;
    }
});

afterEach(() => {
    for (const { app, host } of active) {
        app.unmount();
        host.remove();
    }

    active = [];
});

describe('the touch actions sheet', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    async function hold(host: HTMLElement): Promise<void> {
        const body = find(host, 'message-body') as HTMLElement;
        body.dispatchEvent(pointer('pointerdown', body));
        vi.advanceTimersByTime(LONG_PRESS_MS);
        await nextTick();
    }

    it('opens on a hold below the breakpoint, resolving the message live', async () => {
        mobile.current!.value = true;
        const host = mount();
        await hold(host);

        const sheet = find(host, 'actions-sheet');

        expect(sheet?.getAttribute('data-open')).toBe('true');
        expect(sheet?.getAttribute('data-message')).toBe('m1');
    });

    it('stays shut on a hover-capable layout, where the toolbar owns the actions', async () => {
        const host = mount();
        await hold(host);

        expect(find(host, 'actions-sheet')?.getAttribute('data-open')).toBe(
            'false',
        );
    });

    it('stays shut on a row that offers no actions at all', async () => {
        mobile.current!.value = true;
        const host = mount({
            messages: [message({ isDeleted: true })],
            canReact: false,
        });

        const tombstone = find(host, 'message-tombstone') as HTMLElement;
        tombstone.dispatchEvent(pointer('pointerdown', tombstone));
        vi.advanceTimersByTime(LONG_PRESS_MS);
        await nextTick();

        expect(find(host, 'actions-sheet')?.getAttribute('data-open')).toBe(
            'false',
        );
    });

    it('closes when the viewport crosses up over the breakpoint mid-press', async () => {
        mobile.current!.value = true;
        const host = mount();
        await hold(host);

        mobile.current!.value = false;
        await nextTick();

        expect(find(host, 'actions-sheet')?.getAttribute('data-open')).toBe(
            'false',
        );
    });

    it('closes when the message it is open on is tombstoned in place', async () => {
        mobile.current!.value = true;
        const messages = reactive([message()]);
        const host = document.createElement('div');
        document.body.appendChild(host);

        const app = createApp({
            render: () =>
                h(MessageList, {
                    messages,
                    teamSlug: 'acme',
                    currentUserId: 'peer',
                    canReact: true,
                }),
        });
        app.config.globalProperties.$t = translate;
        app.mount(host);
        active.push({ app, host });

        await hold(host);

        expect(find(host, 'actions-sheet')?.getAttribute('data-open')).toBe(
            'true',
        );

        messages[0].isDeleted = true;
        await nextTick();

        expect(find(host, 'actions-sheet')?.getAttribute('data-open')).toBe(
            'false',
        );
    });
});

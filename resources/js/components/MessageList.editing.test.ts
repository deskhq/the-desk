// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import { translate } from '@/lib/i18n';
import type { Message } from '@/types';

/**
 * Covers the two pieces of state the timeline owns on top of its rows: the
 * inline editor a row swaps into, and the delete confirmation standing between
 * the toolbar and the emit. Both survive a split of `MessageList.vue` unchanged,
 * so what is pinned here is when each opens, what it suppresses while open, and
 * which event it emits on the way out.
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

/**
 * Stands in for the hover toolbar with a button per event the timeline listens
 * for, so the row's response to `edit` and `delete` is driven the way the real
 * toolbar drives it.
 */
vi.mock('@/components/MessageActions.vue', () => ({
    default: defineComponent({
        name: 'MessageActionsStub',
        emits: ['edit', 'delete'],
        setup:
            (_props, { emit }) =>
            () =>
                h('div', [
                    h(
                        'button',
                        {
                            'data-test': 'stub-edit',
                            onClick: () => emit('edit'),
                        },
                        'edit',
                    ),
                    h(
                        'button',
                        {
                            'data-test': 'stub-delete',
                            onClick: () => emit('delete'),
                        },
                        'delete',
                    ),
                ]),
    }),
}));

vi.mock('@/components/MessageActionsSheet.vue', () => ({
    default: marker('MessageActionsSheet'),
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

type Mounted = {
    host: HTMLElement;
    edits: Array<[string, string]>;
    deletes: string[];
};

function mount(props: Record<string, unknown> = {}): Mounted {
    const edits: Array<[string, string]> = [];
    const deletes: string[] = [];
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp({
        render: () =>
            h(MessageList, {
                messages: [message()],
                teamSlug: 'acme',
                currentUserId: 'peer',
                canReact: true,
                onEdit: (target: Message, body: string) =>
                    edits.push([target.id, body]),
                onDelete: (target: Message) => deletes.push(target.id),
                ...props,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);
    active.push({ app, host });

    return { host, edits, deletes };
}

function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

function click(host: HTMLElement, selector: string): void {
    find(host, selector)?.click();
}

async function startEditing(host: HTMLElement): Promise<HTMLTextAreaElement> {
    click(host, 'stub-edit');
    await nextTick();

    return find(host, 'message-edit-input') as HTMLTextAreaElement;
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

describe('editing a message inline', () => {
    it('swaps the body for a textarea seeded with it', async () => {
        const { host } = mount();
        const field = await startEditing(host);

        expect(field.value).toBe('hello');
        expect(find(host, 'message-body')).toBeNull();
        expect(find(host, 'message-hover-time')).not.toBeNull();
    });

    it('names both keys that end the edit', async () => {
        const { host } = mount();
        await startEditing(host);

        expect(host.textContent).toContain('Enter to save · Esc to cancel');
    });

    it('hides the toolbar, reactions and cards of the row being edited', async () => {
        const { host } = mount({
            messages: [
                message({
                    attachments: [{ id: 'a1' }] as Message['attachments'],
                    poll: { id: 'p1' } as Message['poll'],
                }),
            ],
        });
        await startEditing(host);

        expect(
            host.querySelector('[data-stub="MessageAttachments"]'),
        ).toBeNull();
        expect(host.querySelector('[data-stub="MessagePoll"]')).toBeNull();
        expect(host.querySelector('[data-stub="MessageReactions"]')).toBeNull();
        expect(find(host, 'stub-edit')).toBeNull();
    });

    it('saves the edited body on Enter and leaves the editor', async () => {
        const { host, edits } = mount();
        const field = await startEditing(host);

        field.value = 'hello again';
        field.dispatchEvent(new Event('input'));
        await nextTick();
        field.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );
        await nextTick();

        expect(edits).toEqual([['m1', 'hello again']]);
        expect(find(host, 'message-edit-input')).toBeNull();
    });

    it('saves from the Save button too', async () => {
        const { host, edits } = mount();
        const field = await startEditing(host);

        field.value = 'from the button';
        field.dispatchEvent(new Event('input'));
        await nextTick();
        [...host.querySelectorAll('button')]
            .find((button) => button.textContent?.trim() === 'Save')
            ?.click();
        await nextTick();

        expect(edits).toEqual([['m1', 'from the button']]);
    });

    it('trims the draft before saving it', async () => {
        const { host, edits } = mount();
        const field = await startEditing(host);

        field.value = '   spaced   ';
        field.dispatchEvent(new Event('input'));
        await nextTick();
        field.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );
        await nextTick();

        expect(edits).toEqual([['m1', 'spaced']]);
    });

    it('treats an unchanged draft as a no-op', async () => {
        const { host, edits } = mount();
        const field = await startEditing(host);

        field.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );
        await nextTick();

        expect(edits).toEqual([]);
        expect(find(host, 'message-edit-input')).toBeNull();
    });

    it('treats an emptied draft as a no-op, since the server would reject it', async () => {
        const { host, edits } = mount();
        const field = await startEditing(host);

        field.value = '   ';
        field.dispatchEvent(new Event('input'));
        await nextTick();
        field.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );
        await nextTick();

        expect(edits).toEqual([]);
    });

    it('abandons the edit on Esc', async () => {
        const { host, edits } = mount();
        const field = await startEditing(host);

        field.value = 'discarded';
        field.dispatchEvent(new Event('input'));
        await nextTick();
        field.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await nextTick();

        expect(edits).toEqual([]);
        expect(find(host, 'message-edit-input')).toBeNull();
        expect(find(host, 'message-body')?.textContent?.trim()).toBe('hello');
    });

    it('abandons the edit from the Cancel button', async () => {
        const { host, edits } = mount();
        await startEditing(host);

        [...host.querySelectorAll('button')]
            .find((button) => button.textContent?.trim() === 'Cancel')
            ?.click();
        await nextTick();

        expect(edits).toEqual([]);
        expect(find(host, 'message-edit-input')).toBeNull();
    });
});

describe('deleting a message', () => {
    it('asks before deleting, and emits only once confirmed', async () => {
        const { host, deletes } = mount();

        expect(find(host, 'delete-dialog')).toBeNull();

        click(host, 'stub-delete');
        await nextTick();

        expect(find(host, 'delete-dialog')).not.toBeNull();
        expect(host.textContent).toContain(
            "Are you sure you want to delete this message? This can't be undone.",
        );
        expect(deletes).toEqual([]);

        click(host, 'delete-message-confirm');
        await nextTick();

        expect(deletes).toEqual(['m1']);
        expect(find(host, 'delete-dialog')).toBeNull();
    });
});

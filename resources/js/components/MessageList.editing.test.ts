// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import type { Message } from '@/types';
import {
    click,
    find,
    message,
    mountWithActions,
    unmountAll,
} from './MessageList.doubles';

/**
 * Covers the two pieces of state the timeline owns on top of its rows: the
 * inline editor a row swaps into, and the delete confirmation standing between
 * the toolbar and the write. Both survive a split of `MessageList.vue`
 * unchanged, so what is pinned here is when each opens, what it suppresses while
 * open, and which action it reaches on the way out.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { inertiaPageProps } = await import('./MessageList.doubles');

    return { usePage: () => ({ props: inertiaPageProps }) };
});

vi.mock('@/composables/useIsMobile', async () => {
    const { ref } = await import('vue');
    const value = ref(false);

    return { useIsMobile: () => value };
});

/**
 * Stands in for the hover toolbar with a button per event the timeline listens
 * for, so the row's response to `startEdit` and `requestDelete` is driven the
 * way the real toolbar drives it.
 */
vi.mock('@/components/MessageActions.vue', () => ({
    default: defineComponent({
        name: 'MessageActionsStub',
        emits: ['startEdit', 'requestDelete'],
        setup:
            (_props, { emit }) =>
            () =>
                h('div', [
                    h('button', {
                        'data-test': 'stub-edit',
                        onClick: () => emit('startEdit'),
                    }),
                    h('button', {
                        'data-test': 'stub-delete',
                        onClick: () => emit('requestDelete'),
                    }),
                ]),
    }),
}));

vi.mock('@/components/MessageActionsSheet.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('MessageActionsSheet') };
});

vi.mock('@/components/UserHoverCard.vue', () => ({
    default: defineComponent({
        name: 'UserHoverCardStub',
        setup:
            (_props, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
}));

vi.mock('@/components/ui/dialog', async () => {
    const { passthrough } = await import('./MessageList.doubles');

    return {
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
    };
});

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

function mount(props: Record<string, unknown> = {}) {
    return mountWithActions(MessageList, {
        messages: [message()],
        teamSlug: 'acme',
        ...props,
    });
}

async function startEditing(host: HTMLElement): Promise<HTMLTextAreaElement> {
    click(host, 'stub-edit');
    await nextTick();

    return find(host, 'message-edit-input') as HTMLTextAreaElement;
}

/** Type into the editor the way the editor's own `v-model` reads it. */
async function type(field: HTMLTextAreaElement, text: string): Promise<void> {
    field.value = text;
    field.dispatchEvent(new Event('input'));
    await nextTick();
}

async function press(field: HTMLTextAreaElement, key: string): Promise<void> {
    field.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));
    await nextTick();
}

async function pressButton(host: HTMLElement, label: string): Promise<void> {
    [...host.querySelectorAll('button')]
        .find((button) => button.textContent?.trim() === label)
        ?.click();
    await nextTick();
}

afterEach(unmountAll);

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
        const { host, actions } = mount();
        const field = await startEditing(host);

        await type(field, 'hello again');
        await press(field, 'Enter');

        expect(actions.edit).toHaveBeenCalledExactlyOnceWith(
            expect.objectContaining({ id: 'm1' }),
            'hello again',
        );
        expect(find(host, 'message-edit-input')).toBeNull();
    });

    it('saves from the Save button too', async () => {
        const { host, actions } = mount();
        const field = await startEditing(host);

        await type(field, 'from the button');
        await pressButton(host, 'Save');

        expect(actions.edit).toHaveBeenCalledExactlyOnceWith(
            expect.anything(),
            'from the button',
        );
    });

    it('trims the draft before saving it', async () => {
        const { host, actions } = mount();
        const field = await startEditing(host);

        await type(field, '   spaced   ');
        await press(field, 'Enter');

        expect(actions.edit).toHaveBeenCalledExactlyOnceWith(
            expect.anything(),
            'spaced',
        );
    });

    it('treats an unchanged draft as a no-op', async () => {
        const { host, actions } = mount();
        const field = await startEditing(host);

        await press(field, 'Enter');

        expect(actions.edit).not.toHaveBeenCalled();
        expect(find(host, 'message-edit-input')).toBeNull();
    });

    it('treats an emptied draft as a no-op, since the server would reject it', async () => {
        const { host, actions } = mount();
        const field = await startEditing(host);

        await type(field, '   ');
        await press(field, 'Enter');

        expect(actions.edit).not.toHaveBeenCalled();
    });

    it('abandons the edit on Esc', async () => {
        const { host, actions } = mount();
        const field = await startEditing(host);

        await type(field, 'discarded');
        await press(field, 'Escape');

        expect(actions.edit).not.toHaveBeenCalled();
        expect(find(host, 'message-edit-input')).toBeNull();
        expect(find(host, 'message-body')?.textContent?.trim()).toBe('hello');
    });

    it('abandons the edit from the Cancel button', async () => {
        const { host, actions } = mount();
        await startEditing(host);

        await pressButton(host, 'Cancel');

        expect(actions.edit).not.toHaveBeenCalled();
        expect(find(host, 'message-edit-input')).toBeNull();
    });
});

describe('deleting a message', () => {
    it('asks before deleting, and writes only once confirmed', async () => {
        const { host, actions } = mount();

        expect(find(host, 'delete-dialog')).toBeNull();

        click(host, 'stub-delete');
        await nextTick();

        expect(find(host, 'delete-dialog')).not.toBeNull();
        expect(host.textContent).toContain(
            "Are you sure you want to delete this message? This can't be undone.",
        );
        expect(actions.delete).not.toHaveBeenCalled();

        click(host, 'delete-message-confirm');
        await nextTick();

        expect(actions.delete).toHaveBeenCalledExactlyOnceWith(
            expect.objectContaining({ id: 'm1' }),
        );
        expect(find(host, 'delete-dialog')).toBeNull();
    });
});

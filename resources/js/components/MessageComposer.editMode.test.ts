// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import type { Message } from '@/types';
import MessageComposer from './MessageComposer.vue';

/**
 * Covers the composer's inline edit mode: the ArrowUp recall, the banner and
 * the save/cancel controls it swaps in, the `edit`/`editingChange` emits, and
 * the rule that an edit in progress never persists as a channel draft.
 *
 * The browser suite already walks the happy path end to end; this pins the
 * emits and the guards around them at the layer that holds them deterministically.
 */

vi.mock('@/actions/App/Http/Controllers/Channels/AttachmentController', () => ({
    store: () => ({ url: '/t/acme/c/general/attachments' }),
}));

vi.mock('@/components/ui/button', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Button: defineComponent({
            name: 'ButtonStub',
            inheritAttrs: false,
            setup:
                (_props, { attrs, slots }) =>
                () =>
                    h('button', attrs, slots.default?.()),
        }),
    };
});

vi.mock('@/components/ui/tooltip', async () => {
    const { defineComponent, h } = await import('vue');
    const slot = (name: string) =>
        defineComponent({
            name,
            setup:
                (_props, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        });

    return {
        Tooltip: slot('TooltipStub'),
        TooltipContent: slot('TooltipContentStub'),
        TooltipProvider: slot('TooltipProviderStub'),
        TooltipTrigger: slot('TooltipTriggerStub'),
    };
});

const VIEWER_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

/** A message carrying just the fields the edit path reads. */
function message(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        clientUuid: 'uuid-1',
        body: 'first draft',
        type: 'standard',
        user: {
            id: VIEWER_ID,
            name: 'Ada Lovelace',
            avatar: null,
            isBot: false,
            status: null,
            presence: 'active',
            isDnd: false,
        },
        authorOverride: null,
        postedVia: null,
        createdAt: '2024-01-01T00:00:00.000Z',
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
    };
}

type Edit = { message: Message; body: string };

let active: Array<{ app: App; container: HTMLElement }> = [];

function mountComposer(props: Record<string, unknown> = {}) {
    const edits: Edit[] = [];
    const editing: Array<string | null> = [];
    const drafts: string[] = [];
    const container = document.createElement('div');
    document.body.appendChild(container);

    const app = createApp(
        defineComponent({
            setup: () => () =>
                h(MessageComposer as Component, {
                    channelName: 'general',
                    members: [],
                    teamSlug: 'acme',
                    channelSlug: 'general',
                    currentUserId: VIEWER_ID,
                    messages: [message()],
                    onEdit: (target: Message, body: string) =>
                        edits.push({ message: target, body }),
                    onEditingChange: (messageId: string | null) =>
                        editing.push(messageId),
                    onDraftChange: (body: string) => drafts.push(body),
                    ...props,
                }),
        }),
    );
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(container);
    active.push({ app, container });

    const textarea = container.querySelector<HTMLTextAreaElement>(
        '[data-test="message-composer-input"]',
    )!;

    return { container, drafts, editing, edits, textarea };
}

function type(textarea: HTMLTextAreaElement, value: string): Promise<void> {
    textarea.value = value;
    textarea.setSelectionRange(value.length, value.length);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));

    return nextTick();
}

function press(textarea: HTMLTextAreaElement, key: string): Promise<void> {
    textarea.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
    );

    return nextTick();
}

function click(container: HTMLElement, test: string): void {
    container
        .querySelector<HTMLButtonElement>(`[data-test="${test}"]`)!
        .click();
}

function banner(container: HTMLElement): HTMLElement | null {
    return container.querySelector('[data-test="composer-editing-banner"]');
}

afterEach(() => {
    active.forEach(({ app, container }) => {
        app.unmount();
        container.remove();
    });
    active = [];
});

describe('MessageComposer edit mode', () => {
    it('recalls the last editable message on ArrowUp in an empty composer', async () => {
        const { container, editing, textarea } = mountComposer();

        await press(textarea, 'ArrowUp');

        expect(textarea.value).toBe('first draft');
        expect(banner(container)).not.toBeNull();
        expect(editing).toEqual(['m1']);
    });

    it('leaves a composer with text alone', async () => {
        const { container, textarea } = mountComposer();

        await type(textarea, 'still typing');
        await press(textarea, 'ArrowUp');

        expect(banner(container)).toBeNull();
        expect(textarea.value).toBe('still typing');
    });

    it('stays out of edit mode when the viewer has nothing editable', async () => {
        const { container, editing, textarea } = mountComposer({
            messages: [message({ isDeleted: true })],
        });

        await press(textarea, 'ArrowUp');

        expect(banner(container)).toBeNull();
        expect(editing).toEqual([]);
    });

    it('skips an in-flight optimistic send when resolving the target', async () => {
        const { container, textarea } = mountComposer({
            messages: [message(), message({ id: 'm2', clientUuid: 'uuid-2' })],
            pendingUuids: ['uuid-2'],
        });

        await press(textarea, 'ArrowUp');

        expect(textarea.value).toBe('first draft');
        expect(banner(container)).not.toBeNull();
    });

    it('saves the correction on Enter and returns to composing', async () => {
        const { container, editing, edits, textarea } = mountComposer();

        await press(textarea, 'ArrowUp');
        await type(textarea, 'second draft');
        await press(textarea, 'Enter');

        expect(edits).toHaveLength(1);
        expect(edits[0].message.id).toBe('m1');
        expect(edits[0].body).toBe('second draft');
        expect(textarea.value).toBe('');
        expect(banner(container)).toBeNull();
        expect(editing).toEqual(['m1', null]);
    });

    it('saves from the explicit save control', async () => {
        const { container, edits, textarea } = mountComposer();

        await press(textarea, 'ArrowUp');
        await type(textarea, 'second draft');
        click(container, 'message-composer-edit-save');
        await nextTick();

        expect(edits[0].body).toBe('second draft');
    });

    it('leaves an unchanged or emptied body unsaved', async () => {
        const unchanged = mountComposer();
        await press(unchanged.textarea, 'ArrowUp');
        await press(unchanged.textarea, 'Enter');
        expect(unchanged.edits).toEqual([]);
        expect(banner(unchanged.container)).toBeNull();

        const emptied = mountComposer();
        await press(emptied.textarea, 'ArrowUp');
        await type(emptied.textarea, '   ');
        await press(emptied.textarea, 'Enter');
        expect(emptied.edits).toEqual([]);
    });

    it('abandons the edit on Escape', async () => {
        const { container, editing, edits, textarea } = mountComposer();

        await press(textarea, 'ArrowUp');
        await type(textarea, 'second draft');
        await press(textarea, 'Escape');

        expect(edits).toEqual([]);
        expect(textarea.value).toBe('');
        expect(banner(container)).toBeNull();
        expect(editing).toEqual(['m1', null]);
    });

    it('abandons the edit from the banner’s dismiss control', async () => {
        const { container, edits, textarea } = mountComposer();

        await press(textarea, 'ArrowUp');
        click(container, 'composer-editing-dismiss');
        await nextTick();

        expect(edits).toEqual([]);
        expect(banner(container)).toBeNull();
    });

    it('never persists an in-progress edit as a channel draft', async () => {
        const { drafts, textarea } = mountComposer();

        await press(textarea, 'ArrowUp');
        await type(textarea, 'second draft');
        await press(textarea, 'Enter');

        // Neither the recalled body, the correction, nor the wipe that leaves
        // edit mode is a new-message draft.
        expect(drafts).toEqual([]);
    });

    it('keeps typing a draft again once the edit is over', async () => {
        const { drafts, textarea } = mountComposer();

        await press(textarea, 'ArrowUp');
        await press(textarea, 'Escape');
        await type(textarea, 'a fresh message');

        expect(drafts).toEqual(['a fresh message']);
    });

    it('hides the also-send-to-channel row while editing', async () => {
        const { container, textarea } = mountComposer({
            allowSendToChannel: true,
        });

        expect(
            container.querySelector('[data-test="send-to-channel-row"]'),
        ).not.toBeNull();

        await press(textarea, 'ArrowUp');

        expect(
            container.querySelector('[data-test="send-to-channel-row"]'),
        ).toBeNull();
    });
});

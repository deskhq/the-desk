// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import { translate } from '@/lib/i18n';
import type { Message, Reaction } from '@/types';

/** Mutable stand-in for the shared Inertia props the sheet reads. */
const props = vi.hoisted(() => ({
    frequentEmojis: [] as string[],
    customEmojis: {} as Record<string, string>,
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props }),
}));

vi.mock('vue-sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

// The sheet rides the app's dialog primitive; its portal/focus behaviour is the
// primitive's own tested concern, so render it down to a passthrough.
vi.mock('@/components/ui/dialog', () => {
    const pass = (name: string) =>
        defineComponent({
            name,
            setup:
                (_p, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        });

    return {
        Dialog: pass('Dialog'),
        DialogContent: pass('DialogContent'),
        DialogDescription: pass('DialogDescription'),
        DialogTitle: pass('DialogTitle'),
    };
});

// The picker popover pulls in reka-ui portals and the emoji library; the sheet
// only needs its trigger slot, so stub it down to a passthrough.
vi.mock('@/components/EmojiPickerPopover.vue', () => ({
    default: defineComponent({
        name: 'EmojiPickerPopover',
        setup:
            (_p, { slots }) =>
            () =>
                h('div', slots.default?.({ open: false })),
    }),
}));

import MessageActionsSheet from './MessageActionsSheet.vue';

function reaction(emoji: string, reactorIds: string[]): Reaction {
    return {
        emoji,
        count: reactorIds.length,
        reactors: reactorIds.map((id) => ({ id, name: id })),
    };
}

function message(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        clientUuid: 'uuid-1',
        body: 'hello',
        type: 'standard',
        user: { id: 'peer', name: 'Peer' },
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
        ...overrides,
    } as Message;
}

let app: App | null = null;

function mount(componentProps: Record<string, unknown> = {}): {
    host: HTMLElement;
    emitted: Record<string, unknown[][]>;
} {
    const emitted: Record<string, unknown[][]> = {};
    const capture =
        (name: string) =>
        (...args: unknown[]) => {
            (emitted[name] ??= []).push(args);
        };
    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({
        render: () =>
            h(MessageActionsSheet, {
                open: true,
                message: message(),
                currentUserId: 'me',
                canReact: true,
                canPin: true,
                viewerTimeZone: 'UTC',
                'onUpdate:open': capture('update:open'),
                onReact: capture('react'),
                onOpenThread: capture('openThread'),
                onReply: capture('reply'),
                onForward: capture('forward'),
                onPin: capture('pin'),
                onUnpin: capture('unpin'),
                onRemindCustom: capture('remindCustom'),
                onEdit: capture('edit'),
                onDelete: capture('delete'),
                ...componentProps,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    return { host, emitted };
}

function rowNames(host: HTMLElement): string[] {
    return [...host.querySelectorAll<HTMLElement>('[data-test^="sheet-"]')].map(
        (row) => row.dataset.test!.replace(/^sheet-/, ''),
    );
}

beforeEach(() => {
    props.frequentEmojis = ['👍', '❤️', '🎉', ':shipit:', '👀'];
    props.customEmojis = { shipit: 'https://desk.test/shipit.png' };
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('MessageActionsSheet action rows', () => {
    it('offers every toolbar action on a peer root message, minus the author-only pair', () => {
        const { host } = mount();

        expect(rowNames(host)).toEqual([
            'quick-react',
            'quick-react',
            'quick-react',
            'quick-react',
            'quick-react',
            'react',
            'thread',
            'reply',
            'forward',
            'copy',
            'pin',
            'remind',
        ]);
    });

    it('adds edit and delete on the viewer own message', () => {
        const { host } = mount({
            message: message({ user: { id: 'me', name: 'Me' } }),
        });

        expect(rowNames(host)).toContain('edit');
        expect(rowNames(host)).toContain('delete');
    });

    it('adds delete alone when the viewer moderates a peer message', () => {
        const { host } = mount({ canModerate: true });

        expect(rowNames(host)).not.toContain('edit');
        expect(rowNames(host)).toContain('delete');
    });

    it('drops the thread and reply rows inside a thread panel, like the toolbar', () => {
        const { host } = mount({ inThread: true });

        expect(rowNames(host)).not.toContain('thread');
        expect(rowNames(host)).not.toContain('reply');
    });

    it('drops the thread row on a message that is already a reply', () => {
        const { host } = mount({
            message: message({ threadRootId: 'root-1' }),
        });

        expect(rowNames(host)).not.toContain('thread');
        expect(rowNames(host)).toContain('reply');
    });

    it('hides the quick-reaction strip when the viewer may not react', () => {
        const { host } = mount({ canReact: false });

        expect(rowNames(host)).not.toContain('quick-react');
        expect(rowNames(host)).not.toContain('react');
    });

    it('flips the pin row to unpin on a pinned message', () => {
        const pinned = mount({
            message: message({
                pin: {
                    pinnedBy: { id: 'peer', name: 'Peer' },
                    pinnedAt: '2024-01-01T00:00:00.000Z',
                },
            }),
        });

        expect(
            pinned.host.querySelector<HTMLElement>('[data-test="sheet-pin"]')!
                .textContent,
        ).toContain('Unpin from channel');

        pinned.host
            .querySelector<HTMLElement>('[data-test="sheet-pin"]')!
            .click();

        expect(pinned.emitted.unpin).toHaveLength(1);
        expect(pinned.emitted.pin).toBeUndefined();
    });

    it('emits the chosen action and dismisses itself', () => {
        const { host, emitted } = mount();

        host.querySelector<HTMLElement>('[data-test="sheet-forward"]')!.click();

        expect(emitted.forward).toHaveLength(1);
        expect(emitted['update:open']).toEqual([[false]]);
    });

    it('copies the message text as typed and dismisses', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText },
            configurable: true,
        });

        const { host, emitted } = mount({
            message: message({
                body: 'hi @[Alice](a1b2c3d4-e5f6-7890-1234-567890abcdef)\n**bold**',
            }),
        });

        host.querySelector<HTMLElement>('[data-test="sheet-copy"]')!.click();
        await Promise.resolve();

        expect(writeText).toHaveBeenCalledExactlyOnceWith(
            'hi @Alice\n**bold**',
        );
        expect(emitted['update:open']).toEqual([[false]]);
    });

    it('reports a copy that could not reach the clipboard and still dismisses', async () => {
        const { toast } = await import('vue-sonner');
        const writeText = vi.fn().mockRejectedValue(new Error('denied'));
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText },
            configurable: true,
        });

        const { host, emitted } = mount();

        host.querySelector<HTMLElement>('[data-test="sheet-copy"]')!.click();
        await Promise.resolve();
        await Promise.resolve();

        expect(toast.error).toHaveBeenCalledExactlyOnceWith(
            'The message text could not be copied.',
        );
        expect(emitted['update:open']).toEqual([[false]]);
    });

    it('hides the copy row when the message has no text to copy', () => {
        const { host } = mount({ message: message({ body: '' }) });

        expect(rowNames(host)).not.toContain('copy');
    });

    it('routes the remind row to the custom reminder flow', () => {
        const { host, emitted } = mount();

        host.querySelector<HTMLElement>('[data-test="sheet-remind"]')!.click();

        expect(emitted.remindCustom).toHaveLength(1);
        expect(emitted['update:open']).toEqual([[false]]);
    });
});

function shortcut(host: HTMLElement, emoji: string): HTMLElement {
    return [
        ...host.querySelectorAll<HTMLElement>(
            '[data-test="sheet-quick-react"]',
        ),
    ].find((cell) => cell.dataset.emoji === emoji)!;
}

describe('MessageActionsSheet quick reactions', () => {
    it('applies a shortcut reaction and dismisses', () => {
        const { host, emitted } = mount();

        shortcut(host, '🎉').click();

        expect(emitted.react).toEqual([['🎉']]);
        expect(emitted['update:open']).toEqual([[false]]);
    });

    it('marks an already-reacted shortcut pressed and labels it as a retraction', () => {
        const { host } = mount({
            message: message({ reactions: [reaction('🎉', ['me', 'peer'])] }),
        });

        const pressed = shortcut(host, '🎉');

        expect(pressed.getAttribute('aria-pressed')).toBe('true');
        expect(pressed.getAttribute('aria-label')).toBe('Remove your 🎉');
    });

    it('resolves a custom shortcode to its image and a native glyph to text', () => {
        const { host } = mount();

        expect(
            shortcut(host, ':shipit:')
                .querySelector('img')
                ?.getAttribute('src'),
        ).toBe('https://desk.test/shipit.png');
        expect(shortcut(host, '👍').querySelector('img')).toBeNull();
    });
});

describe('MessageActionsSheet selection suppression', () => {
    it('renders every surface non-selectable, like a native sheet', () => {
        const { host } = mount();

        const sheet = host.querySelector<HTMLElement>(
            '[data-test="message-actions-sheet"]',
        )!;

        expect(sheet.classList.contains('select-none')).toBe(true);
        expect(sheet.classList.contains('[-webkit-touch-callout:none]')).toBe(
            true,
        );
    });

    it('clears a selection that slipped through before the sheet opened', () => {
        const stray = document.createElement('p');
        stray.textContent = 'selected before the press landed';
        document.body.appendChild(stray);
        const range = document.createRange();
        range.selectNodeContents(stray);
        window.getSelection()?.addRange(range);

        expect(window.getSelection()?.toString()).not.toBe('');

        mount();

        expect(window.getSelection()?.toString()).toBe('');
    });
});

describe('MessageActionsSheet lifted card', () => {
    it('echoes the pressed message: avatar initials, author, time, plain body', () => {
        const { host } = mount({
            message: message({
                body: 'Shipped the **thread panel**',
            }),
        });

        const card = host.querySelector<HTMLElement>(
            '[data-test="lifted-message"]',
        )!;

        expect(card.textContent).toContain('Peer');
        expect(card.textContent).toContain('P');
        expect(card.textContent).toContain('Shipped the thread panel');
        expect(card.innerHTML).not.toContain('<strong>');
        expect(card.textContent).toMatch(/12:00|0:00/);
    });
});

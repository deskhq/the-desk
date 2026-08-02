// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Reaction } from '@/types';

/**
 * Covers the touch actions sheet mounted on its own against a stub facade: which
 * rows it offers for a given viewer, where each row lands, the clipboard row's
 * two outcomes, and the lifted card echoing the pressed message.
 */
const { toastError } = vi.hoisted(() => ({ toastError: vi.fn() }));

vi.mock('@inertiajs/vue3', async () => {
    const { inertiaPageProps } = await import('./MessageList.doubles');

    return { usePage: () => ({ props: inertiaPageProps }) };
});

vi.mock('@/composables/useToast', () => {
    const toast = {
        error: toastError,
        success: vi.fn(),
        warning: vi.fn(),
        progress: vi.fn(),
    };

    return { useToast: () => toast };
});

// The sheet rides the app's dialog primitive; its portal/focus behaviour is the
// primitive's own tested concern, so render it down to a passthrough.
vi.mock('@/components/ui/dialog', async () => {
    const { passthrough } = await import('./MessageList.doubles');

    return {
        Dialog: passthrough('Dialog'),
        DialogContent: passthrough('DialogContent'),
        DialogDescription: passthrough('DialogDescription'),
        DialogTitle: passthrough('DialogTitle'),
    };
});

// The picker popover pulls in reka-ui portals and the emoji library; the sheet
// only needs its trigger slot, so stub it down to a passthrough.
vi.mock('@/components/EmojiPickerPopover.vue', async () => {
    const { popover } = await import('./MessageList.doubles');

    return { default: popover('EmojiPickerPopover') };
});

import MessageActionsSheet from './MessageActionsSheet.vue';
import {
    all,
    author,
    click,
    find,
    inertiaPageProps,
    message,
    mountWithActions,
    unmountAll,
} from './MessageList.doubles';

function reaction(emoji: string, reactorIds: string[]): Reaction {
    return {
        emoji,
        count: reactorIds.length,
        reactors: reactorIds.map((id) => ({ id, name: id, avatar: null })),
    };
}

function mount(
    props: Record<string, unknown> = {},
    overrides: Parameters<typeof mountWithActions>[2] = {},
) {
    return mountWithActions(
        MessageActionsSheet,
        {
            open: true,
            message: message(),
            'onUpdate:open': () => {},
            onStartEdit: () => {},
            onRequestDelete: () => {},
            ...props,
        },
        overrides,
    );
}

function rowNames(host: HTMLElement): string[] {
    return [...host.querySelectorAll<HTMLElement>('[data-test^="sheet-"]')].map(
        (row) => row.dataset.test!.replace(/^sheet-/, ''),
    );
}

function shortcut(host: HTMLElement, emoji: string): HTMLElement {
    return all(host, 'sheet-quick-react').find(
        (cell) => cell.dataset.emoji === emoji,
    )!;
}

/** Stub the clipboard with a write that either lands or is refused. */
function stubClipboard(writeText: ReturnType<typeof vi.fn>): void {
    Object.defineProperty(navigator, 'clipboard', {
        value: { writeText },
        configurable: true,
    });
}

beforeEach(() => {
    inertiaPageProps.frequentEmojis = ['👍', '❤️', '🎉', ':shipit:', '👀'];
    inertiaPageProps.customEmojis = { shipit: 'https://desk.test/shipit.png' };
});

afterEach(unmountAll);

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
            message: message({
                user: author({ id: 'me', name: 'Me' }),
            }),
        });

        expect(rowNames(host)).toContain('edit');
        expect(rowNames(host)).toContain('delete');
    });

    it('adds delete alone when the viewer moderates a peer message', () => {
        const { host } = mount({}, { scope: { canModerate: true } });

        expect(rowNames(host)).not.toContain('edit');
        expect(rowNames(host)).toContain('delete');
    });

    it('drops the thread and reply rows inside a thread panel, like the toolbar', () => {
        const { host } = mount({}, { subtree: { inThread: true } });

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
        const { host } = mount({}, { scope: { canReact: false } });

        expect(rowNames(host)).not.toContain('quick-react');
        expect(rowNames(host)).not.toContain('react');
    });

    it('flips the pin row to unpin on a pinned message', () => {
        const pinned = message({
            pin: {
                pinnedBy: { id: 'peer', name: 'Peer', avatar: null },
                pinnedAt: '2024-01-01T00:00:00.000Z',
            },
        });
        const { host, actions } = mount({ message: pinned });

        expect(find(host, 'sheet-pin')?.textContent).toContain(
            'Unpin from channel',
        );

        click(host, 'sheet-pin');

        expect(actions.unpin).toHaveBeenCalledExactlyOnceWith(pinned);
        expect(actions.pin).not.toHaveBeenCalled();
    });

    it('reaches the chosen action and dismisses itself', () => {
        const target = message();
        const { host, actions, events } = mount({ message: target });

        click(host, 'sheet-forward');

        expect(actions.forward).toHaveBeenCalledExactlyOnceWith(target);
        expect(events).toEqual([['update:open', false]]);
    });

    it('hands the timeline the edit and delete affordances it owns', () => {
        const { host, events } = mount({
            message: message({
                user: author({ id: 'me', name: 'Me' }),
            }),
        });

        click(host, 'sheet-edit');
        click(host, 'sheet-delete');

        expect(events.map(([name]) => name)).toEqual([
            'startEdit',
            'update:open',
            'requestDelete',
            'update:open',
        ]);
    });

    it('copies the message text as typed and dismisses', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        stubClipboard(writeText);

        const { host, events } = mount({
            message: message({
                body: 'hi @[Alice](a1b2c3d4-e5f6-7890-1234-567890abcdef)\n**bold**',
            }),
        });

        click(host, 'sheet-copy');
        await Promise.resolve();

        expect(writeText).toHaveBeenCalledExactlyOnceWith(
            'hi @Alice\n**bold**',
        );
        expect(events).toEqual([['update:open', false]]);
    });

    it('reports a copy that could not reach the clipboard and still dismisses', async () => {
        stubClipboard(vi.fn().mockRejectedValue(new Error('denied')));

        const { host, events } = mount();

        click(host, 'sheet-copy');
        await Promise.resolve();
        await Promise.resolve();

        expect(toastError).toHaveBeenCalledExactlyOnceWith(
            'The message text could not be copied',
        );
        expect(events).toEqual([['update:open', false]]);
    });

    it('hides the copy row when the message has no text to copy', () => {
        const { host } = mount({ message: message({ body: '' }) });

        expect(rowNames(host)).not.toContain('copy');
    });

    it('routes the remind row to the custom reminder flow', () => {
        const target = message();
        const { host, actions, events } = mount({ message: target });

        click(host, 'sheet-remind');

        expect(actions.remindCustom).toHaveBeenCalledExactlyOnceWith(target);
        expect(events).toEqual([['update:open', false]]);
    });
});

describe('MessageActionsSheet quick reactions', () => {
    it('applies a shortcut reaction and dismisses', () => {
        const target = message();
        const { host, actions, events } = mount({ message: target });

        shortcut(host, '🎉').click();

        expect(actions.react).toHaveBeenCalledExactlyOnceWith(target, '🎉');
        expect(events).toEqual([['update:open', false]]);
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

        const sheet = find(host, 'message-actions-sheet')!;

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
            message: message({ body: 'Shipped the **thread panel**' }),
        });

        const card = find(host, 'lifted-message')!;

        expect(card.textContent).toContain('Peer');
        expect(card.textContent).toContain('P');
        expect(card.textContent).toContain('Shipped the thread panel');
        expect(card.innerHTML).not.toContain('<strong>');
        expect(card.textContent).toMatch(/10:30/);
    });
});

// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import { translate } from '@/lib/i18n';
import type { Message, Reaction } from '@/types';

/** Mutable stand-in for the shared Inertia props the bar reads. */
const props = vi.hoisted(() => ({
    frequentEmojis: [] as string[],
    customEmojis: {} as Record<string, string>,
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props }),
}));

// The picker popover pulls in reka-ui portals and the emoji library; the quick
// cluster only needs its trigger slot, so stub it down to a passthrough.
vi.mock('@/components/EmojiPickerPopover.vue', () => ({
    default: defineComponent({
        name: 'EmojiPickerPopover',
        setup:
            (_p, { slots }) =>
            () =>
                h('div', slots.default?.({ open: false })),
    }),
}));

vi.mock('@/components/ui/tooltip', () => {
    const pass = (name: string) =>
        defineComponent({
            name,
            setup:
                (_p, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        });

    return {
        Tooltip: pass('Tooltip'),
        TooltipContent: pass('TooltipContent'),
        TooltipProvider: pass('TooltipProvider'),
        TooltipTrigger: pass('TooltipTrigger'),
    };
});

vi.mock('@/components/MessageReminderPopover.vue', () => ({
    default: defineComponent({
        name: 'MessageReminderPopover',
        setup:
            (_p, { slots }) =>
            () =>
                h('div', slots.default?.({ open: false })),
    }),
}));

import MessageActions from './MessageActions.vue';

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
    reacted: string[];
} {
    const reacted: string[] = [];
    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({
        render: () =>
            h(MessageActions, {
                message: message(),
                currentUserId: 'me',
                canReact: true,
                viewerTimezone: null,
                onReact: (emoji: string) => reacted.push(emoji),
                ...componentProps,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    return { host, reacted };
}

function shortcuts(host: HTMLElement): HTMLElement[] {
    return [...host.querySelectorAll<HTMLElement>('[data-test="quick-react"]')];
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

describe('MessageActions quick-react cluster', () => {
    it('renders one shortcut per frequently-used emoji, ahead of the picker trigger', () => {
        const { host } = mount();
        const cells = shortcuts(host);

        expect(cells.map((cell) => cell.dataset.emoji)).toEqual([
            '👍',
            '❤️',
            '🎉',
            ':shipit:',
            '👀',
        ]);

        const order = [...host.querySelectorAll('[data-test]')].map((node) =>
            node.getAttribute('data-test'),
        );

        expect(order.indexOf('message-react')).toBeGreaterThan(
            order.lastIndexOf('quick-react'),
        );
    });

    it('resolves a custom shortcode to its image and a native glyph to text', () => {
        const { host } = mount();
        const custom = shortcuts(host).find(
            (cell) => cell.dataset.emoji === ':shipit:',
        )!;

        expect(custom.querySelector('img')?.getAttribute('src')).toBe(
            'https://desk.test/shipit.png',
        );
        expect(shortcuts(host)[0].querySelector('img')).toBeNull();
        expect(shortcuts(host)[0].textContent?.trim()).toBe('👍');
    });

    it('shows only the top three shortcuts inside a thread panel', () => {
        const { host } = mount({ inThread: true });

        expect(shortcuts(host).map((cell) => cell.dataset.emoji)).toEqual([
            '👍',
            '❤️',
            '🎉',
        ]);
    });

    it('hides the cluster when the viewer may not react', () => {
        const { host } = mount({ canReact: false });

        expect(shortcuts(host)).toHaveLength(0);
    });

    it('marks an already-reacted shortcut pressed and labels it as a retraction', () => {
        const { host } = mount({
            message: message({
                reactions: [
                    reaction('🎉', ['me', 'peer']),
                    reaction('👍', ['peer']),
                ],
            }),
        });
        const cells = shortcuts(host);
        const pressed = cells.find((cell) => cell.dataset.emoji === '🎉')!;
        const unpressed = cells.find((cell) => cell.dataset.emoji === '👍')!;

        expect(pressed.getAttribute('aria-pressed')).toBe('true');
        expect(pressed.getAttribute('aria-label')).toBe('Remove your 🎉');
        expect(unpressed.getAttribute('aria-pressed')).toBe('false');
        expect(unpressed.getAttribute('aria-label')).toBe('React with 👍');
    });

    it('emits the react toggle on click, pressed or not', () => {
        const { host, reacted } = mount({
            message: message({ reactions: [reaction('🎉', ['me'])] }),
        });

        shortcuts(host)[0].click();
        shortcuts(host)
            .find((cell) => cell.dataset.emoji === '🎉')!
            .click();

        expect(reacted).toEqual(['👍', '🎉']);
    });
});

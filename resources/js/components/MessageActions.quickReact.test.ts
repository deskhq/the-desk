// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Reaction } from '@/types';

/**
 * Covers the hover toolbar's quick-react cluster, mounted on its own against a
 * stub facade — no timeline, no page. What is pinned here is the ranked list it
 * renders, how a shortcut reads once the viewer has used it, and that a press
 * reaches the provided `react` rather than an emit up a relay.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { inertiaPageProps } = await import('./MessageList.doubles');

    return { usePage: () => ({ props: inertiaPageProps }) };
});

// The picker popover pulls in reka-ui portals and the emoji library; the quick
// cluster only needs its trigger slot, so stub it down to a passthrough.
vi.mock('@/components/EmojiPickerPopover.vue', async () => {
    const { popover } = await import('./MessageList.doubles');

    return { default: popover('EmojiPickerPopover') };
});

vi.mock('@/components/MessageReminderPopover.vue', async () => {
    const { popover } = await import('./MessageList.doubles');

    return { default: popover('MessageReminderPopover') };
});

vi.mock('@/components/ui/tooltip', async () => {
    const { passthrough } = await import('./MessageList.doubles');

    return {
        Tooltip: passthrough('Tooltip'),
        TooltipContent: passthrough('TooltipContent'),
        TooltipProvider: passthrough('TooltipProvider'),
        TooltipTrigger: passthrough('TooltipTrigger'),
    };
});

import MessageActions from './MessageActions.vue';
import {
    all,
    inertiaPageProps,
    message,
    mountWithActions,
    unmountAll,
} from './MessageList.doubles';

function reaction(emoji: string, reactorIds: string[]): Reaction {
    return {
        emoji,
        count: reactorIds.length,
        reactors: reactorIds.map((id) => ({ id, name: id })),
    };
}

function mount(
    props: Record<string, unknown> = {},
    overrides: Parameters<typeof mountWithActions>[2] = {},
) {
    return mountWithActions(
        MessageActions,
        { message: message(), ...props },
        overrides,
    );
}

function shortcuts(host: HTMLElement): HTMLElement[] {
    return all(host, 'quick-react');
}

beforeEach(() => {
    inertiaPageProps.frequentEmojis = ['👍', '❤️', '🎉', ':shipit:', '👀'];
    inertiaPageProps.customEmojis = { shipit: 'https://desk.test/shipit.png' };
});

afterEach(unmountAll);

describe('MessageActions quick-react cluster', () => {
    it('renders one shortcut per frequently-used emoji, ahead of the picker trigger', () => {
        const { host } = mount();

        expect(shortcuts(host).map((cell) => cell.dataset.emoji)).toEqual([
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
        const { host } = mount({}, { subtree: { inThread: true } });

        expect(shortcuts(host).map((cell) => cell.dataset.emoji)).toEqual([
            '👍',
            '❤️',
            '🎉',
        ]);
    });

    it('hides the cluster when the viewer may not react', () => {
        const { host } = mount({}, { scope: { canReact: false } });

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

    it('reaches the provided react action on click, pressed or not', () => {
        const target = message({ reactions: [reaction('🎉', ['me'])] });
        const { host, actions } = mount({ message: target });

        shortcuts(host)[0].click();
        shortcuts(host)
            .find((cell) => cell.dataset.emoji === '🎉')!
            .click();

        expect(actions.react.mock.calls).toEqual([
            [target, '👍'],
            [target, '🎉'],
        ]);
    });
});

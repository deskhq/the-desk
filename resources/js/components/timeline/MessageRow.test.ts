// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import type { UseLongPress } from '@/composables/useLongPress';
import type { Message } from '@/types';
import {
    click,
    find,
    inertiaPageProps,
    message,
    mountWithActions,
    unmountAll,
} from '../MessageList.doubles';

/**
 * Mounts one row with its real hover toolbar and nothing else — no timeline, no
 * channel page — against a stub message-actions facade. That mount is only
 * possible because the eleven actions are provided rather than drilled
 * (ADR-0009), and what it pins is that each affordance reaches the facade
 * carrying its own message, with the two timeline-owned affordances (the inline
 * editor, the delete confirmation) still leaving as events.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { inertiaPageProps } = await import('../MessageList.doubles');

    return { usePage: () => ({ props: inertiaPageProps }) };
});

vi.mock('@/components/EmojiPickerPopover.vue', async () => {
    const { popover } = await import('../MessageList.doubles');

    return { default: popover('EmojiPickerPopover') };
});

vi.mock('@/components/MessageReminderPopover.vue', () => ({
    default: {
        name: 'MessageReminderPopoverStub',
        emits: ['set', 'custom'],
        template: `
            <div>
                <slot :open="false" />
                <button data-test="stub-remind-preset" @click="$emit('set', '2024-03-06T09:00:00.000Z')"></button>
                <button data-test="stub-remind-custom" @click="$emit('custom')"></button>
            </div>
        `,
    },
}));

vi.mock('@/components/ui/tooltip', async () => {
    const { passthrough } = await import('../MessageList.doubles');

    return {
        Tooltip: passthrough('Tooltip'),
        TooltipContent: passthrough('TooltipContent'),
        TooltipProvider: passthrough('TooltipProvider'),
        TooltipTrigger: passthrough('TooltipTrigger'),
    };
});

vi.mock('@/components/MessageAttachments.vue', async () => {
    const { marker } = await import('../MessageList.doubles');

    return { default: marker('MessageAttachments') };
});

vi.mock('@/components/MessageForward.vue', async () => {
    const { marker } = await import('../MessageList.doubles');

    return { default: marker('MessageForward') };
});

vi.mock('@/components/UserHoverCard.vue', async () => {
    const { passthrough } = await import('../MessageList.doubles');

    return { default: passthrough('UserHoverCard') };
});

import MessageRow from './MessageRow.vue';

/** A gesture that records nothing: the touch sheet is the timeline's concern. */
const longPress: UseLongPress<Message> = {
    start: vi.fn(),
    move: vi.fn(),
    end: vi.fn(),
    cancel: vi.fn(),
    onContextMenu: vi.fn(),
    pressing: ref(null),
};

function mount(
    props: Record<string, unknown> = {},
    overrides: Parameters<typeof mountWithActions>[2] = {},
) {
    return mountWithActions(
        MessageRow,
        {
            message: message(),
            authorName: 'Peer',
            teamSlug: 'acme',
            isLead: true,
            pending: false,
            queued: false,
            held: false,
            editing: false,
            highlighted: false,
            activeThreadRoot: false,
            composerEditing: false,
            longPress,
            onStartEdit: () => {},
            onRequestDelete: () => {},
            ...props,
        },
        overrides,
    );
}

beforeEach(() => {
    inertiaPageProps.frequentEmojis = ['👍'];
    inertiaPageProps.customEmojis = {};
    inertiaPageProps.userGroups = [];
});

afterEach(unmountAll);

describe('a message row on its own', () => {
    it('reaches the provided facade for every toolbar action, carrying its message', () => {
        const target = message({ threadReplyCount: 0 });
        const { host, actions } = mount({ message: target });

        click(host, 'message-thread');
        click(host, 'message-reply');
        click(host, 'message-forward');
        click(host, 'message-pin');
        click(host, 'stub-remind-preset');
        click(host, 'stub-remind-custom');

        expect(actions.openThread).toHaveBeenCalledExactlyOnceWith('m1');
        expect(actions.reply).toHaveBeenCalledExactlyOnceWith(target);
        expect(actions.forward).toHaveBeenCalledExactlyOnceWith(target);
        expect(actions.pin).toHaveBeenCalledExactlyOnceWith(target);
        expect(actions.remind).toHaveBeenCalledExactlyOnceWith(
            target,
            '2024-03-06T09:00:00.000Z',
        );
        expect(actions.remindCustom).toHaveBeenCalledExactlyOnceWith(target);
    });

    it('unpins a pinned message through the same toggle', () => {
        const target = message({
            pin: {
                pinnedBy: { id: 'peer', name: 'Peer', avatar: null },
                pinnedAt: '2024-03-04T10:31:00.000Z',
            },
        });
        const { host, actions } = mount({ message: target });

        click(host, 'message-pin');

        expect(actions.unpin).toHaveBeenCalledExactlyOnceWith(target);
        expect(actions.pin).not.toHaveBeenCalled();
    });

    it('leaves the editor and the delete confirmation to the timeline', () => {
        const { host, actions, events } = mount({
            message: message({
                user: {
                    id: 'me',
                    name: 'Me',
                    avatar: null,
                    isBot: false,
                    status: null,
                    presence: 'active',
                    isDnd: false,
                },
            }),
        });

        click(host, 'message-edit');
        click(host, 'message-delete');

        expect(events.map(([name]) => name)).toEqual([
            'startEdit',
            'requestDelete',
        ]);
        expect(actions.edit).not.toHaveBeenCalled();
        expect(actions.delete).not.toHaveBeenCalled();
    });

    it('reads the viewer capabilities off the facade rather than off a prop', () => {
        const { host } = mount({}, { scope: { canReact: false } });

        expect(find(host, 'message-react')).toBeNull();
        expect(find(host, 'quick-react')).toBeNull();
        expect(find(host, 'message-forward')).not.toBeNull();
    });

    it('lets a moderator delete a peer message it does not own', () => {
        const { host } = mount({}, { scope: { canModerate: true } });

        expect(find(host, 'message-delete')).not.toBeNull();
        expect(find(host, 'message-edit')).toBeNull();
    });

    it('drops the thread and reply affordances inside a thread panel', () => {
        const { host } = mount({}, { subtree: { inThread: true } });

        expect(find(host, 'message-thread')).toBeNull();
        expect(find(host, 'message-reply')).toBeNull();
    });

    it('renders its timestamps in the zone the facade carries', () => {
        const { host } = mount(
            {},
            { scope: { viewerTimeZone: 'Australia/Sydney' } },
        );

        expect(
            host.querySelector('[role="listitem"]')?.getAttribute('aria-label'),
        ).toBe('Peer, 9:30 PM');
    });

    it('offers no toolbar at all while the send is still in flight', () => {
        const { host } = mount({ pending: true });

        expect(find(host, 'message-forward')).toBeNull();
        expect(find(host, 'message-react')).toBeNull();
    });
});

// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { Message } from '@/types';
import {
    find,
    inertiaPageProps,
    message,
    mountWithActions,
    stub,
    unmountAll,
} from './MessageList.doubles';

/**
 * Covers a single message row: the body and the segments it tokenizes into, the
 * markers riding above and below it (pinned, edited, queued, tombstone), the
 * reply quote, and the thread summary. These all move together when
 * `MessageList.vue` is split, so what is pinned here is the rendered markup —
 * every selector, every guard that decides whether a piece renders at all.
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

vi.mock('@/components/UserHoverCard.vue', () => ({
    default: defineComponent({
        name: 'UserHoverCardStub',
        setup:
            (_props, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
}));

vi.mock('@/components/ui/hover-card', async () => {
    const { passthrough } = await import('./MessageList.doubles');

    return {
        HoverCard: passthrough('HoverCard'),
        HoverCardTrigger: passthrough('HoverCardTrigger'),
        HoverCardContent: passthrough('HoverCardContent'),
    };
});

vi.mock('@/components/MessageActions.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('MessageActions') };
});

vi.mock('@/components/MessageActionsSheet.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('MessageActionsSheet') };
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

vi.mock('@/components/LinkPreview.vue', async () => {
    const { marker } = await import('./MessageList.doubles');

    return { default: marker('LinkPreview') };
});

import MessageList from './MessageList.vue';

function mount(props: Record<string, unknown> = {}) {
    return mountWithActions(MessageList, {
        messages: [message()],
        teamSlug: 'acme',
        ...props,
    });
}

beforeEach(() => {
    inertiaPageProps.customEmojis = {};
    inertiaPageProps.userGroups = [];
});

afterEach(unmountAll);

describe('the message body', () => {
    it('renders the body text with an anchor id for jump targets', () => {
        const { host } = mount();

        expect(find(host, 'message-body')?.textContent?.trim()).toBe('hello');
        expect(host.querySelector('#message-m1')).not.toBeNull();
    });

    it('leaves the body out when the message carries only an attachment', () => {
        const { host } = mount({ messages: [message({ body: '' })] });

        expect(find(host, 'message-body')).toBeNull();
    });

    it('renders a resolved mention as a hover-carded pill', () => {
        const id = '11111111-1111-4111-8111-111111111111';
        const { host } = mount({
            messages: [
                message({
                    body: `hey @[Ada](${id})`,
                    mentions: [{ id, name: 'Ada', avatar: null }],
                }),
            ],
        });

        expect(find(host, 'message-mention')?.textContent).toBe('@Ada');
    });

    it('renders a resolved group mention as an inert pill of its own hue', () => {
        const id = '22222222-2222-4222-8222-222222222222';
        inertiaPageProps.userGroups = [{ id }];
        const { host } = mount({
            messages: [message({ body: `@[design](group:${id})` })],
        });

        const pill = find(host, 'message-group-mention');

        expect(pill?.textContent).toBe('@design');
        expect(find(host, 'message-mention')).toBeNull();
    });

    it('renders a custom emoji shortcode as its image', () => {
        inertiaPageProps.customEmojis = {
            shipit: 'https://desk.test/shipit.png',
        };
        const { host } = mount({
            messages: [message({ body: 'ship it :shipit:' })],
        });

        const emoji = find(host, 'message-emoji') as HTMLImageElement | null;

        expect(emoji?.getAttribute('src')).toBe('https://desk.test/shipit.png');
        expect(emoji?.getAttribute('alt')).toBe(':shipit:');
        expect(emoji?.getAttribute('title')).toBe(':shipit:');
    });

    it('links a bare URL, unwrapped while it has no preview', () => {
        const { host } = mount({
            messages: [message({ body: 'see https://desk.test/docs' })],
        });

        const link = find(host, 'message-link');

        expect(link?.getAttribute('href')).toBe('https://desk.test/docs');
        expect(link?.getAttribute('rel')).toBe('noopener noreferrer nofollow');
        expect(link?.getAttribute('target')).toBe('_blank');
        expect(stub(host, 'HoverCard')).toBeNull();
    });

    it('wraps a link in a hover card once its preview has unfurled', () => {
        const { host } = mount({
            messages: [
                message({
                    body: 'see https://desk.test/docs',
                    linkPreviews: [
                        {
                            url: 'https://desk.test/docs',
                            status: 'ready',
                            title: 'Docs',
                            description: null,
                            imageUrl: null,
                            siteName: null,
                        },
                    ],
                }),
            ],
        });

        expect(stub(host, 'HoverCard')).not.toBeNull();
        expect(find(host, 'link-preview-card')).not.toBeNull();
        expect(stub(host, 'LinkPreview')).not.toBeNull();
    });

    it('marks an edited message beside its body', () => {
        const { host } = mount({
            messages: [message({ editedAt: '2024-03-04T11:00:00.000Z' })],
        });

        expect(find(host, 'message-edited')?.textContent).toBe('(edited)');
    });

    it('fades a body still in flight to the server', () => {
        const { host } = mount({ pendingUuids: ['uuid-1'] });

        expect(find(host, 'message-body')?.className).toContain('opacity-60');
    });
});

describe('a deleted message', () => {
    it('replaces the body with its tombstone', () => {
        const { host } = mount({ messages: [message({ isDeleted: true })] });

        expect(find(host, 'message-tombstone')?.textContent?.trim()).toBe(
            'This message was deleted',
        );
        expect(find(host, 'message-body')).toBeNull();
    });

    it('drops the attachments, poll, reactions and forward card with it', () => {
        const { host } = mount({
            messages: [
                message({
                    isDeleted: true,
                    attachments: [{ id: 'a1' }] as Message['attachments'],
                    poll: { id: 'p1' } as Message['poll'],
                    forwardedFrom: {
                        id: 'f1',
                        body: 'x',
                        authorName: 'Peer',
                        authorIsBot: false,
                        authorOverride: null,
                        channelName: 'general',
                        isDeleted: false,
                        mentions: [],
                    },
                }),
            ],
        });

        expect(stub(host, 'MessageAttachments')).toBeNull();
        expect(stub(host, 'MessagePoll')).toBeNull();
        expect(stub(host, 'MessageReactions')).toBeNull();
        expect(stub(host, 'MessageForward')).toBeNull();
    });
});

describe('the markers around a row', () => {
    it('names who pinned a pinned message', () => {
        const { host } = mount({
            messages: [
                message({
                    pin: {
                        pinnedBy: { id: 'u2', name: 'Ada', avatar: null },
                        pinnedAt: '2024-03-04T10:31:00.000Z',
                    },
                }),
            ],
        });

        expect(
            find(host, 'message-pinned-indicator')?.textContent?.trim(),
        ).toBe('Pinned by Ada');
    });

    it('marks a send held in the offline outbox', () => {
        const { host } = mount({ queuedUuids: ['uuid-1'] });

        expect(find(host, 'message-queued')?.textContent?.trim()).toBe(
            'Queued — will send on reconnect',
        );
    });

    it('leaves the queued marker off a message that has been sent', () => {
        const { host } = mount();

        expect(find(host, 'message-queued')).toBeNull();
    });

    it('carries a machine-readable hover timestamp on every row', () => {
        const { host } = mount();

        const time = find(host, 'message-hover-time');

        expect(time?.getAttribute('datetime')).toBe('2024-03-04T10:30:00.000Z');
        expect(time?.getAttribute('aria-hidden')).toBe('true');
    });
});

describe('the reply quote', () => {
    const replied = message({
        replyTo: {
            id: 'parent',
            body: 'the question',
            authorName: 'Ada',
            authorIsBot: false,
            authorOverride: null,
            isDeleted: false,
            mentions: [],
        },
    });

    it('jumps to the replied message when pressed', () => {
        const { host, actions } = mount({ messages: [replied] });

        const quote = find(host, 'message-quote');

        expect(quote?.getAttribute('aria-label')).toBe(
            'Jump to replied message from Ada',
        );
        quote?.click();

        expect(actions.jump).toHaveBeenCalledExactlyOnceWith('parent');
    });

    it('bleeds the quote to the full timeline width below the breakpoint', () => {
        const { host } = mount({ messages: [replied] });

        expect(find(host, 'message-quote')?.className).toContain(
            'max-md:-ml-14',
        );
    });

    it('drops the quote once the message is deleted', () => {
        const { host } = mount({
            messages: [message({ ...replied, isDeleted: true })],
        });

        expect(find(host, 'message-quote')).toBeNull();
    });
});

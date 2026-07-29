// @vitest-environment jsdom
import { afterEach, expect, it } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick } from 'vue';
import SystemNotice from '@/components/timeline/SystemNotice.vue';
import type { Message } from '@/types';

/**
 * The timeline's centered, inert notice line. What is under test is the copy it
 * builds from the row alone — its type, its author, and (for a channel edit)
 * the author's own words in the body — since no rendered English is persisted.
 */
let app: App | null = null;

function notice(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        clientUuid: 'uuid-1',
        body: '',
        type: 'member_joined',
        user: { id: 'u1', name: 'Ada' },
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
        threadParticipants: [],
        threadLastReplyAt: null,
        threadFollowed: false,
        threadUnread: false,
        ...overrides,
    } as Message;
}

function render(message: Message, isDirect = false): string {
    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({
        render: () => h(SystemNotice, { message, isDirect }),
    });
    app.mount(host);

    return host.textContent?.trim() ?? '';
}

afterEach(async () => {
    app?.unmount();
    app = null;
    await nextTick();
    document.body.innerHTML = '';
});

it('names who joined or left, and what they joined or left', () => {
    expect(render(notice())).toBe('Ada joined the channel');
    expect(render(notice({ type: 'member_left' }))).toBe(
        'Ada left the channel',
    );
    expect(render(notice(), true)).toBe('Ada joined the conversation');
    expect(render(notice({ type: 'member_left' }), true)).toBe(
        'Ada left the conversation',
    );
});

it('quotes the new topic the row carries', () => {
    expect(
        render(notice({ type: 'topic_changed', body: 'Launch coordination' })),
    ).toBe('Ada set the topic to Launch coordination');
});

it('reads as a clearing when the topic notice carries nothing', () => {
    expect(render(notice({ type: 'topic_changed' }))).toBe(
        'Ada cleared the topic',
    );
});

it('names the channel a rename landed on', () => {
    expect(render(notice({ type: 'channel_renamed', body: 'Growth' }))).toBe(
        'Ada renamed the channel to Growth',
    );
});

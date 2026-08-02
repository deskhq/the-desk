import { describe, expect, it } from 'vitest';
import { ref } from 'vue';
import { useMessageStream } from '@/composables/useMessageStream';
import type { Message } from '@/types';

/**
 * Covers what a broadcast patch must not destroy. A broadcast carries no viewer
 * context, so every viewer-scoped field on it is the server default; overlaying
 * one wholesale would silently strip what the viewer-scoped page load resolved.
 */
function message(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        clientUuid: 'uuid-1',
        body: 'Deploy finished',
        type: 'standard',
        user: { id: 'bot', name: 'Deploy Bot', isBot: true },
        createdAt: '2026-07-10T12:00:00.000Z',
        editedAt: null,
        isDeleted: false,
        mentions: [],
        reactions: [],
        pin: null,
        poll: null,
        linkPreviews: [],
        attachments: [],
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
    } as unknown as Message;
}

describe('applyPatch', () => {
    it('keeps the credential attribution a viewer-free broadcast cannot carry', () => {
        const server = ref<Message[]>([
            message({
                postedVia: {
                    kind: 'incoming_webhook',
                    id: 'hook-a',
                    name: 'CI alerts',
                    botId: null,
                },
            }),
        ]);
        const stream = useMessageStream(server);

        // What an unfurl or an edit broadcasts: the same row, resolved without a
        // viewer, so it names no credential.
        stream.applyPatch(message({ body: 'Deploy finished (edited)' }));

        expect(stream.displayMessages.value[0].body).toBe(
            'Deploy finished (edited)',
        );
        expect(stream.displayMessages.value[0].postedVia?.name).toBe(
            'CI alerts',
        );
    });

    it('leaves an ordinary message without an attribution', () => {
        const server = ref<Message[]>([message()]);
        const stream = useMessageStream(server);

        stream.applyPatch(message({ body: 'edited' }));

        expect(stream.displayMessages.value[0].postedVia ?? null).toBeNull();
    });
});

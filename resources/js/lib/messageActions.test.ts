import { describe, expect, it } from 'vitest';
import {
    canDeleteMessage,
    canEditMessage,
    canForwardMessage,
    canReactToMessage,
    canRemindAboutMessage,
    canReplyToMessage,
    canStartThreadFromMessage,
    hasAnyMessageAction,
    showsThreadSummary,
} from '@/lib/messageActions';
import type { MessageActionContext } from '@/lib/messageActions';
import type { Message } from '@/types';

/** A message carrying just the fields the action guards read. */
function message(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        clientUuid: 'uuid-1',
        body: 'hello',
        user: { id: 'peer', name: 'Peer' },
        createdAt: '2024-01-01T00:00:00.000Z',
        editedAt: null,
        isDeleted: false,
        mentions: [],
        linkPreviews: [],
        reactions: [],
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
    };
}

/** A viewer context, defaulting to a reacting, non-moderating member outside a thread. */
function context(
    overrides: Partial<MessageActionContext> = {},
): MessageActionContext {
    return {
        currentUserId: 'me',
        canReact: true,
        canModerate: false,
        inThread: false,
        pending: false,
        ...overrides,
    };
}

describe('messageActions guards', () => {
    describe('canEditMessage', () => {
        it('is true only for the viewer’s own live message', () => {
            const own = message({ user: { id: 'me', name: 'Me' } });

            expect(canEditMessage(own, context())).toBe(true);
            expect(canEditMessage(message(), context())).toBe(false);
        });

        it('is false for a deleted or pending message', () => {
            const own = { user: { id: 'me', name: 'Me' } } as const;

            expect(
                canEditMessage(message({ ...own, isDeleted: true }), context()),
            ).toBe(false);
            expect(
                canEditMessage(message(own), context({ pending: true })),
            ).toBe(false);
        });
    });

    describe('canDeleteMessage', () => {
        it('allows the author, or a moderator on anyone’s message', () => {
            expect(
                canDeleteMessage(
                    message({ user: { id: 'me', name: 'Me' } }),
                    context(),
                ),
            ).toBe(true);
            expect(canDeleteMessage(message(), context())).toBe(false);
            expect(
                canDeleteMessage(message(), context({ canModerate: true })),
            ).toBe(true);
        });

        it('is false for a deleted or pending message even for a moderator', () => {
            expect(
                canDeleteMessage(
                    message({ isDeleted: true }),
                    context({ canModerate: true }),
                ),
            ).toBe(false);
            expect(
                canDeleteMessage(
                    message(),
                    context({ canModerate: true, pending: true }),
                ),
            ).toBe(false);
        });
    });

    describe('canReplyToMessage', () => {
        it('is true for a live message in the main timeline', () => {
            expect(canReplyToMessage(message(), context())).toBe(true);
        });

        it('is suppressed inside a thread panel, or for deleted/pending rows', () => {
            expect(
                canReplyToMessage(message(), context({ inThread: true })),
            ).toBe(false);
            expect(
                canReplyToMessage(message({ isDeleted: true }), context()),
            ).toBe(false);
            expect(
                canReplyToMessage(message(), context({ pending: true })),
            ).toBe(false);
        });
    });

    describe('canForwardMessage', () => {
        it('is true for any live message, including inside a thread', () => {
            expect(
                canForwardMessage(message(), context({ inThread: true })),
            ).toBe(true);
        });

        it('is false for a deleted or pending message', () => {
            expect(
                canForwardMessage(message({ isDeleted: true }), context()),
            ).toBe(false);
            expect(
                canForwardMessage(message(), context({ pending: true })),
            ).toBe(false);
        });
    });

    describe('canReactToMessage', () => {
        it('requires react permission on a live message', () => {
            expect(canReactToMessage(message(), context())).toBe(true);
            expect(
                canReactToMessage(message(), context({ canReact: false })),
            ).toBe(false);
            expect(
                canReactToMessage(message({ isDeleted: true }), context()),
            ).toBe(false);
        });
    });

    describe('canRemindAboutMessage', () => {
        it('is true for any live message, false for deleted or pending', () => {
            expect(
                canRemindAboutMessage(message(), context({ inThread: true })),
            ).toBe(true);
            expect(
                canRemindAboutMessage(message({ isDeleted: true }), context()),
            ).toBe(false);
        });
    });

    describe('canStartThreadFromMessage', () => {
        it('is true only for a live root in the main timeline', () => {
            expect(canStartThreadFromMessage(message(), context())).toBe(true);
        });

        it('is false for a reply, inside a thread, or a deleted row', () => {
            expect(
                canStartThreadFromMessage(
                    message({ threadRootId: 'root' }),
                    context(),
                ),
            ).toBe(false);
            expect(
                canStartThreadFromMessage(
                    message(),
                    context({ inThread: true }),
                ),
            ).toBe(false);
            expect(
                canStartThreadFromMessage(
                    message({ isDeleted: true }),
                    context(),
                ),
            ).toBe(false);
        });
    });

    describe('showsThreadSummary', () => {
        it('shows for a root with replies in the main timeline, even when deleted', () => {
            expect(
                showsThreadSummary(
                    message({ threadReplyCount: 2, isDeleted: true }),
                    context(),
                ),
            ).toBe(true);
        });

        it('is hidden with no replies, or inside a thread panel', () => {
            expect(showsThreadSummary(message(), context())).toBe(false);
            expect(
                showsThreadSummary(
                    message({ threadReplyCount: 2 }),
                    context({ inThread: true }),
                ),
            ).toBe(false);
        });
    });

    describe('hasAnyMessageAction', () => {
        it('is true when at least one action is available', () => {
            expect(hasAnyMessageAction(message(), context())).toBe(true);
        });

        it('is false for a deleted message with no permissions', () => {
            expect(
                hasAnyMessageAction(
                    message({ isDeleted: true }),
                    context({ canReact: false }),
                ),
            ).toBe(false);
        });

        it('is true for a deleted root that still owns a thread summary’s siblings', () => {
            // A deleted, non-own message with react turned off exposes no bar actions.
            expect(
                hasAnyMessageAction(
                    message({ isDeleted: true }),
                    context({ canReact: false, canModerate: true }),
                ),
            ).toBe(false);
        });
    });
});

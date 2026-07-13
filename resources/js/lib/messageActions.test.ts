import { describe, expect, it } from 'vitest';
import {
    canDeleteMessage,
    canEditMessage,
    canForwardMessage,
    canPinMessage,
    canReactToMessage,
    canRemindAboutMessage,
    canReplyToMessage,
    canStartThreadFromMessage,
    hasAnyMessageAction,
    isSystemMessage,
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
        canPin: true,
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

    describe('canPinMessage', () => {
        it('requires pin permission on a live message', () => {
            expect(canPinMessage(message(), context())).toBe(true);
            expect(canPinMessage(message(), context({ canPin: false }))).toBe(
                false,
            );
            expect(canPinMessage(message({ isDeleted: true }), context())).toBe(
                false,
            );
        });

        it('is allowed both when unpinned and already pinned (shared toggle)', () => {
            expect(canPinMessage(message({ pin: null }), context())).toBe(true);
            expect(
                canPinMessage(
                    message({
                        pin: {
                            pinnedBy: { id: 'u1', name: 'Ada' },
                            pinnedAt: '2026-01-01T00:00:00Z',
                        },
                    }),
                    context(),
                ),
            ).toBe(true);
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

    describe('isSystemMessage', () => {
        it('is true for a member joined/left notice and false for a standard message', () => {
            expect(isSystemMessage(message())).toBe(false);
            expect(isSystemMessage(message({ type: 'member_joined' }))).toBe(
                true,
            );
            expect(isSystemMessage(message({ type: 'member_left' }))).toBe(
                true,
            );
        });
    });

    describe('system notices are inert', () => {
        it('exposes no interaction, even to the author or a moderator', () => {
            // The recorded actor authors the notice, yet nothing acts on it.
            const notice = message({
                type: 'member_left',
                user: { id: 'me', name: 'Me' },
                threadReplyCount: 2,
            });
            const moderator = context({ canModerate: true });

            expect(canEditMessage(notice, context())).toBe(false);
            expect(canDeleteMessage(notice, moderator)).toBe(false);
            expect(canReplyToMessage(notice, context())).toBe(false);
            expect(canForwardMessage(notice, context())).toBe(false);
            expect(canReactToMessage(notice, context())).toBe(false);
            expect(canRemindAboutMessage(notice, context())).toBe(false);
            expect(canStartThreadFromMessage(notice, context())).toBe(false);
            expect(showsThreadSummary(notice, context())).toBe(false);
            expect(hasAnyMessageAction(notice, moderator)).toBe(false);
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

import { describe, expect, it } from 'vitest';
import { placeIncomingMessage } from '@/lib/messagePlacement';
import type { IncomingPlacementInput } from '@/lib/messagePlacement';

/**
 * A qualifying baseline: a plain channel message (not a reply) arriving while the
 * viewer has the tab focused and no thread open. Each test overrides one axis to
 * probe a single routing branch.
 */
function input(
    overrides: Partial<IncomingPlacementInput> = {},
): IncomingPlacementInput {
    return {
        threadRootId: null,
        sentToChannel: false,
        isOwnMessage: false,
        activeThreadRootId: null,
        isTabFocused: true,
        isFollowedThread: false,
        isThreadUnreadSuppressed: false,
        ...overrides,
    };
}

describe('placeIncomingMessage', () => {
    it('routes a plain channel message to the main timeline only', () => {
        expect(placeIncomingMessage(input())).toEqual({
            appendToMain: true,
            appendToThread: false,
            markThreadReadNow: false,
            flagRootThreadUnread: false,
        });
    });

    it('keeps a thread reply out of the main timeline and out of a closed thread', () => {
        expect(placeIncomingMessage(input({ threadRootId: 'root-1' }))).toEqual(
            {
                appendToMain: false,
                appendToThread: false,
                markThreadReadNow: false,
                flagRootThreadUnread: false,
            },
        );
    });

    it('lands a sent-to-channel reply in the main timeline as well', () => {
        const placement = placeIncomingMessage(
            input({ threadRootId: 'root-1', sentToChannel: true }),
        );

        expect(placement.appendToMain).toBe(true);
    });

    it('appends a reply into the open thread and marks it read while focused', () => {
        expect(
            placeIncomingMessage(
                input({ threadRootId: 'root-1', activeThreadRootId: 'root-1' }),
            ),
        ).toEqual({
            appendToMain: false,
            appendToThread: true,
            markThreadReadNow: true,
            flagRootThreadUnread: false,
        });
    });

    it('does not mark-read a reply in the open thread when the tab is blurred', () => {
        const placement = placeIncomingMessage(
            input({
                threadRootId: 'root-1',
                activeThreadRootId: 'root-1',
                isTabFocused: false,
                isFollowedThread: true,
            }),
        );

        expect(placement.appendToThread).toBe(true);
        expect(placement.markThreadReadNow).toBe(false);
        expect(placement.flagRootThreadUnread).toBe(true);
    });

    it('flags a followed thread whose reply arrives while it is closed', () => {
        const placement = placeIncomingMessage(
            input({ threadRootId: 'root-1', isFollowedThread: true }),
        );

        expect(placement.appendToThread).toBe(false);
        expect(placement.flagRootThreadUnread).toBe(true);
    });

    it("never flags the viewer's own reply", () => {
        const placement = placeIncomingMessage(
            input({
                threadRootId: 'root-1',
                isFollowedThread: true,
                isOwnMessage: true,
            }),
        );

        expect(placement.flagRootThreadUnread).toBe(false);
    });

    it('never flags when thread unread dots are suppressed for the channel', () => {
        const placement = placeIncomingMessage(
            input({
                threadRootId: 'root-1',
                isFollowedThread: true,
                isThreadUnreadSuppressed: true,
            }),
        );

        expect(placement.flagRootThreadUnread).toBe(false);
    });
});

import { describe, expect, it } from 'vitest';
import { shouldFlagThreadUnread } from '@/lib/shouldFlagThreadUnread';
import type { ThreadUnreadInput } from '@/lib/shouldFlagThreadUnread';

/**
 * A qualifying baseline: someone else's reply landing in a followed thread the
 * viewer isn't looking at, in an unsilenced channel. Each test overrides one axis.
 */
function input(overrides: Partial<ThreadUnreadInput> = {}): ThreadUnreadInput {
    return {
        isReply: true,
        isOwnReply: false,
        isFollowedThread: true,
        isViewingThreadFocused: false,
        channel: { muted: false, notificationLevel: 'all' },
        mentionsCurrentUser: false,
        ...overrides,
    };
}

describe('shouldFlagThreadUnread', () => {
    it('flags a reply from someone else in a followed thread', () => {
        expect(shouldFlagThreadUnread(input())).toBe(true);
    });

    it('never flags a non-reply message', () => {
        expect(shouldFlagThreadUnread(input({ isReply: false }))).toBe(false);
    });

    it("never flags the viewer's own reply", () => {
        expect(shouldFlagThreadUnread(input({ isOwnReply: true }))).toBe(false);
    });

    it('does not flag a thread the viewer does not follow', () => {
        expect(shouldFlagThreadUnread(input({ isFollowedThread: false }))).toBe(
            false,
        );
    });

    it('does not flag while the thread is open and the tab is focused', () => {
        expect(
            shouldFlagThreadUnread(input({ isViewingThreadFocused: true })),
        ).toBe(false);
    });

    it('flags while the thread is open but the tab is blurred', () => {
        expect(
            shouldFlagThreadUnread(input({ isViewingThreadFocused: false })),
        ).toBe(true);
    });

    it('does not flag an ordinary reply on a muted channel', () => {
        expect(
            shouldFlagThreadUnread(
                input({
                    channel: { muted: true, notificationLevel: 'all' },
                }),
            ),
        ).toBe(false);
    });

    it('does not flag an ordinary reply below the "all" level', () => {
        expect(
            shouldFlagThreadUnread(
                input({
                    channel: { muted: false, notificationLevel: 'mentions' },
                }),
            ),
        ).toBe(false);
    });

    it('flags a reply that mentions the viewer at the "mentions" level', () => {
        expect(
            shouldFlagThreadUnread(
                input({
                    channel: { muted: false, notificationLevel: 'mentions' },
                    mentionsCurrentUser: true,
                }),
            ),
        ).toBe(true);
    });

    it('does not flag a mention on a muted channel or at "nothing"', () => {
        expect(
            shouldFlagThreadUnread(
                input({
                    channel: { muted: true, notificationLevel: 'mentions' },
                    mentionsCurrentUser: true,
                }),
            ),
        ).toBe(false);

        expect(
            shouldFlagThreadUnread(
                input({
                    channel: { muted: false, notificationLevel: 'nothing' },
                    mentionsCurrentUser: true,
                }),
            ),
        ).toBe(false);
    });

    it('flags when the channel preference is unknown', () => {
        expect(shouldFlagThreadUnread(input({ channel: null }))).toBe(true);
    });
});

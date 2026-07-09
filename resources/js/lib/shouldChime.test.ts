import { describe, expect, it } from 'vitest';
import { shouldChime } from '@/lib/shouldChime';
import type { ChimeDecisionInput } from '@/lib/shouldChime';

/**
 * A qualifying baseline: an ordinary message from someone else in an unmuted
 * "all" channel while the tab is blurred. Each test overrides one axis.
 */
function input(
    overrides: Partial<ChimeDecisionInput> = {},
): ChimeDecisionInput {
    return {
        chimeEnabled: true,
        isOwnMessage: false,
        isChannelMessage: true,
        mentionsCurrentUser: false,
        channel: { muted: false, notificationLevel: 'all' },
        tabHasFocus: false,
        isActiveChannel: false,
        ...overrides,
    };
}

describe('shouldChime', () => {
    it('chimes for an ordinary message in an unmuted "all" channel while blurred', () => {
        expect(shouldChime(input())).toBe(true);
    });

    it('never chimes when chimes are disabled', () => {
        expect(shouldChime(input({ chimeEnabled: false }))).toBe(false);
    });

    it("never chimes for the user's own message", () => {
        expect(shouldChime(input({ isOwnMessage: true }))).toBe(false);
    });

    it('never chimes for a channel the user does not belong to', () => {
        expect(shouldChime(input({ channel: null }))).toBe(false);
    });

    it('never chimes for a muted channel, even on a mention', () => {
        expect(
            shouldChime(
                input({
                    channel: { muted: true, notificationLevel: 'all' },
                    mentionsCurrentUser: true,
                }),
            ),
        ).toBe(false);
    });

    it('does not chime for ordinary traffic at the "mentions" level', () => {
        expect(
            shouldChime(
                input({
                    channel: { muted: false, notificationLevel: 'mentions' },
                }),
            ),
        ).toBe(false);
    });

    it('chimes for a direct mention at the "mentions" level', () => {
        expect(
            shouldChime(
                input({
                    channel: { muted: false, notificationLevel: 'mentions' },
                    mentionsCurrentUser: true,
                }),
            ),
        ).toBe(true);
    });

    it('never chimes at the "nothing" level, even on a mention', () => {
        expect(
            shouldChime(
                input({
                    channel: { muted: false, notificationLevel: 'nothing' },
                    mentionsCurrentUser: true,
                }),
            ),
        ).toBe(false);
    });

    it('does not chime for a thread-only reply that does not mention the user', () => {
        expect(shouldChime(input({ isChannelMessage: false }))).toBe(false);
    });

    it('chimes for a thread-only reply that mentions the user', () => {
        expect(
            shouldChime(
                input({ isChannelMessage: false, mentionsCurrentUser: true }),
            ),
        ).toBe(true);
    });

    it('does not chime while actively viewing the channel (focused + open)', () => {
        expect(
            shouldChime(input({ tabHasFocus: true, isActiveChannel: true })),
        ).toBe(false);
    });

    it('chimes for the open channel when the tab is blurred', () => {
        expect(
            shouldChime(input({ tabHasFocus: false, isActiveChannel: true })),
        ).toBe(true);
    });

    it('chimes for a background channel while focused elsewhere', () => {
        expect(
            shouldChime(input({ tabHasFocus: true, isActiveChannel: false })),
        ).toBe(true);
    });
});

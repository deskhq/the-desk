import { describe, expect, it } from 'vitest';
import {
    EMPTY_DIGEST,
    NOTHING_UNREAD,
    channelUnread,
    workspaceUnread,
} from '@/lib/unreadDigest';
import type { UnreadDigest } from '@/types/unread';

function digest(overrides: Partial<UnreadDigest> = {}): UnreadDigest {
    return { ...EMPTY_DIGEST, ...overrides };
}

describe('unread digest lookups', () => {
    it('reports what the digest names for a channel', () => {
        const counts = channelUnread(
            digest({ channels: { 'ch-1': { unread: 4, mention: 2 } } }),
            'ch-1',
        );

        expect(counts).toEqual({ unread: 4, mention: 2 });
    });

    it('reads a channel the digest does not name as nothing waiting', () => {
        expect(channelUnread(digest(), 'ch-absent')).toBe(NOTHING_UNREAD);
    });

    it('reports what the digest names for a workspace', () => {
        const counts = workspaceUnread(
            digest({ teams: { 't-1': { unread: 9, mention: 0 } } }),
            't-1',
        );

        expect(counts).toEqual({ unread: 9, mention: 0 });
    });

    it('reads a workspace the digest does not name as nothing waiting', () => {
        expect(workspaceUnread(digest(), 't-absent')).toBe(NOTHING_UNREAD);
    });
});

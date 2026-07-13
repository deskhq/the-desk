import { describe, expect, it } from 'vitest';
import { unreadDividerMessageId } from '@/lib/unreadDivider';
import type { MessageType } from '@/types';

/**
 * A time-ordered, uuid-shaped id for a given sequence number. Zero-padded to a
 * fixed width so the ids sort chronologically under a lexicographic comparison,
 * mirroring the ordered UUIDs the server assigns to real messages.
 */
function id(seq: number): string {
    return `019f44c7-0000-7000-8000-${String(seq).padStart(12, '0')}`;
}

/**
 * A minimal message row for boundary computation: the id, author, and kind.
 */
function message(
    messageId: string,
    userId: string,
    type: MessageType = 'standard',
) {
    return {
        id: messageId,
        user: { id: userId, name: `User ${userId}` },
        type,
    };
}

const ME = 'me';
const PEER = 'peer';

describe('unreadDividerMessageId', () => {
    it('marks the first message posted after the read pointer', () => {
        const messages = [
            message(id(1), PEER),
            message(id(2), PEER),
            message(id(3), PEER),
            message(id(4), PEER),
        ];

        expect(unreadDividerMessageId(messages, id(2), ME)).toBe(id(3));
    });

    it('returns null when every message has been read', () => {
        const messages = [message(id(1), PEER), message(id(2), PEER)];

        expect(unreadDividerMessageId(messages, id(2), ME)).toBeNull();
    });

    it('treats a null pointer as an entirely unread channel', () => {
        const messages = [message(id(1), PEER), message(id(2), PEER)];

        expect(unreadDividerMessageId(messages, null, ME)).toBe(id(1));
    });

    it("skips the reader's own messages at the boundary", () => {
        const messages = [
            message(id(1), PEER),
            message(id(2), ME),
            message(id(3), ME),
            message(id(4), PEER),
        ];

        // Read up to 1; 2 and 3 are your own, so the "New" line sits above 4.
        expect(unreadDividerMessageId(messages, id(1), ME)).toBe(id(4));
    });

    it("returns null when the only unread messages are the reader's own", () => {
        const messages = [
            message(id(1), PEER),
            message(id(2), ME),
            message(id(3), ME),
        ];

        expect(unreadDividerMessageId(messages, id(1), ME)).toBeNull();
    });

    it('ignores a random client uuid from an optimistic send awaiting confirmation', () => {
        const messages = [
            message(id(1), PEER),
            // A random v4 client uuid that sorts *after* the ordered read pointer,
            // yet is the reader's own send and must not become the boundary.
            message('c1d2e3f4-5a6b-4c7d-8e9f-0a1b2c3d4e5f', ME),
            message(id(2), PEER),
        ];

        expect(unreadDividerMessageId(messages, id(1), ME)).toBe(id(2));
    });

    it('skips ambient system notices when placing the boundary', () => {
        const messages = [
            message(id(1), PEER),
            // A peer's "member joined" notice sitting first after the pointer must
            // not open the boundary — it's ambient, so the line lands on 3.
            message(id(2), PEER, 'member_joined'),
            message(id(3), PEER),
        ];

        expect(unreadDividerMessageId(messages, id(1), ME)).toBe(id(3));
    });

    it('returns null when the only unread rows are system notices', () => {
        const messages = [
            message(id(1), PEER),
            message(id(2), PEER, 'member_left'),
        ];

        expect(unreadDividerMessageId(messages, id(1), ME)).toBeNull();
    });

    it('returns null for an empty channel', () => {
        expect(unreadDividerMessageId([], id(5), ME)).toBeNull();
    });
});

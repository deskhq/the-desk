import { describe, expect, it } from 'vitest';
import { readersForMessage } from '@/lib/readReceipts';
import type { ChannelReader } from '@/types';

/**
 * A time-ordered, uuid-shaped id for a given sequence number. Zero-padded to a
 * fixed width so the ids sort chronologically under a lexicographic comparison,
 * mirroring the ordered UUIDs the server assigns to real messages.
 */
function id(seq: number): string {
    return `019f44c7-0000-7000-8000-${String(seq).padStart(12, '0')}`;
}

/**
 * A reader row: the member and how far they have read.
 */
function reader(
    userId: string,
    name: string,
    lastReadMessageId: string | null,
): ChannelReader {
    return { user: { id: userId, name }, lastReadMessageId };
}

const ME = 'me';

describe('readersForMessage', () => {
    it('includes members whose pointer sits at or after the message', () => {
        const readers = [
            reader('a', 'Alice', id(5)), // read past it
            reader('b', 'Bob', id(3)), // read exactly it
            reader('c', 'Carol', id(2)), // has not reached it
        ];

        expect(readersForMessage(readers, id(3), ME)).toEqual([
            { id: 'a', name: 'Alice' },
            { id: 'b', name: 'Bob' },
        ]);
    });

    it('excludes a member who has never read the channel (null pointer)', () => {
        const readers = [reader('a', 'Alice', null)];

        expect(readersForMessage(readers, id(1), ME)).toEqual([]);
    });

    it('excludes the current user defensively', () => {
        const readers = [reader(ME, 'Me', id(9)), reader('a', 'Alice', id(9))];

        expect(readersForMessage(readers, id(3), ME)).toEqual([
            { id: 'a', name: 'Alice' },
        ]);
    });

    it('sorts the roster by name for stable avatar order', () => {
        const readers = [
            reader('c', 'Carol', id(9)),
            reader('a', 'Alice', id(9)),
            reader('b', 'Bob', id(9)),
        ];

        expect(
            readersForMessage(readers, id(3), ME).map((member) => member.name),
        ).toEqual(['Alice', 'Bob', 'Carol']);
    });

    it('returns an empty roster when nobody has read that far', () => {
        const readers = [
            reader('a', 'Alice', id(1)),
            reader('b', 'Bob', id(2)),
        ];

        expect(readersForMessage(readers, id(5), ME)).toEqual([]);
    });

    it('returns an empty roster when there are no readers', () => {
        expect(readersForMessage([], id(1), ME)).toEqual([]);
    });
});

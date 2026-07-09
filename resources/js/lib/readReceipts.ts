import type { ChannelReader, MessageAuthor } from '@/types';

/**
 * The members who have read up to (or past) a given message — the "Seen by"
 * roster for that message.
 *
 * A member has seen the message when their `lastReadMessageId` sorts at or after
 * the message's id. Message ids are time-ordered UUIDs, so they compare
 * chronologically under a plain lexicographic string comparison — no numeric
 * coercion (a UUID is `NaN` as a number). A null pointer means the member has
 * never read the channel, so they have seen nothing. The current user is dropped
 * defensively (the server already excludes them), and the result is sorted by
 * name so avatar order is stable across realtime updates.
 */
export function readersForMessage(
    readers: ChannelReader[],
    messageId: string,
    currentUserId: string,
): MessageAuthor[] {
    return readers
        .filter(
            (reader) =>
                reader.user.id !== currentUserId &&
                reader.lastReadMessageId !== null &&
                reader.lastReadMessageId >= messageId,
        )
        .map((reader) => reader.user)
        .sort((a, b) => a.name.localeCompare(b.name));
}

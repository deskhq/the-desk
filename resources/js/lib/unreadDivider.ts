import type { Message } from '@/types';

/**
 * The id of the message where a "New messages" divider should sit — the first
 * message the reader has not yet seen, or null when there is no boundary to mark.
 *
 * `lastReadMessageId` is the read pointer captured when the channel opened; a
 * message is unread when its id sorts after that pointer. A null pointer means
 * the channel has never been read, so every message counts as unread. The
 * reader's own messages at the boundary are skipped — you don't need a "new"
 * marker above something you just posted — so an entirely-own unread tail yields
 * null.
 *
 * Message ids are time-ordered UUIDs, so they sort chronologically under a plain
 * lexicographic string comparison — no numeric coercion (a UUID is `NaN` as a
 * number). An optimistic send still keyed by its random client uuid is always
 * the reader's own message, so the author check below drops it regardless of
 * where its random id happens to sort; the boundary is fixed at channel open
 * rather than moved by later traffic.
 */
export function unreadDividerMessageId(
    messages: Pick<Message, 'id' | 'user'>[],
    lastReadMessageId: string | null,
    currentUserId: string,
): string | null {
    for (const message of messages) {
        // A null pointer means the channel has never been read, so nothing is
        // yet read and the first peer message wins.
        if (lastReadMessageId !== null && message.id <= lastReadMessageId) {
            continue;
        }

        if (message.user.id === currentUserId) {
            continue;
        }

        return message.id;
    }

    return null;
}

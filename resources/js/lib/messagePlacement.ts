import { shouldFlagThreadUnread } from '@/lib/shouldFlagThreadUnread';

export type IncomingPlacementInput = {
    /** The reply's root message id, or `null` for a top-level channel message. */
    threadRootId: string | null;
    /** A thread reply the author also chose to echo into the channel timeline. */
    sentToChannel: boolean;
    /** The arriving message was authored by the current viewer. */
    isOwnMessage: boolean;
    /** The root id of the thread currently open in the panel, or `null`. */
    activeThreadRootId: string | null;
    /** The tab is focused, so an open thread's replies are actively being read. */
    isTabFocused: boolean;
    /**
     * The viewer follows the reply's thread (authored its root, replied, or was
     * @mentioned); only followed threads raise a dot. See {@see shouldFlagThreadUnread}.
     */
    isFollowedThread: boolean;
    /** Thread dots are silenced for this channel (muted or below "all"). */
    isThreadUnreadSuppressed: boolean;
};

export type IncomingPlacement = {
    /** Append the message to the main channel timeline. */
    appendToMain: boolean;
    /** Append the message to the open thread panel. */
    appendToThread: boolean;
    /** Advance the open thread's read pointer now (it's focused, so already read). */
    markThreadReadNow: boolean;
    /** Raise the unread dot on the reply's root back in the main timeline. */
    flagRootThreadUnread: boolean;
};

/**
 * Decide where a freshly-arrived realtime message belongs: the main timeline, the
 * open thread, both, or neither — and whether it advances or dots the thread's
 * read state.
 *
 * This is the pure decision core of `Show.vue`'s realtime routing. A top-level
 * message (or a reply explicitly sent to the channel) shows in the main timeline;
 * a reply into the *open* thread appends there and, while the tab is focused,
 * keeps that thread read; a reply into a *closed* followed thread raises its
 * unread dot instead — the last branch deferring to {@see shouldFlagThreadUnread}
 * so a live dot and a navigation-time dot agree.
 */
export function placeIncomingMessage(
    input: IncomingPlacementInput,
): IncomingPlacement {
    const isReply = input.threadRootId !== null;
    const isActiveThread =
        isReply && input.threadRootId === input.activeThreadRootId;
    const isViewingThreadFocused = isActiveThread && input.isTabFocused;

    return {
        appendToMain: !isReply || input.sentToChannel,
        appendToThread: isActiveThread,
        markThreadReadNow: isViewingThreadFocused,
        flagRootThreadUnread:
            isReply &&
            !isViewingThreadFocused &&
            shouldFlagThreadUnread({
                isReply: true,
                isOwnReply: input.isOwnMessage,
                isFollowedThread: input.isFollowedThread,
                isViewingThreadFocused: false,
                isSuppressed: input.isThreadUnreadSuppressed,
            }),
    };
}

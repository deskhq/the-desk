import { alertsOnMention, alertsOnUnread } from '@/lib/alerts';
import type { AlertPreference } from '@/lib/alerts';

export type ThreadUnreadInput = {
    /** The arriving message is a thread reply (carries a `threadRootId`). */
    isReply: boolean;
    /** The reply was authored by the current user (own replies never dot). */
    isOwnReply: boolean;
    /**
     * The viewer follows the reply's thread — they authored its root, replied in
     * it, or were @mentioned. Only followed threads raise a dot, matching the
     * server's `threadUnread` derivation.
     */
    isFollowedThread: boolean;
    /**
     * The thread is open in the panel and the tab is focused, so the reply is
     * already being read and must not raise a dot. A blurred panel still dots,
     * mirroring how a blurred channel still badges.
     */
    isViewingThreadFocused: boolean;
    /**
     * The viewer's preference for the reply's channel, or `null` when it is not
     * known — which silences nothing, matching the server's outer join onto a
     * membership row that may not exist.
     */
    channel: AlertPreference | null;
    /** The reply directly @mentions the viewer. */
    mentionsCurrentUser: boolean;
};

/**
 * Decide whether a freshly-arrived realtime reply should raise the unread dot on
 * its root's "N replies" affordance.
 *
 * The gate mirrors the server's per-viewer `threadUnread` derivation so a live
 * dot and a navigation-time dot agree: a reply only dots a thread the viewer
 * follows, authored by someone else, while they are not already reading it and
 * the channel preference lets it through — read through `lib/alerts.ts`, the
 * rule's one client home, per reply rather than per channel so a mention still
 * dots a thread on a "mentions"-level channel (#1143).
 */
export function shouldFlagThreadUnread(input: ThreadUnreadInput): boolean {
    if (!input.isReply || input.isOwnReply) {
        return false;
    }

    if (!input.isFollowedThread || !alertsForViewer(input)) {
        return false;
    }

    return !input.isViewingThreadFocused;
}

/** Whether the channel preference lets this particular reply alert the viewer. */
function alertsForViewer(input: ThreadUnreadInput): boolean {
    if (input.channel === null) {
        return true;
    }

    return input.mentionsCurrentUser
        ? alertsOnMention(input.channel)
        : alertsOnUnread(input.channel);
}

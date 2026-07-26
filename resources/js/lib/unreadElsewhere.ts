import type { Channel } from '@/types/channels';

/** Highest numeral the badge spells out; anything past it renders as `99+`. */
const UNREAD_BADGE_CAP = 99;

/**
 * What the mobile sidebar toggle has to report: one numeral plus one boolean,
 * rolled up from every conversation the viewer is not currently reading.
 */
export type UnreadElsewhereSummary = {
    /**
     * The conversations that earn a numeral — @mentions, and DM traffic, which
     * is addressed to the viewer by definition.
     */
    count: number;
    /**
     * Whether anything at all is waiting, numeral-worthy or not. Ordinary
     * channel unread only reaches this flag, which inks the toggle's rail.
     */
    hasUnread: boolean;
};

/**
 * Roll the sidebar's per-conversation badges up into the single signal the
 * mobile toggle carries, since below `md` the sidebar itself is a sheet and
 * every badge it holds is off screen while a conversation is open.
 *
 * Three rules shape the rollup, all of them narrowing what the sidebar shows:
 *
 * - The open conversation is excluded — the badge means "unread *elsewhere*",
 *   and its own unread is already in front of the viewer.
 * - Muted conversations and those at the `nothing` notification level are
 *   excluded outright. The sidebar dims a muted row rather than silencing it,
 *   but a single aggregate cannot be dimmed per row, so a muted room would
 *   shout exactly as loudly as a live one.
 * - Only mentions and DM unread reach the numeral. Everything else contributes
 *   the `hasUnread` flag alone, which the glyph carries as a brass rail.
 */
export function summarizeUnreadElsewhere(
    channels: Channel[],
    activeChannelId: string | null,
): UnreadElsewhereSummary {
    let count = 0;
    let hasUnread = false;

    for (const channel of channels) {
        if (channel.id === activeChannelId) {
            continue;
        }

        if (channel.muted || channel.notificationLevel === 'nothing') {
            continue;
        }

        // A DM's whole unread run is addressed to the viewer, so all of it
        // counts; a channel contributes only its @mentions. Taking the larger of
        // the two on a DM keeps an @mention inside one from counting twice.
        count += channel.isDirect
            ? Math.max(channel.unreadCount, channel.mentionCount)
            : channel.mentionCount;

        if (channel.unreadCount > 0 || channel.mentionCount > 0) {
            hasUnread = true;
        }
    }

    return { count, hasUnread };
}

/**
 * Render a rollup count for the badge, capping it so a long-neglected inbox
 * cannot widen the toggle out of its hit target.
 */
export function formatUnreadCount(count: number): string {
    return count > UNREAD_BADGE_CAP ? `${UNREAD_BADGE_CAP}+` : String(count);
}

import type { NotificationLevel } from '@/types';

/** The viewer's standing preference for one channel, as the rule reads it. */
export type AlertPreference = {
    /** The viewer muted the channel, which silences every reading. */
    muted: boolean;
    /** The viewer's chosen notification level for the channel. */
    notificationLevel: NotificationLevel;
};

/**
 * Whether ordinary unread traffic alerts the viewer on this channel.
 *
 * Only the "all" level does, and muting silences it outright.
 *
 * The client twin of `NotificationLevel::alertsOnUnread()`, pinned against the
 * same case table (`tests/Fixtures/alert-cases.json`) so a change made on one
 * side of the wire cannot land without the other. ADR-0010 records why the rule
 * has exactly one home per language.
 */
export function alertsOnUnread(channel: AlertPreference): boolean {
    return !channel.muted && channel.notificationLevel === 'all';
}

/**
 * Whether a direct @mention alerts the viewer on this channel.
 *
 * Only the "nothing" level silences a mention; "mentions" and "all" both alert.
 * Muting silences them all. The wider of the two readings, deliberately: a
 * channel set to "mentions only" still has to raise the one message that named
 * the viewer, in the thread it landed in as much as in the sidebar.
 *
 * The client twin of `NotificationLevel::alertsOnMention()`.
 */
export function alertsOnMention(channel: AlertPreference): boolean {
    return !channel.muted && channel.notificationLevel !== 'nothing';
}

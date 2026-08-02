import { alertsOnMention, alertsOnUnread } from '@/lib/alerts';
import type { AlertPreference } from '@/lib/alerts';

export type ChimeDecisionInput = {
    /** Whether the user has an audible chime selected (their sound is not "off"). */
    chimeEnabled: boolean;
    /** The message was authored by the current user. */
    isOwnMessage: boolean;
    /**
     * The message counts as ordinary channel traffic — a top-level message or a
     * thread reply that was also broadcast to the channel. Thread-only replies
     * are `false` and only ever chime through the mention path, mirroring how the
     * sidebar unread badge excludes them.
     */
    isChannelMessage: boolean;
    /** The current user is directly @mentioned by the message. */
    mentionsCurrentUser: boolean;
    /**
     * The current user's per-channel preference, or `null` when the channel is
     * not in their sidebar (e.g. they are not a member) — which never chimes.
     */
    channel: AlertPreference | null;
    /** The browser tab currently has focus. */
    tabHasFocus: boolean;
    /** The message landed in the channel the user is actively viewing on screen. */
    isActiveChannel: boolean;
    /**
     * The user is in do-not-disturb at this instant — a manual pause still
     * running, or their quiet-hours window covering right now (see
     * `isDndActiveNow`). Suppresses everything, mentions included: DND is the
     * stronger, time-boxed claim on top of the standing per-channel levels.
     */
    dndActive: boolean;
};

/**
 * Decide whether a freshly-arrived realtime message should play a chime.
 *
 * The gate mirrors the sidebar badge semantics so a chime, an unread badge and a
 * mention badge stay consistent: the channel preference is read through
 * `lib/alerts.ts`, the rule's one client home. On top of that a chime is
 * suppressed for the user's own messages, when chimes are disabled, while the
 * user is in do-not-disturb, and when the user is already actively looking at
 * the message (its channel is open and the tab has focus).
 */
export function shouldChime(input: ChimeDecisionInput): boolean {
    if (!input.chimeEnabled) {
        return false;
    }

    if (input.dndActive) {
        return false;
    }

    if (input.isOwnMessage) {
        return false;
    }

    const { channel } = input;

    if (channel === null) {
        return false;
    }

    if (input.mentionsCurrentUser) {
        if (!alertsOnMention(channel)) {
            return false;
        }
    } else if (!alertsOnUnread(channel) || !input.isChannelMessage) {
        // Thread-only replies never count as ordinary traffic — they live in the
        // thread view, and only ever chime through the mention path above.
        return false;
    }

    // Never chime for a message the user is already looking at.
    if (input.tabHasFocus && input.isActiveChannel) {
        return false;
    }

    return true;
}

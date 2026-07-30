import { authorOverrideKey } from '@/lib/authorIdentity';
import { formatTimeOfDay } from '@/lib/datetime';
import { translate } from '@/lib/i18n';
import { isSystemMessage } from '@/lib/messageActions';
import type { AuthorOverride, Message, MessageAuthor } from '@/types';

/**
 * Consecutive messages from the same author within this window collapse under a
 * single avatar + header line in the timeline.
 */
export const GROUPING_WINDOW_MS = 5 * 60 * 1000;

/**
 * A run of consecutive messages from one author, rendered under a single avatar
 * gutter. `leadCreatedAt` is the timestamp shown under the avatar.
 */
export type TimelineGroup = {
    type: 'group';
    key: string;
    author: MessageAuthor;
    /**
     * The display identity the run's messages asked for, if any. Part of what
     * defines the run: one bot posting as two logical sources gets two groups.
     */
    authorOverride: AuthorOverride | null;
    /**
     * The incoming webhook behind the run, for the viewers the server told. Also
     * part of what defines the run: the header's hover card names this credential
     * on behalf of every row beneath it, so a run may never span two of them.
     */
    incomingWebhook: App.Data.IncomingWebhookSourceData | null;
    leadCreatedAt: string;
    messages: Message[];
};

/**
 * A divider between groups: a `day` boundary (carrying the crossing message's
 * timestamp so the view can format its label) or the `unread` "new" boundary.
 */
export type TimelineDivider = {
    type: 'divider';
    key: string;
    variant: 'day' | 'unread';
    iso?: string;
};

/**
 * A system notice (a member joined/left line) rendered on its own row as a
 * centered, inert line rather than an author-grouped chat bubble. It always
 * breaks the surrounding author run so it can never be folded into a group.
 */
export type TimelineSystemNotice = {
    type: 'system';
    key: string;
    message: Message;
};

export type TimelineItem =
    TimelineGroup | TimelineDivider | TimelineSystemNotice;

/**
 * The screen-reader accessible name for a single message row: the author's name
 * and the message's time of day (e.g. "Alice, 10:30 AM"), so list navigation
 * announces who said something and when without reading the body.
 */
export function messageAccessibleName(
    authorName: string,
    iso: string,
    timeZone?: string,
): string {
    return translate(':author, :time', {
        author: authorName,
        time: formatTimeOfDay(iso, timeZone),
    });
}

/**
 * The day bucket a timestamp falls in, as a stable string key. Uses the runner's
 * local date so dividers land on the viewer's calendar days.
 */
function dayKey(iso: string): string {
    return new Date(iso).toDateString();
}

/**
 * Fold a flat, chronological message list into the timeline's render items:
 * day dividers, the "new" unread boundary, and author-grouped runs.
 *
 * A new group begins whenever the day changes, the unread boundary is crossed,
 * the author (account or displayed identity) changes, or the same author pauses
 * longer than `groupingWindowMs`.
 * The unread divider sits directly above the first unread message and always
 * breaks the run so the boundary is never buried mid-group.
 */
export function buildTimelineItems(
    messages: Message[],
    unreadDividerId: string | null,
    groupingWindowMs: number = GROUPING_WINDOW_MS,
): TimelineItem[] {
    const items: TimelineItem[] = [];
    let currentGroup: TimelineGroup | null = null;
    let currentDay: string | null = null;
    let lastCreatedAt: string | null = null;

    for (const message of messages) {
        const messageDay = dayKey(message.createdAt);
        const startsNewDay = messageDay !== currentDay;

        if (startsNewDay) {
            items.push({
                type: 'divider',
                key: `divider-${messageDay}`,
                variant: 'day',
                iso: message.createdAt,
            });
            currentDay = messageDay;
        }

        // A system notice renders as its own centered, inert row: it breaks the
        // author run on both sides so it is never folded into a bubble group.
        if (isSystemMessage(message)) {
            items.push({
                type: 'system',
                key: `system-${message.id}`,
                message,
            });
            currentGroup = null;
            lastCreatedAt = message.createdAt;
            continue;
        }

        const isUnreadBoundary =
            unreadDividerId != null && message.id === unreadDividerId;

        if (isUnreadBoundary) {
            items.push({
                type: 'divider',
                key: 'unread-divider',
                variant: 'unread',
            });
        }

        // Same account, same displayed identity *and* same credential: a webhook
        // posting as two logical sources must not collapse them under one name
        // and avatar, and two webhooks must not collapse under one provenance.
        const sameAuthor =
            currentGroup?.author.id === message.user.id &&
            authorOverrideKey(currentGroup?.authorOverride) ===
                authorOverrideKey(message.authorOverride) &&
            (currentGroup?.incomingWebhook?.id ?? null) ===
                (message.incomingWebhook?.id ?? null);
        const withinWindow =
            lastCreatedAt !== null &&
            new Date(message.createdAt).getTime() -
                new Date(lastCreatedAt).getTime() <=
                groupingWindowMs;

        if (
            !currentGroup ||
            startsNewDay ||
            isUnreadBoundary ||
            !sameAuthor ||
            !withinWindow
        ) {
            currentGroup = {
                type: 'group',
                key: `group-${message.id}`,
                author: message.user,
                authorOverride: message.authorOverride ?? null,
                incomingWebhook: message.incomingWebhook ?? null,
                leadCreatedAt: message.createdAt,
                messages: [message],
            };
            items.push(currentGroup);
        } else {
            currentGroup.messages.push(message);
        }

        lastCreatedAt = message.createdAt;
    }

    return items;
}

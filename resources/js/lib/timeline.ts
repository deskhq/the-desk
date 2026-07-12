import { formatTimeOfDay } from '@/lib/datetime';
import { translate } from '@/lib/i18n';
import type { Message, MessageAuthor } from '@/types';

// Consecutive messages from the same author within this window collapse under a
// single avatar + header line in the timeline.
export const GROUPING_WINDOW_MS = 5 * 60 * 1000;

/**
 * A run of consecutive messages from one author, rendered under a single avatar
 * gutter. `leadCreatedAt` is the timestamp shown under the avatar.
 */
export type TimelineGroup = {
    type: 'group';
    key: string;
    author: MessageAuthor;
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

export type TimelineItem = TimelineGroup | TimelineDivider;

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
 * the author changes, or the same author pauses longer than `groupingWindowMs`.
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

        const isUnreadBoundary =
            unreadDividerId != null && message.id === unreadDividerId;

        if (isUnreadBoundary) {
            items.push({
                type: 'divider',
                key: 'unread-divider',
                variant: 'unread',
            });
        }

        const sameAuthor = currentGroup?.author.id === message.user.id;
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

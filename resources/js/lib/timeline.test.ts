import { describe, expect, it } from 'vitest';
import { buildTimelineItems, messageAccessibleName } from '@/lib/timeline';
import type { Message } from '@/types';

/** A message carrying just the fields the timeline grouping reads. */
function message(
    id: string,
    userId: string,
    name: string,
    createdAt: string,
): Message {
    return { id, user: { id: userId, name }, createdAt } as unknown as Message;
}

// Noon timestamps keep day boundaries unambiguous regardless of the runner's
// timezone; a calendar day never flips around midday.
const DAY_1_NOON = '2026-07-10T12:00:00.000Z';

function minutesLater(iso: string, minutes: number): string {
    return new Date(new Date(iso).getTime() + minutes * 60_000).toISOString();
}

describe('buildTimelineItems', () => {
    it('returns no items for an empty timeline', () => {
        expect(buildTimelineItems([], null)).toEqual([]);
    });

    it('groups consecutive messages from the same author within the window', () => {
        const items = buildTimelineItems(
            [
                message('m1', 'u1', 'Maya', DAY_1_NOON),
                message('m2', 'u1', 'Maya', minutesLater(DAY_1_NOON, 2)),
            ],
            null,
        );

        // A single day divider then one group holding both messages.
        expect(items.map((item) => item.type)).toEqual(['divider', 'group']);
        const group = items[1];
        expect(
            group.type === 'group' && group.messages.map((m) => m.id),
        ).toEqual(['m1', 'm2']);
        expect(group.type === 'group' && group.author.name).toBe('Maya');
    });

    it('starts a new group when the author changes', () => {
        const items = buildTimelineItems(
            [
                message('m1', 'u1', 'Maya', DAY_1_NOON),
                message('m2', 'u2', 'Jonas', minutesLater(DAY_1_NOON, 1)),
            ],
            null,
        );

        const groups = items.filter((item) => item.type === 'group');
        expect(groups).toHaveLength(2);
    });

    it('starts a new group when the same author pauses past the window', () => {
        const items = buildTimelineItems(
            [
                message('m1', 'u1', 'Maya', DAY_1_NOON),
                // 6 minutes later, past the 5-minute grouping window.
                message('m2', 'u1', 'Maya', minutesLater(DAY_1_NOON, 6)),
            ],
            null,
        );

        const groups = items.filter((item) => item.type === 'group');
        expect(groups).toHaveLength(2);
    });

    it('inserts a day divider carrying the crossing message time', () => {
        const nextDay = '2026-07-11T12:00:00.000Z';
        const items = buildTimelineItems(
            [
                message('m1', 'u1', 'Maya', DAY_1_NOON),
                message('m2', 'u1', 'Maya', nextDay),
            ],
            null,
        );

        const dividers = items.filter(
            (item) => item.type === 'divider' && item.variant === 'day',
        );
        expect(dividers).toHaveLength(2);
        expect(dividers[1].type === 'divider' && dividers[1].iso).toBe(nextDay);
    });

    it('breaks the group with an unread divider above the first unread message', () => {
        const items = buildTimelineItems(
            [
                message('m1', 'u1', 'Maya', DAY_1_NOON),
                message('m2', 'u1', 'Maya', minutesLater(DAY_1_NOON, 1)),
            ],
            'm2',
        );

        // day divider, group[m1], unread divider, group[m2].
        expect(items.map((item) => item.type)).toEqual([
            'divider',
            'group',
            'divider',
            'group',
        ]);
        const unread = items[2];
        expect(unread.type === 'divider' && unread.variant).toBe('unread');
    });
});

describe('messageAccessibleName', () => {
    // 2026-07-10 15:30 UTC, so UTC reads 3:30 PM.
    const INSTANT = '2026-07-10T15:30:00Z';

    it('composes the author name and the time of day', () => {
        const name = messageAccessibleName('Alice', INSTANT, 'UTC');

        expect(name).toContain('Alice');
        expect(name).toContain('3:30');
    });

    it('renders the time in the given zone', () => {
        expect(
            messageAccessibleName('Alice', INSTANT, 'America/New_York'),
        ).toContain('11:30');
    });
});

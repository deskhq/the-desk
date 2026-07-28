import { describe, expect, it } from 'vitest';
import {
    analyticsTiles,
    deltaVsPrevious,
    percentVsPrevious,
    toneClass,
} from '@/lib/analyticsTiles';
import type { AnalyticsStat, WorkspaceAnalytics } from '@/types';

/**
 * The dashboard's headline tiles, away from the markup that renders them: the
 * comparison lines each kind of delta produces, and the tone that pairs with
 * them.
 */
function stat(overrides: Partial<AnalyticsStat> = {}): AnalyticsStat {
    return {
        value: 0,
        total: null,
        delta: null,
        deltaPercent: null,
        secondary: null,
        ...overrides,
    };
}

function analytics(
    overrides: Partial<WorkspaceAnalytics> = {},
): WorkspaceAnalytics {
    return {
        range: '30d',
        days: 30,
        activeMembers: stat({ value: 12, total: 20, delta: 3 }),
        messagesPerDay: stat({ value: 48, deltaPercent: -12 }),
        messagesSent: stat({ value: 1440, secondary: 210 }),
        activeChannels: stat({ value: 6, total: 9, delta: 0 }),
        messagesByDay: [],
        topChannels: [],
        memberGrowth: [],
        topContributors: [],
        ...overrides,
    };
}

describe('deltaVsPrevious', () => {
    it('signs a change and names the window', () => {
        expect(deltaVsPrevious(3, 30)).toBe('+3 vs previous 30 days');
        expect(deltaVsPrevious(-3, 7)).toBe('-3 vs previous 7 days');
    });

    it('reads a flat or missing change as no change', () => {
        expect(deltaVsPrevious(0, 30)).toBe('No change vs previous 30 days');
        expect(deltaVsPrevious(null, 30)).toBe('No change vs previous 30 days');
    });
});

describe('percentVsPrevious', () => {
    it('signs a percentage and names the window', () => {
        expect(percentVsPrevious(12, 30)).toBe('+12% vs previous 30 days');
        expect(percentVsPrevious(-12, 30)).toBe('-12% vs previous 30 days');
    });

    it('separates a flat window from one with nothing to compare against', () => {
        expect(percentVsPrevious(0, 90)).toBe('No change vs previous 90 days');
        expect(percentVsPrevious(null, 90)).toBe('No previous activity');
    });
});

describe('toneClass', () => {
    it('tones a rise green, a fall amber and a flat line muted', () => {
        expect(toneClass(2)).toBe('text-emerald-700 dark:text-emerald-500');
        expect(toneClass(-2)).toBe('text-amber-700 dark:text-amber-500');
        expect(toneClass(0)).toBe('text-muted-foreground');
        expect(toneClass(null)).toBe('text-muted-foreground');
    });
});

describe('analyticsTiles', () => {
    it('formats every headline stat with its meta and comparison line', () => {
        expect(analyticsTiles(analytics())).toEqual([
            {
                key: 'members',
                label: 'Active members',
                value: '12',
                meta: 'of 20',
                delta: 3,
                deltaText: '+3 vs previous 30 days',
            },
            {
                key: 'perDay',
                label: 'Messages / day',
                value: '48',
                meta: 'avg',
                delta: -12,
                deltaText: '-12% vs previous 30 days',
            },
            {
                key: 'sent',
                label: 'Messages sent',
                value: '1,440',
                meta: '30 days',
                delta: null,
                deltaText: '210 in threads',
            },
            {
                key: 'channels',
                label: 'Active channels',
                value: '6',
                meta: 'of 9',
                delta: 0,
                deltaText: 'No change vs previous 30 days',
            },
        ]);
    });

    it('falls back to zero when a total or a thread count is missing', () => {
        const tiles = analyticsTiles(
            analytics({
                activeMembers: stat({ value: 4 }),
                activeChannels: stat({ value: 2 }),
                messagesSent: stat({ value: 9 }),
            }),
        );

        expect(tiles[0].meta).toBe('of 0');
        expect(tiles[2].deltaText).toBe('0 in threads');
        expect(tiles[3].meta).toBe('of 0');
    });
});

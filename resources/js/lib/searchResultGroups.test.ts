import { describe, expect, it } from 'vitest';
import { groupSearchResults } from '@/lib/searchResultGroups';
import type { MessageSearchResult } from '@/types';

const NOW = new Date('2026-07-15T12:00:00Z');

function resultAt(id: string, iso: string): MessageSearchResult {
    return {
        message: { id, createdAt: iso },
    } as unknown as MessageSearchResult;
}

describe('groupSearchResults', () => {
    it('returns no groups for no results', () => {
        expect(groupSearchResults([], NOW)).toEqual([]);
    });

    it('buckets today, yesterday, last week, and older months in order', () => {
        const groups = groupSearchResults(
            [
                resultAt('t', '2026-07-15T09:00:00Z'),
                resultAt('y', '2026-07-14T09:00:00Z'),
                resultAt('w', '2026-07-11T09:00:00Z'),
                resultAt('m', '2026-05-02T09:00:00Z'),
            ],
            NOW,
        );

        expect(groups.map((group) => group.key)).toEqual([
            'today',
            'yesterday',
            'last-week',
            'month-2026-4',
        ]);
        expect(groups[0].label).toBe('Today');
        expect(groups[1].label).toBe('Yesterday');
        expect(groups[2].label).toBe('Last week');
        expect(groups[3].label).toBe('May');
    });

    it('keeps several results of the same day in one group, in order', () => {
        const groups = groupSearchResults(
            [
                resultAt('a', '2026-07-15T11:00:00Z'),
                resultAt('b', '2026-07-15T08:00:00Z'),
            ],
            NOW,
        );

        expect(groups).toHaveLength(1);
        expect(groups[0].results.map((result) => result.message.id)).toEqual([
            'a',
            'b',
        ]);
    });

    it('labels an older-year month with its year', () => {
        const groups = groupSearchResults(
            [resultAt('old', '2025-12-02T09:00:00Z')],
            NOW,
        );

        expect(groups[0].label).toBe('December 2025');
    });
});

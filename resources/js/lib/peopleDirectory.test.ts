import { describe, expect, it } from 'vitest';
import { rankPeople } from '@/lib/peopleDirectory';
import type { PersonRef } from '@/types/people';

const members: PersonRef[] = [
    { id: 'u1', name: 'Alice Ng' },
    { id: 'u2', name: 'Bob Stone' },
    { id: 'u3', name: 'Alicia Keys' },
];

describe('rankPeople', () => {
    it('returns everyone alphabetically for an empty query', () => {
        expect(rankPeople(members, '', 'u2').map((p) => p.name)).toEqual([
            'Alice Ng',
            'Alicia Keys',
            'Bob Stone',
        ]);
    });

    it('filters and ranks by name match, best first', () => {
        expect(rankPeople(members, 'alic', 'u2').map((p) => p.id)).toEqual([
            'u1',
            'u3',
        ]);
    });

    it('matches on a word-boundary prefix in the surname', () => {
        expect(rankPeople(members, 'stone', 'u2').map((p) => p.id)).toEqual([
            'u2',
        ]);
    });

    it('returns no one when nothing matches', () => {
        expect(rankPeople(members, 'zzz', 'u2')).toEqual([]);
    });

    it('flags the viewer as self so the caller can render "You"', () => {
        const ranked = rankPeople(members, '', 'u2');

        expect(ranked.find((p) => p.id === 'u2')?.isSelf).toBe(true);
        expect(ranked.find((p) => p.id === 'u1')?.isSelf).toBe(false);
    });
});

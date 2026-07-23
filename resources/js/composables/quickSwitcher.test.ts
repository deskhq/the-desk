import { describe, expect, it } from 'vitest';
import {
    matchRange,
    rankChannels,
    rankChannelsByActivity,
    scoreChannelName,
} from '@/composables/quickSwitcher';

describe('scoreChannelName', () => {
    it('scores an empty query as a neutral match for every name', () => {
        expect(scoreChannelName('general', '')).toBe(0);
    });

    it('ranks tiers from best to worst: exact > prefix > word-boundary > substring > subsequence', () => {
        const exact = scoreChannelName('gen', 'gen')!;
        const prefix = scoreChannelName('general', 'gen')!;
        const wordBoundary = scoreChannelName('team-general', 'gen')!;
        const substring = scoreChannelName('regeneration', 'gen')!;
        const subsequence = scoreChannelName('gardening', 'gen')!;

        expect(exact).toBeGreaterThan(prefix);
        expect(prefix).toBeGreaterThan(wordBoundary);
        expect(wordBoundary).toBeGreaterThan(substring);
        expect(substring).toBeGreaterThan(subsequence);
    });

    it('is case-insensitive', () => {
        expect(scoreChannelName('General', 'GEN')).toBe(
            scoreChannelName('general', 'gen'),
        );
    });

    it('prefers shorter remainders within the prefix tier', () => {
        expect(scoreChannelName('gen', 'gen')).toBeGreaterThan(
            scoreChannelName('generalities', 'gen')!,
        );
        expect(scoreChannelName('general', 'gen')).toBeGreaterThan(
            scoreChannelName('generalities', 'gen')!,
        );
    });

    it('prefers earlier substring positions', () => {
        expect(scoreChannelName('xgen', 'gen')).toBeGreaterThan(
            scoreChannelName('xxgen', 'gen')!,
        );
    });

    it('prefers tighter subsequences', () => {
        // Both are subsequence-only matches for "gen" (no substring): the
        // characters sit closer together in "green" than in "gardening".
        expect(scoreChannelName('green', 'gen')).toBeGreaterThan(
            scoreChannelName('gardening', 'gen')!,
        );
    });

    it('returns null when the characters are out of order', () => {
        expect(scoreChannelName('general', 'neg')).toBeNull();
    });

    it('returns null when a needle character is missing', () => {
        expect(scoreChannelName('general', 'genx')).toBeNull();
    });
});

describe('rankChannels', () => {
    const channel = (name: string) => ({ name });

    it('returns every channel alphabetically for an empty query', () => {
        const result = rankChannels(
            [channel('random'), channel('general'), channel('announce')],
            '',
        );

        expect(result.map((c) => c.name)).toEqual([
            'announce',
            'general',
            'random',
        ]);
    });

    it('filters out non-matches', () => {
        const result = rankChannels(
            [channel('general'), channel('random'), channel('marketing')],
            'gen',
        );

        expect(result.map((c) => c.name)).toEqual(['general']);
    });

    it('orders matches by score, best first', () => {
        const result = rankChannels(
            [
                channel('regeneration'), // substring
                channel('gardening'), // subsequence
                channel('general'), // prefix
                channel('gen'), // exact
            ],
            'gen',
        );

        expect(result.map((c) => c.name)).toEqual([
            'gen',
            'general',
            'regeneration',
            'gardening',
        ]);
    });

    it('breaks score ties alphabetically', () => {
        const result = rankChannels(
            [channel('general-updates'), channel('general-chat')],
            'general',
        );

        expect(result.map((c) => c.name)).toEqual([
            'general-chat',
            'general-updates',
        ]);
    });

    it('ignores a leading # and surrounding whitespace', () => {
        const result = rankChannels(
            [channel('general'), channel('random')],
            '  #gen  ',
        );

        expect(result.map((c) => c.name)).toEqual(['general']);
    });
});

describe('rankChannelsByActivity', () => {
    const channel = (name: string, lastActivityAt: string | null) => ({
        name,
        lastActivityAt,
    });

    it('returns every channel most-recently-active first for an empty query', () => {
        const result = rankChannelsByActivity(
            [
                channel('announce', '2026-07-20T10:00:00Z'),
                channel('random', '2026-07-22T10:00:00Z'),
                channel('general', '2026-07-21T10:00:00Z'),
            ],
            '',
        );

        expect(result.map((c) => c.name)).toEqual([
            'random',
            'general',
            'announce',
        ]);
    });

    it('sorts channels without any activity after dated ones, alphabetically', () => {
        const result = rankChannelsByActivity(
            [
                channel('zebra', null),
                channel('apple', null),
                channel('general', '2026-07-01T10:00:00Z'),
            ],
            '',
        );

        expect(result.map((c) => c.name)).toEqual([
            'general',
            'apple',
            'zebra',
        ]);
    });

    it('ranks a better name match above a more recently active one', () => {
        const result = rankChannelsByActivity(
            [
                channel('regeneration', '2026-07-22T10:00:00Z'),
                channel('general', '2026-07-01T10:00:00Z'),
            ],
            'gen',
        );

        expect(result.map((c) => c.name)).toEqual(['general', 'regeneration']);
    });

    it('breaks score ties by recency instead of alphabetically', () => {
        const result = rankChannelsByActivity(
            [
                channel('general-chat', '2026-07-01T10:00:00Z'),
                channel('general-docs', '2026-07-22T10:00:00Z'),
            ],
            'general-',
        );

        expect(result.map((c) => c.name)).toEqual([
            'general-docs',
            'general-chat',
        ]);
    });

    it('filters out non-matches', () => {
        const result = rankChannelsByActivity(
            [
                channel('general', null),
                channel('marketing', '2026-07-22T10:00:00Z'),
            ],
            'gen',
        );

        expect(result.map((c) => c.name)).toEqual(['general']);
    });
});

describe('matchRange', () => {
    it('finds the first case-insensitive occurrence of the query', () => {
        expect(matchRange('Priya Desai', 'des')).toEqual({
            start: 6,
            length: 3,
        });
    });

    it('matches at the start of the name', () => {
        expect(matchRange('design', 'des')).toEqual({ start: 0, length: 3 });
    });

    it('returns null for an empty query', () => {
        expect(matchRange('design', '')).toBeNull();
    });

    it('returns null when the query is only a subsequence, not a substring', () => {
        expect(matchRange('gardening', 'gen')).toBeNull();
    });

    it('ignores a leading # and surrounding whitespace, like the ranking does', () => {
        expect(matchRange('design', ' #des ')).toEqual({
            start: 0,
            length: 3,
        });
    });
});

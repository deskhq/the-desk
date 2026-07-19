import { describe, expect, it } from 'vitest';
import { applyVote, hasVoted, optionShare } from '@/lib/polls';
import type { Mention, Poll, PollOption } from '@/types';

const ME: Mention = { id: 'me', name: 'Me' };
const ALICE: Mention = { id: 'a', name: 'Alice' };

/** A public option with the given voters (roster present). */
function publicOption(id: string, voters: Mention[]): PollOption {
    return {
        id,
        label: id.toUpperCase(),
        position: 0,
        voteCount: voters.length,
        voters,
        votedByViewer: voters.some((voter) => voter.id === ME.id),
    };
}

/** An anonymous option (no roster) with a count and the viewer's own flag. */
function anonOption(
    id: string,
    voteCount: number,
    votedByViewer: boolean,
): PollOption {
    return {
        id,
        label: id.toUpperCase(),
        position: 0,
        voteCount,
        voters: null,
        votedByViewer,
    };
}

function poll(options: PollOption[], overrides: Partial<Poll> = {}): Poll {
    const totalVotes = options.reduce(
        (sum, option) => sum + option.voteCount,
        0,
    );

    return {
        id: 'p',
        question: 'Q?',
        allowMultiple: false,
        isAnonymous: options.every((option) => option.voters === null),
        closedAt: null,
        options,
        totalVotes,
        voterCount: totalVotes,
        ...overrides,
    };
}

describe('hasVoted', () => {
    it('reads the roster for a public option', () => {
        expect(hasVoted(publicOption('a', [ALICE, ME]), ME.id)).toBe(true);
        expect(hasVoted(publicOption('a', [ALICE]), ME.id)).toBe(false);
    });

    it('reads votedByViewer for an anonymous option', () => {
        expect(hasVoted(anonOption('a', 3, true), ME.id)).toBe(true);
        expect(hasVoted(anonOption('a', 3, false), ME.id)).toBe(false);
    });
});

describe('optionShare', () => {
    it('is the percent of voters who picked the option', () => {
        const p = poll([publicOption('a', [ALICE]), publicOption('b', [ME])], {
            voterCount: 4,
        });

        expect(optionShare(p.options[0], p)).toBe(25);
    });

    it('is zero when nobody has voted', () => {
        const p = poll([publicOption('a', []), publicOption('b', [])]);

        expect(optionShare(p.options[0], p)).toBe(0);
    });
});

describe('applyVote (single choice)', () => {
    it('adds the first vote and bumps the totals', () => {
        const p = poll([publicOption('a', []), publicOption('b', [])]);
        const next = applyVote(p, 'a', ME);

        expect(next.options[0].voteCount).toBe(1);
        expect(next.options[0].voters).toEqual([ME]);
        expect(next.options[0].votedByViewer).toBe(true);
        expect(next.totalVotes).toBe(1);
        expect(next.voterCount).toBe(1);
    });

    it('swaps the vote to another option without changing the voter count', () => {
        const p = poll([publicOption('a', [ME]), publicOption('b', [])]);
        const next = applyVote(p, 'b', ME);

        expect(hasVoted(next.options[0], ME.id)).toBe(false);
        expect(hasVoted(next.options[1], ME.id)).toBe(true);
        expect(next.totalVotes).toBe(1);
        expect(next.voterCount).toBe(1);
    });

    it('retracts the vote when re-clicking the chosen option', () => {
        const p = poll([publicOption('a', [ME]), publicOption('b', [])]);
        const next = applyVote(p, 'a', ME);

        expect(hasVoted(next.options[0], ME.id)).toBe(false);
        expect(next.totalVotes).toBe(0);
        expect(next.voterCount).toBe(0);
    });
});

describe('applyVote (multiple choice)', () => {
    it('toggles each option independently, keeping the voter counted once', () => {
        const base = poll(
            [
                publicOption('a', [ME]),
                publicOption('b', []),
                publicOption('c', []),
            ],
            { allowMultiple: true, voterCount: 1 },
        );

        const next = applyVote(base, 'b', ME);

        expect(hasVoted(next.options[0], ME.id)).toBe(true);
        expect(hasVoted(next.options[1], ME.id)).toBe(true);
        expect(next.totalVotes).toBe(2);
        expect(next.voterCount).toBe(1);
    });
});

describe('applyVote (anonymous)', () => {
    it('toggles the viewer flag without exposing a roster', () => {
        const p = poll([anonOption('a', 2, false), anonOption('b', 1, false)]);
        const next = applyVote(p, 'a', ME);

        expect(next.options[0].votedByViewer).toBe(true);
        expect(next.options[0].voteCount).toBe(3);
        expect(next.options[0].voters).toBeNull();
        expect(next.voterCount).toBe(p.voterCount + 1);
    });
});

describe('applyVote (guard)', () => {
    it('returns the poll unchanged for an unknown option id', () => {
        const p = poll([publicOption('a', [])]);

        expect(applyVote(p, 'missing', ME)).toBe(p);
    });
});

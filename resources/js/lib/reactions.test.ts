import { describe, expect, it } from 'vitest';
import { hasReacted, reactionRoster, toggleReaction } from '@/lib/reactions';
import type { Mention, Reaction } from '@/types';

const ME: Mention = { id: 'me', name: 'Me' };
const ALICE: Mention = { id: 'a', name: 'Alice' };
const BOB: Mention = { id: 'b', name: 'Bob' };
const CAROL: Mention = { id: 'c', name: 'Carol' };

/**
 * A reaction entry for `emoji` reacted to by the given members, in order.
 */
function reaction(emoji: string, reactors: Mention[]): Reaction {
    return { emoji, count: reactors.length, reactors };
}

describe('hasReacted', () => {
    it('is true when the user is among the reactors', () => {
        expect(hasReacted(reaction('👍', [ALICE, ME]), ME.id)).toBe(true);
    });

    it('is false when the user is not among the reactors', () => {
        expect(hasReacted(reaction('👍', [ALICE]), ME.id)).toBe(false);
    });
});

describe('toggleReaction', () => {
    it('adds a brand-new emoji to the end', () => {
        const result = toggleReaction([reaction('👍', [ALICE])], '🎉', ME);

        expect(result).toEqual([reaction('👍', [ALICE]), reaction('🎉', [ME])]);
    });

    it('appends the user to an existing emoji they had not used', () => {
        const result = toggleReaction([reaction('👍', [ALICE])], '👍', ME);

        expect(result).toEqual([reaction('👍', [ALICE, ME])]);
    });

    it('removes the user from an emoji they already used', () => {
        const result = toggleReaction([reaction('👍', [ALICE, ME])], '👍', ME);

        expect(result).toEqual([reaction('👍', [ALICE])]);
    });

    it('drops the entry entirely when the last reactor toggles off', () => {
        const result = toggleReaction([reaction('👍', [ME])], '👍', ME);

        expect(result).toEqual([]);
    });

    it('does not mutate the input array or its entries', () => {
        const input = [reaction('👍', [ALICE])];
        const snapshot = structuredClone(input);

        toggleReaction(input, '👍', ME);

        expect(input).toEqual(snapshot);
    });
});

describe('reactionRoster', () => {
    it('names a single other reactor', () => {
        expect(reactionRoster(reaction('👍', [ALICE]), ME.id)).toBe('Alice');
    });

    it('surfaces the viewer as "You"', () => {
        expect(reactionRoster(reaction('👍', [ME]), ME.id)).toBe('You');
    });

    it('puts "You" first when the viewer reacted alongside others', () => {
        expect(reactionRoster(reaction('👍', [ALICE, ME]), ME.id)).toBe(
            'You and Alice',
        );
    });

    it('joins two names with "and"', () => {
        expect(reactionRoster(reaction('👍', [ALICE, BOB]), ME.id)).toBe(
            'Alice and Bob',
        );
    });

    it('names every reactor, without collapsing beyond two', () => {
        expect(reactionRoster(reaction('👍', [ALICE, BOB, CAROL]), ME.id)).toBe(
            'Alice, Bob and Carol',
        );
    });

    it('keeps "You" first across a longer full roster', () => {
        expect(
            reactionRoster(reaction('👍', [ALICE, BOB, CAROL, ME]), ME.id),
        ).toBe('You, Alice, Bob and Carol');
    });
});

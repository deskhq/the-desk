import type { Mention, Reaction } from '@/types';

/**
 * Whether the given user is among a reaction's reactors — i.e. the viewer has
 * this emoji reaction on the message. The reaction summary is viewer-free, so
 * "did I react" is derived here rather than sent from the server.
 */
export function hasReacted(reaction: Reaction, userId: string): boolean {
    return reaction.reactors.some((reactor) => reactor.id === userId);
}

/**
 * Apply an optimistic toggle of `user`'s reaction with `emoji` to a message's
 * reaction summary, returning a new array (the input is never mutated).
 *
 * Mirrors the server's toggle: if the user already reacts with this emoji they
 * are removed — dropping the whole entry when they were the last reactor —
 * otherwise they are appended. A brand-new emoji is added to the end, keeping
 * the first-reacted-first order the server also produces; the broadcast echo
 * later replaces this optimistic copy with the authoritative one.
 */
export function toggleReaction(
    reactions: Reaction[],
    emoji: string,
    user: Mention,
): Reaction[] {
    const existing = reactions.find((reaction) => reaction.emoji === emoji);

    if (!existing) {
        return [...reactions, { emoji, count: 1, reactors: [user] }];
    }

    if (hasReacted(existing, user.id)) {
        const reactors = existing.reactors.filter(
            (reactor) => reactor.id !== user.id,
        );

        if (reactors.length === 0) {
            return reactions.filter((reaction) => reaction.emoji !== emoji);
        }

        return reactions.map((reaction) =>
            reaction.emoji === emoji
                ? { ...reaction, count: reactors.length, reactors }
                : reaction,
        );
    }

    const reactors = [...existing.reactors, user];

    return reactions.map((reaction) =>
        reaction.emoji === emoji
            ? { ...reaction, count: reactors.length, reactors }
            : reaction,
    );
}

/**
 * The full, human-readable roster of who reacted with an emoji, for the pill's
 * hover card, with the viewer surfaced as "You" first: "You", "You and Alice",
 * "You, Alice and Bob", "Alice", "Alice and Bob", "Alice, Bob and Carol".
 *
 * Every reactor is named (the card exists to show exactly who reacted), so —
 * unlike a compact tooltip — no names are collapsed into an "N others" tail.
 */
export function reactionRoster(reaction: Reaction, userId: string): string {
    const names = reaction.reactors.map((reactor) =>
        reactor.id === userId ? 'You' : reactor.name,
    );

    // Keep "You" first so the viewer always reads as the leading reactor.
    names.sort((a, b) => (a === 'You' ? -1 : b === 'You' ? 1 : 0));

    if (names.length === 1) {
        return names[0];
    }

    if (names.length === 2) {
        return `${names[0]} and ${names[1]}`;
    }

    const head = names.slice(0, -1).join(', ');

    return `${head} and ${names[names.length - 1]}`;
}

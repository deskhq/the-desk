import type { Mention, Poll, PollOption } from '@/types';

/**
 * Whether the viewer has voted for the given option. A public poll derives it
 * from the roster (viewer-free, like a reaction's reactors); an anonymous poll
 * has no roster, so its own per-option `votedByViewer` flag carries the answer.
 */
export function hasVoted(option: PollOption, userId: string): boolean {
    return option.voters
        ? option.voters.some((voter) => voter.id === userId)
        : option.votedByViewer;
}

/**
 * The share of voters who picked this option, as a whole-number percent (0 when
 * nobody has voted). The denominator is the distinct voter count, so a
 * multiple-choice option reads as "the fraction of people who picked it".
 */
export function optionShare(option: PollOption, poll: Poll): number {
    if (poll.voterCount === 0) {
        return 0;
    }

    return Math.round((option.voteCount / poll.voterCount) * 100);
}

/**
 * Apply an optimistic toggle of `user`'s vote for one option, returning a new
 * poll (the input is never mutated) — the pure counterpart of the CastVote
 * action, so the card updates instantly before the broadcast echo replaces it
 * with the authoritative tally.
 *
 * Mirrors the server: re-clicking the chosen option retracts it; a single-choice
 * poll swaps (the viewer's other pick is cleared); a multiple-choice poll toggles
 * each option independently. Counts, the public roster, the viewer's own
 * selection, and both totals are kept consistent.
 */
export function applyVote(poll: Poll, optionId: string, user: Mention): Poll {
    const target = poll.options.find((option) => option.id === optionId);

    if (!target) {
        return poll;
    }

    const setVote = (option: PollOption, voted: boolean): PollOption => {
        if (hasVoted(option, user.id) === voted) {
            return option;
        }

        return {
            ...option,
            voteCount: option.voteCount + (voted ? 1 : -1),
            votedByViewer: voted,
            voters: option.voters
                ? voted
                    ? [...option.voters, user]
                    : option.voters.filter((voter) => voter.id !== user.id)
                : option.voters,
        };
    };

    const hadAnyVote = poll.options.some((option) => hasVoted(option, user.id));
    const currentlyVoted = hasVoted(target, user.id);

    let options: PollOption[];

    if (currentlyVoted || poll.allowMultiple) {
        // Retract the chosen option (if already voted) or toggle it on for a
        // multiple-choice poll, leaving every other option untouched.
        options = poll.options.map((option) =>
            option.id === optionId ? setVote(option, !currentlyVoted) : option,
        );
    } else {
        // Single choice: this option becomes the sole pick, clearing the rest.
        options = poll.options.map((option) =>
            setVote(option, option.id === optionId),
        );
    }

    const hasAnyVote = options.some((option) => hasVoted(option, user.id));

    return {
        ...poll,
        options,
        totalVotes: options.reduce((sum, option) => sum + option.voteCount, 0),
        voterCount:
            poll.voterCount + (hasAnyVote ? 1 : 0) - (hadAnyVote ? 1 : 0),
    };
}

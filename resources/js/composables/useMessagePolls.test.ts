import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post, toastError } = vi.hoisted(() => ({
    post: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { post, patch: vi.fn(), delete: vi.fn() },
}));
vi.mock('@/composables/useToast', () => {
    const toast = {
        error: toastError,
        success: vi.fn(),
        warning: vi.fn(),
        progress: vi.fn(),
    };

    return { useToast: () => toast };
});

import { shown, streams } from '@/composables/messageWrites.harness';
import { channel, me, message } from '@/composables/useMessageActions.harness';
import { useMessagePolls } from '@/composables/useMessagePolls';
import type { Poll } from '@/types';

function poll(): Poll {
    return {
        id: 'poll-1',
        question: 'Lunch?',
        allowMultiple: false,
        isAnonymous: false,
        closedAt: null,
        totalVotes: 0,
        voterCount: 0,
        options: [
            {
                id: 'o1',
                label: 'Ramen',
                position: 0,
                voteCount: 0,
                voters: [],
                votedByViewer: false,
            },
            {
                id: 'o2',
                label: 'Tacos',
                position: 1,
                voteCount: 0,
                voters: [],
                votedByViewer: false,
            },
        ],
    };
}

function harness() {
    const row = message({ poll: poll() });
    const { mainStream, threadStream, unmount } = streams([row]);

    const polls = useMessagePolls({
        teamSlug: () => 'acme',
        channel: () => channel,
        currentUser: () => me,
        mainStream,
        threadStream,
    });

    return { polls, row, mainStream, threadStream, unmount };
}

/** The viewer's standing on the first option, as a stream currently shows it. */
function firstOption(
    stream: ReturnType<typeof streams>['mainStream'],
    id: string,
) {
    return shown(stream, id).poll?.options[0];
}

describe('useMessagePolls', () => {
    beforeEach(() => {
        post.mockClear();
        toastError.mockClear();
    });

    it('counts the vote in both streams before the server has agreed', () => {
        const { polls, row, mainStream, threadStream, unmount } = harness();

        polls.voteOnPoll(row, 'o1');

        expect(firstOption(mainStream, row.id)?.votedByViewer).toBe(true);
        expect(firstOption(threadStream, row.id)?.votedByViewer).toBe(true);

        unmount();
    });

    it('takes the vote back out of both streams when the write is refused', () => {
        const { polls, row, mainStream, threadStream, unmount } = harness();

        polls.voteOnPoll(row, 'o1');
        post.mock.calls[0][2].onError({});

        expect(firstOption(mainStream, row.id)?.votedByViewer).toBe(false);
        expect(firstOption(mainStream, row.id)?.voteCount).toBe(0);
        expect(firstOption(threadStream, row.id)?.votedByViewer).toBe(false);
        expect(firstOption(threadStream, row.id)?.voteCount).toBe(0);
        expect(toastError).toHaveBeenCalledWith('Failed to record your vote');

        unmount();
    });

    it('ignores a vote on a message carrying no poll', () => {
        const row = message();
        const { mainStream, threadStream, unmount } = streams([row]);

        useMessagePolls({
            teamSlug: () => 'acme',
            channel: () => channel,
            currentUser: () => me,
            mainStream,
            threadStream,
        }).voteOnPoll(row, 'o1');

        expect(post).not.toHaveBeenCalled();

        unmount();
    });

    it('says closing the poll failed, having shown nothing to take back', () => {
        // Closing is not optimistic: the frozen tally arrives over the
        // broadcast, so there is no local patch a refusal would have to undo.
        const { polls, row, mainStream, unmount } = harness();

        polls.closePoll(row);
        post.mock.calls[0][2].onError({});

        expect(shown(mainStream, row.id).poll?.closedAt).toBeNull();
        expect(toastError).toHaveBeenCalledWith('Failed to close the poll');

        unmount();
    });
});

import { router } from '@inertiajs/vue3';
import {
    close as closePollAction,
    vote as voteOnPollAction,
} from '@/actions/App/Http/Controllers/Channels/PollController';
import type { MessageActionsOptions } from '@/composables/useMessageActions';
import {
    snapshotStreams,
    useOptimisticWrite,
} from '@/composables/useOptimisticWrite';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import { applyVote } from '@/lib/polls';
import { CHANNEL_LIST_PROPS } from '@/lib/reloadProps';
import type { Message } from '@/types';

export interface MessagePolls {
    /** Toggle the viewer's vote for a poll option, optimistically, rolled back on error. */
    voteOnPoll: (message: Message, optionId: string) => void;
    /** Close a poll, freezing its tally; the frozen state arrives over the broadcast. */
    closePoll: (message: Message) => void;
}

export type MessagePollsOptions = Pick<
    MessageActionsOptions,
    'teamSlug' | 'channel' | 'currentUser' | 'mainStream' | 'threadStream'
>;

/** The two writes a poll message takes: a vote on it, and closing it. */
export function useMessagePolls(options: MessagePollsOptions): MessagePolls {
    const { t } = useTranslations();
    const toast = useToast();
    const { write } = useOptimisticWrite();

    /**
     * Toggle the viewer's vote for a poll option. The tally is applied
     * optimistically to both streams (via the pure {@see applyVote}) and rolled
     * back on error; the authoritative tally reaches every client — including this
     * one — over the `PollVoteChanged` broadcast. A no-op when the message carries
     * no poll.
     */
    function voteOnPoll(message: Message, optionId: string): void {
        if (message.poll === null) {
            return;
        }

        const channel = options.channel();
        const next = applyVote(message.poll, optionId, options.currentUser());

        write({
            capture: () =>
                snapshotStreams(
                    message.clientUuid,
                    options.mainStream,
                    options.threadStream,
                ),
            apply: () => {
                options.mainStream.patchThreadState(message.id, { poll: next });
                options.threadStream.patchThreadState(message.id, {
                    poll: next,
                });
            },
            url: voteOnPollAction({
                team: options.teamSlug(),
                channel: channel.slug,
                poll: message.poll.id,
            }).url,
            data: { option_id: optionId },
            only: CHANNEL_LIST_PROPS,
            failure: t('Failed to record your vote'),
        });
    }

    /**
     * Close a poll, freezing its tally. Non-optimistic: the frozen state reaches
     * every client — including this one — over the `PollVoteChanged` broadcast, so
     * nothing is patched locally up front. With nothing shown ahead of the
     * server there is nothing to roll back, which is why this one stays a plain
     * post rather than joining {@see useOptimisticWrite}. A no-op when the
     * message carries no poll.
     */
    function closePoll(message: Message): void {
        if (message.poll === null) {
            return;
        }

        const channel = options.channel();

        router.post(
            closePollAction({
                team: options.teamSlug(),
                channel: channel.slug,
                poll: message.poll.id,
            }).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: CHANNEL_LIST_PROPS,
                onError: () => {
                    toast.error(t('Failed to close the poll'));
                },
            },
        );
    }

    return { voteOnPoll, closePoll };
}

import { nextTick } from 'vue';
import type { Ref } from 'vue';
import { useMessageEdits } from '@/composables/useMessageEdits';
import type { MessageEdits } from '@/composables/useMessageEdits';
import { useMessageForwarding } from '@/composables/useMessageForwarding';
import type { MessageForwarding } from '@/composables/useMessageForwarding';
import { useMessagePins } from '@/composables/useMessagePins';
import type { MessagePins } from '@/composables/useMessagePins';
import { useMessagePolls } from '@/composables/useMessagePolls';
import type { MessagePolls } from '@/composables/useMessagePolls';
import { useMessageSends } from '@/composables/useMessageSends';
import type { MessageSends } from '@/composables/useMessageSends';
import type { useMessageStream } from '@/composables/useMessageStream';
import { useReminderActions } from '@/composables/useReminderActions';
import type { ReminderActions } from '@/composables/useReminderActions';
import { useScheduledMessages } from '@/composables/useScheduledMessages';
import type { ScheduledMessages } from '@/composables/useScheduledMessages';
import { useThreadReplies } from '@/composables/useThreadReplies';
import type { ThreadReplies } from '@/composables/useThreadReplies';
import type { Outbox } from '@/lib/outbox';
import type { Channel, Mention, Message } from '@/types';

export { QUEUED_SENDS_TOAST_KEY } from '@/composables/useMessageSends';
export type {
    CommandCallbacks,
    SendCallbacks,
} from '@/composables/useMessageSends';

type MessageStream = ReturnType<typeof useMessageStream>;

/** The subset of the open channel the message actions route and quote against. */
type ActionChannel = Pick<Channel, 'id' | 'slug' | 'name' | 'isDirect'>;

export interface MessageActionsOptions {
    /** The current team's slug, for every message route. */
    teamSlug: () => string;
    /** The open channel; re-read per call so a channel switch routes correctly. */
    channel: () => ActionChannel;
    /** The current viewer, stamped as the author of optimistic rows. */
    currentUser: () => Mention;
    /** The main channel timeline stream. */
    mainStream: MessageStream;
    /** The open thread panel's stream. */
    threadStream: MessageStream;
    /** The root id of the thread currently open in the panel, or `null`. */
    activeThreadRootId: Ref<string | null>;
    /** The message the composer is quoting, or `null` for a normal send. */
    replyTarget: Ref<Message | null>;
    /** Whether the viewer is scrolled near the bottom of the main timeline. */
    isNearBottom: () => boolean;
    /** Smooth-scroll the main timeline to the newest message. */
    scrollToBottom: () => void;
    /** Drop a pending draft save (a send/schedule clears the draft server-side). */
    cancelDraft: () => void;
    /**
     * Immediately clear the saved draft. A queued (offline) send never reaches the
     * store endpoint that normally clears it, so without this a refresh would
     * repopulate the composer with the already-queued text.
     */
    clearDraft: () => void;
    /** Clear the composer's reply quote. */
    cancelReply: () => void;
    /** Whether the realtime socket is up; a send while offline queues instead. */
    isOnline: () => boolean;
    /** The offline queue holding channel sends until the connection recovers. */
    outbox: Outbox;
    /**
     * Announce a send failure to assistive technology. A rolled-back optimistic
     * row vanishes silently, so this surfaces the same message a toast shows to a
     * screen reader via a polite live region.
     */
    onSendFailure?: (message: string) => void;
}

/**
 * Every write the channel makes on the viewer's behalf, as one object. Each
 * group is declared alongside the composable that implements it, so the
 * contract and its implementation cannot drift apart.
 */
export interface MessageActions
    extends
        MessageSends,
        MessageEdits,
        MessagePolls,
        MessagePins,
        MessageForwarding,
        ThreadReplies,
        ScheduledMessages,
        ReminderActions {}

/**
 * Own the channel's optimistic-mutation engine: every message action follows the
 * same shape — capture the previous state, apply optimistically, fire the router
 * call, then roll back and toast on failure. Concentrating the eight-plus call
 * sites behind one seam keeps the optimistic/rollback contract in a single,
 * unit-testable module rather than scattered through `Show.vue`'s setup block.
 *
 * This is the composition root for that engine, not its implementation: the
 * actions live in one composable per group they belong to ({@see useMessageSends},
 * {@see useMessageEdits}, {@see useMessagePolls}, {@see useMessagePins},
 * {@see useMessageForwarding}, {@see useThreadReplies},
 * {@see useScheduledMessages}, {@see useReminderActions}), and what is left here
 * is the contract they share and the one piece of plumbing more than one of them
 * needs. Realtime echoes re-apply the same patches via {@see useChannelRealtime},
 * so a rolled-back optimistic copy and its later broadcast stay consistent.
 */
export function useMessageActions(
    options: MessageActionsOptions,
): MessageActions {
    /** Add an optimistic row to the main timeline, honouring the pin-to-bottom rule. */
    function appendPendingMain(message: Message): void {
        const pinned = options.isNearBottom();
        options.mainStream.addPending(message);

        if (pinned) {
            nextTick(() => options.scrollToBottom());
        }
    }

    return {
        ...useMessageSends(options),
        ...useMessageEdits(options),
        ...useMessagePolls(options),
        ...useMessagePins(options),
        ...useMessageForwarding({ ...options, appendPendingMain }),
        ...useThreadReplies({ ...options, appendPendingMain }),
        ...useScheduledMessages(options),
        ...useReminderActions(options),
    };
}

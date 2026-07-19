import { echo } from '@laravel/echo-vue';
import type { Ref } from 'vue';
import { onBeforeUnmount, onMounted, watch } from 'vue';
import type { useMessageStream } from '@/composables/useMessageStream';
import type {
    TypingUser,
    useTypingIndicator,
} from '@/composables/useTypingIndicator';
import { createChannelFleet } from '@/lib/channelFleet';
import { placeIncomingMessage } from '@/lib/messagePlacement';
import type {
    ChannelReader,
    Mention,
    Message,
    MessageAuthor,
    Poll,
    Reaction,
} from '@/types';

type MessageStream = ReturnType<typeof useMessageStream>;
type TypingIndicator = ReturnType<typeof useTypingIndicator>;

export interface ChannelRealtimeOptions {
    /** The open channel's id; re-read on change to move the subscription. */
    channelId: () => string;
    /** The current viewer's id, to drop own read-echoes and own-reply dots. */
    currentUserId: () => string;
    /** The main channel timeline stream. */
    mainStream: MessageStream;
    /** The open thread panel's stream. */
    threadStream: MessageStream;
    /** The root id of the thread currently open in the panel, or `null`. */
    activeThreadRootId: Ref<string | null>;
    /** The main timeline's rendered messages, to resolve a root's follow state. */
    displayMessages: () => Message[];
    /** Whether thread unread dots are silenced for this channel. */
    isThreadUnreadSuppressed: () => boolean;
    /** The channel's read-receipt map, advanced by peers' `MessageRead`. */
    readers: Ref<Map<string, ChannelReader>>;
    /** Whether the viewer is scrolled near the bottom of the main timeline. */
    isNearBottom: () => boolean;
    /** Follow a live append down, or raise the "new messages" count instead. */
    notifyAppended: (wasNearBottom: boolean) => void;
    /** The channel's typing indicator, for whisper receipt and settle-on-send. */
    typing: TypingIndicator;
    /** Advance the channel's read pointer (a message landed while focused). */
    markRead: () => void;
    /** Advance the open thread's read pointer. */
    markThreadRead: () => void;
    /** Update the channel-level pin count driving the masthead badge. */
    updatePinCount: (count: number) => void;
}

/** The Echo private-channel name a channel id broadcasts on. */
function channelName(id: string): string {
    return `channel.${id}`;
}

/**
 * Own the *active* channel's realtime lifecycle: subscribe to its Echo channel,
 * route the five broadcast events plus typing whispers into the two message
 * streams, and move the subscription when the open channel changes.
 *
 * The subscribe/leave bookkeeping rides {@see createChannelFleet} — the same
 * engine behind {@see useChannelFleetSubscription} — driven as a single-element
 * fleet: each reconcile passes `null` as the active id (so the fleet's
 * active-channel handoff never engages) and the sole desired id, which leaves the
 * channel just navigated away from and subscribes the new one. The placement of
 * each arriving message is decided by the pure {@see placeIncomingMessage}, so
 * this composable stays a thin bridge between Echo and the streams.
 */
export function useChannelRealtime(options: ChannelRealtimeOptions): void {
    /**
     * An edit or deletion may touch either timeline (or both, for a
     * sent-to-channel reply); patch both streams, since a patch is ignored where
     * the message isn't rendered.
     */
    function applyPatch(message: Message): void {
        options.mainStream.applyPatch(message);
        options.threadStream.applyPatch(message);
    }

    /**
     * Route a broadcast message to the timeline it belongs to, following the main
     * timeline down when the reader is near the bottom and reconciling the open
     * thread's read state — all decided by `placeIncomingMessage`.
     */
    function routeIncoming(message: Message): void {
        const root = options
            .displayMessages()
            .find((candidate) => candidate.id === message.threadRootId);

        const placement = placeIncomingMessage({
            threadRootId: message.threadRootId,
            sentToChannel: message.sentToChannel,
            isOwnMessage: message.user.id === options.currentUserId(),
            activeThreadRootId: options.activeThreadRootId.value,
            isTabFocused: document.hasFocus(),
            isFollowedThread: root?.threadFollowed ?? false,
            isThreadUnreadSuppressed: options.isThreadUnreadSuppressed(),
        });

        if (placement.appendToMain) {
            const wasNearBottom = options.isNearBottom();

            if (options.mainStream.appendLive(message)) {
                options.notifyAppended(wasNearBottom);
            }
        }

        if (placement.appendToThread) {
            options.threadStream.appendLive(message);
        }

        if (placement.markThreadReadNow) {
            options.markThreadRead();
        }

        if (placement.flagRootThreadUnread && message.threadRootId !== null) {
            options.mainStream.patchThreadState(message.threadRootId, {
                threadUnread: true,
            });
        }
    }

    const fleet = createChannelFleet({
        subscribe(id) {
            echo()
                .private(channelName(id))
                .listen('MessageSent', (message: Message) => {
                    // Their message landed; stop showing them as typing.
                    options.typing.forget(message.user.id);
                    routeIncoming(message);
                    // Keep the open, focused channel read as new messages arrive.
                    options.markRead();
                })
                .listen('MessageUpdated', (message: Message) => {
                    applyPatch(message);
                })
                .listen('MessageDeleted', (message: Message) => {
                    applyPatch(message);
                })
                .listen(
                    'MessageReactionChanged',
                    (event: { messageId: string; reactions: Reaction[] }) => {
                        // The authoritative, viewer-free summary; patch it into
                        // whichever timeline renders the message (no-op elsewhere).
                        options.mainStream.patchReactions(
                            event.messageId,
                            event.reactions,
                        );
                        options.threadStream.patchReactions(
                            event.messageId,
                            event.reactions,
                        );
                    },
                )
                .listen(
                    'PollVoteChanged',
                    (event: { messageId: string; poll: Poll }) => {
                        // The authoritative, viewer-free tally; patch it into
                        // whichever timeline renders the poll (no-op elsewhere).
                        options.mainStream.patchPoll(
                            event.messageId,
                            event.poll,
                        );
                        options.threadStream.patchPoll(
                            event.messageId,
                            event.poll,
                        );
                    },
                )
                .listen(
                    'MessagePinned',
                    (event: {
                        messageId: string;
                        pinned: boolean;
                        pinnedBy: Mention | null;
                        pinCount: number;
                    }) => {
                        // Raise or drop the "Pinned by" indicator on whichever
                        // timeline renders the message (no-op elsewhere). The
                        // broadcast omits the pin timestamp — the indicator shows
                        // only the pinner — so approximate it with the receive
                        // time; the pins panel reads its own server prop.
                        const pin =
                            event.pinned && event.pinnedBy !== null
                                ? {
                                      pinnedBy: event.pinnedBy,
                                      pinnedAt: new Date().toISOString(),
                                  }
                                : null;
                        options.mainStream.patchPin(event.messageId, pin);
                        options.threadStream.patchPin(event.messageId, pin);
                        // Keep the masthead's count badge current for everyone.
                        options.updatePinCount(event.pinCount);
                    },
                )
                .listen(
                    'MessageRead',
                    (event: {
                        reader: MessageAuthor;
                        lastReadMessageId: string;
                    }) => {
                        // Our own advance echoes back on the shared private
                        // channel; the "Seen by" row never shows the viewer, so
                        // drop it here.
                        if (event.reader.id === options.currentUserId()) {
                            return;
                        }

                        const next = new Map(options.readers.value);
                        next.set(event.reader.id, {
                            user: event.reader,
                            lastReadMessageId: event.lastReadMessageId,
                        });
                        options.readers.value = next;
                    },
                )
                .listenForWhisper('typing', (user: TypingUser) => {
                    options.typing.receiveTyping(user);
                });
        },
        leave(id) {
            echo().leave(channelName(id));
        },
    });

    /**
     * Drive the fleet as a single-channel subscription: `null` active id keeps the
     * handoff dormant, so each reconcile leaves the previous channel and
     * subscribes the current one.
     */
    function moveSubscription(): void {
        fleet.reconcile([options.channelId()], null);
    }

    onMounted(moveSubscription);
    watch(options.channelId, moveSubscription);
    onBeforeUnmount(() => {
        fleet.leaveAll();
    });
}

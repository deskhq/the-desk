import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { useConnectionState } from '@/composables/useConnectionState';
import type { ConnectionState } from '@/composables/useConnectionState';
import { QUEUED_SENDS_TOAST_KEY } from '@/composables/useMessageActions';
import { optimisticMessage } from '@/composables/useMessageStream';
import type { useMessageStream } from '@/composables/useMessageStream';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import { backgroundVisit } from '@/lib/backgroundVisit';
import { createOutbox } from '@/lib/outbox';
import type { Outbox } from '@/lib/outbox';
import type { Mention } from '@/types';

type MessageStream = ReturnType<typeof useMessageStream>;

export interface OfflineOutboxOptions {
    /** Keys the persisted queue, so each channel keeps its own. */
    channelId: () => string;
    /** The sender, so a rehydrated row renders under their name. */
    currentUser: () => Mention;
    /** The channel timeline the optimistic rows are re-added to. */
    mainStream: MessageStream;
}

export interface OfflineOutbox {
    /** The realtime connection cue (reconnecting / back-online pill). */
    connection: ConnectionState;
    outbox: Outbox;
    /** Client uuids currently queued, so the timeline can mark each row. */
    queuedUuids: ComputedRef<string[]>;
    /** Drop the whole queue: the optimistic rows and the stored sends. */
    discardQueue: () => void;
    /**
     * Wire the queue to the socket. Taken as a step of its own because the flush
     * belongs to the actions engine, which is itself built over this queue.
     */
    flushOnReconnect: (flushOutbox: () => Promise<number>) => void;
}

/**
 * The offline outbox: sends made while the socket is down queue here and flush
 * on recovery. The queue persists per channel, so a refresh while offline keeps
 * it.
 */
export function useOfflineOutbox(options: OfflineOutboxOptions): OfflineOutbox {
    const { t } = useTranslations();
    const toast = useToast();

    const connection = useConnectionState();
    const outbox = createOutbox({
        storageKey: `outbox:${options.channelId()}`,
    });

    // A queue rehydrated from a previous session has no optimistic rows yet; re-add
    // them so the queued sends still render in the timeline after a refresh. The
    // reply quote isn't persisted, so a rehydrated row shows its body without it.
    for (const item of outbox.items.value) {
        options.mainStream.addPending(
            optimisticMessage({
                clientUuid: item.clientUuid,
                body: item.body,
                author: options.currentUser(),
                mentions: [],
            }),
        );
    }

    const queuedUuids = computed(() =>
        outbox.items.value.map((item) => item.clientUuid),
    );

    /** Discard the whole offline queue: drop the optimistic rows and clear the outbox. */
    function discardQueue(): void {
        for (const item of outbox.items.value) {
            options.mainStream.removePending(item.clientUuid);
        }

        outbox.clear();
    }

    function flushOnReconnect(flushOutbox: () => Promise<number>): void {
        // Whenever the socket connects, flush any queued sends — including a queue
        // rehydrated on load, which connects for the first time rather than reconnecting.
        // Only on a genuine reconnect do we also backfill messages that landed while the
        // socket was down (the stream dedupes by client uuid, so re-fetching the latest
        // page adds no gaps or dupes) and confirm the flush with a toast.
        connection.onConnected(({ isReconnect }) => {
            const flushed = flushOutbox();

            if (!isReconnect) {
                return;
            }

            // A reconnect is the socket's timing, not the user's; see
            // {@see backgroundVisit}. Fired before the flush settles so the backfill
            // isn't held up by a queue that is slow — or failing — to drain.
            router.reload({ ...backgroundVisit, only: ['messages'] });

            // "You're caught up" is only true of the sends that actually landed, so it
            // waits for the flush and reports what it says landed. Anything still queued
            // has already raised its own failure card, under the same key this replaces.
            void flushed.then((sent) => {
                if (sent === 0) {
                    return;
                }

                toast.success(
                    sent === 1
                        ? t(
                              "Reconnected — 1 queued message sent, you're caught up.",
                          )
                        : t(
                              "Reconnected — :count queued messages sent, you're caught up.",
                              { count: sent },
                          ),
                    { key: QUEUED_SENDS_TOAST_KEY },
                );
            });
        });

        // If we mount already connected with a rehydrated queue — the socket was up
        // before this page (re)loaded, so no connect event will fire — flush it now.
        if (connection.isOnline.value && outbox.count.value > 0) {
            void flushOutbox();
        }
    }

    return { connection, outbox, queuedUuids, discardQueue, flushOnReconnect };
}

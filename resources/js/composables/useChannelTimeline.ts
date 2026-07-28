import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { useMessageStream } from '@/composables/useMessageStream';
import type { Message, MessagePage } from '@/types';

export interface ChannelTimelineOptions {
    /** The server's message page for the open channel. */
    messages: () => MessagePage;
}

export interface ChannelTimeline {
    /** The server page alone, oldest-first. */
    serverMessages: ComputedRef<Message[]>;
    /** The merge engine, which the realtime router and the actions engine drive. */
    mainStream: ReturnType<typeof useMessageStream>;
    /** What the timeline renders: the merged, deduped list. */
    displayMessages: ComputedRef<Message[]>;
    /** Client uuids of sends still in flight, so their rows can read as pending. */
    pendingUuids: ComputedRef<string[]>;
}

/**
 * The main channel timeline: optimistic sends + live echoes + edit/delete
 * patches, all merged over the server page and deduped by client uuid.
 *
 * `Inertia::scroll` delivers messages newest-first, so the page is reversed for
 * display before the stream merges over it.
 */
export function useChannelTimeline(
    options: ChannelTimelineOptions,
): ChannelTimeline {
    const serverMessages = computed<Message[]>(() =>
        [...(options.messages()?.data ?? [])].reverse(),
    );

    const mainStream = useMessageStream(serverMessages);

    return {
        serverMessages,
        mainStream,
        displayMessages: mainStream.displayMessages,
        pendingUuids: mainStream.pendingUuids,
    };
}

import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { ChannelReader } from '@/types';

export interface ChannelReadersOptions {
    /** The server's read positions for the open channel. */
    channelReaders: () => ChannelReader[];
}

export interface ChannelReaders {
    /** Keyed by user id, for the realtime router to advance in place. */
    readers: Ref<Map<string, ChannelReader>>;
    /** Reseed from the server prop, on open and on every channel switch. */
    seedReaders: () => void;
    /** The same positions as a list, as the timeline renders them. */
    channelReadersList: ComputedRef<ChannelReader[]>;
}

/**
 * Read positions of the channel's other members who share read receipts, keyed
 * by user id. Seeded from the server prop and kept current from the MessageRead
 * broadcast, driving the "Seen by" affordance under the newest message.
 */
export function useChannelReaders(
    options: ChannelReadersOptions,
): ChannelReaders {
    const readers = ref(new Map<string, ChannelReader>());

    function seedReaders(): void {
        readers.value = new Map(
            options.channelReaders().map((reader) => [reader.user.id, reader]),
        );
    }

    const channelReadersList = computed(() =>
        Array.from(readers.value.values()),
    );

    return { readers, seedReaders, channelReadersList };
}

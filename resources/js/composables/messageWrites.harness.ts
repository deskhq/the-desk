import { effectScope, ref } from 'vue';
import { useMessageStream } from '@/composables/useMessageStream';
import type { Message } from '@/types';

/**
 * Test-only: the two streams a message write patches, built directly rather
 * than through `useMessageActions`.
 *
 * Five of the eleven writes behind the message-action facade were only ever
 * exercised through that aggregate, which meant a dropped rollback was caught
 * by nothing — a stream restored in the timeline and not in the thread panel
 * beside it looks identical from the facade's outside (#1115). Driving each
 * composable directly is what makes that assertable, and every one of them
 * takes the same pair.
 *
 * The fixtures and mock accessors are shared with
 * {@see useMessageActions.harness}, so a message means the same thing on both
 * sides. Nothing the app ships imports this.
 */

type Stream = ReturnType<typeof useMessageStream>;

export interface MessageStreams {
    /** The channel timeline's stream. */
    mainStream: Stream;
    /** The open thread panel's stream, over the same rows where they overlap. */
    threadStream: Stream;
    /** Tear the scope down, as unmounting the channel page would. */
    unmount: () => void;
}

/**
 * A main and a thread stream, both seeded with `rows` — the case that matters,
 * since a message showing in both places has to roll back in both.
 */
export function streams(rows: Message[]): MessageStreams {
    const scope = effectScope();

    let mainStream!: Stream;
    let threadStream!: Stream;

    scope.run(() => {
        mainStream = useMessageStream(ref(rows));
        threadStream = useMessageStream(ref(rows));
    });

    return { mainStream, threadStream, unmount: () => scope.stop() };
}

/** The rendered row for `id`, as a stream currently shows it. */
export function shown(stream: Stream, id: string): Message {
    const row = stream.displayMessages.value.find(
        (message) => message.id === id,
    );

    if (!row) {
        throw new Error(`No message ${id} in the stream.`);
    }

    return row;
}

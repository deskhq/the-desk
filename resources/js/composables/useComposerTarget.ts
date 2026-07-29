import { ref } from 'vue';
import type { Ref } from 'vue';
import type { Message } from '@/types';

export interface ComposerTarget {
    /** The message the composer is currently quoting, or null for a normal send. */
    replyTarget: Ref<Message | null>;
    /**
     * The id of the message the main composer is editing in place (via the ↑
     * shortcut), or null. Highlights the target row in the timeline while editing.
     */
    composerEditingId: Ref<string | null>;
    startReply: (message: Message) => void;
    cancelReply: () => void;
}

/** What the channel composer is currently pointed at: a quote, or an edit. */
export function useComposerTarget(): ComposerTarget {
    const replyTarget = ref<Message | null>(null);
    const composerEditingId = ref<string | null>(null);

    function startReply(message: Message): void {
        replyTarget.value = message;
    }

    function cancelReply(): void {
        replyTarget.value = null;
    }

    return { replyTarget, composerEditingId, startReply, cancelReply };
}
